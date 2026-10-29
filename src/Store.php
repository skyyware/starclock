<?php
declare(strict_types=1);

namespace Starclock;

use RuntimeException;

final class Store
{
    public readonly string $plans;
    public readonly string $runs;

    public function __construct(private \wpdb $db)
    {
        $this->plans = $db->prefix . 'starclock_plans';
        $this->runs = $db->prefix . 'starclock_runs';
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->plans} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            status varchar(24) NOT NULL DEFAULT 'paused',
            payload longtext NOT NULL,
            PRIMARY KEY  (id)
        ) $collation;");
        dbDelta("CREATE TABLE {$this->runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) unsigned NOT NULL,
            adapter varchar(40) NOT NULL,
            occurrence varchar(10) NOT NULL,
            state varchar(24) NOT NULL,
            external_id bigint(20) unsigned DEFAULT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY occurrence (plan_id,occurrence),
            UNIQUE KEY external_id (adapter,external_id)
        ) $collation;");
        if ($this->db->last_error) {
            throw new RuntimeException('Die Starclock-Tabellen konnten nicht eingerichtet werden.');
        }
        update_option('starclock_schema', 1, false);
    }

    private function queryRows(string $sql): array
    {
        $rows = $this->db->get_results($sql, ARRAY_A);
        if ($this->db->last_error || !is_array($rows)) {
            throw new RuntimeException('Starclock kann seine Daten nicht lesen.');
        }
        return array_map(static function (array $row): array {
            $data = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['payload']);
            foreach (['id', 'revision', 'plan_id', 'external_id'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = (int)$row[$key];
                }
            }
            return $row + $data;
        }, $rows);
    }

    public function plans(): array
    {
        return $this->queryRows("SELECT * FROM {$this->plans} ORDER BY id DESC");
    }

    public function plan(int $id): array
    {
        $rows = $this->queryRows($this->db->prepare("SELECT * FROM {$this->plans} WHERE id=%d", $id));
        if (!$rows) {
            throw new RuntimeException('Dieser Zeitplan wurde nicht gefunden.');
        }
        return $rows[0];
    }

    public function save(array $plan, int $expectedRevision): array
    {
        $id = (int)($plan['id'] ?? 0);
        $status = $plan['status'];
        unset($plan['id'], $plan['revision'], $plan['status']);
        $json = wp_json_encode($plan, JSON_THROW_ON_ERROR);
        if ($id) {
            $result = $this->db->query($this->db->prepare(
                "UPDATE {$this->plans} SET payload=%s,status=%s,revision=revision+1 WHERE id=%d AND revision=%d",
                $json, $status, $id, $expectedRevision
            ));
            if ($result !== 1) {
                throw new RuntimeException('Der Zeitplan wurde zwischenzeitlich geändert. Bitte neu laden.');
            }
        } else {
            if ($expectedRevision !== 0 || $this->db->insert($this->plans, ['payload' => $json, 'status' => $status, 'revision' => 1]) !== 1) {
                throw new RuntimeException('Der Zeitplan konnte nicht gespeichert werden.');
            }
            $id = (int)$this->db->insert_id;
        }
        return $this->plan($id);
    }

    public function locked(string $key, callable $callback): mixed
    {
        $name = 'starclock_' . substr(hash('sha256', $this->plans . ':' . $key), 0, 40);
        if ((int)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== 1) {
            throw new RuntimeException('Dieser Zeitplan wird gerade verarbeitet. Bitte gleich erneut versuchen.');
        }
        try {
            return $callback();
        } finally {
            $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public function claim(int $planId, string $date, array $payload, string $state = 'claimed'): ?array
    {
        $result = $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$this->runs} (plan_id,adapter,occurrence,state,payload) VALUES (%d,%s,%s,%s,%s)",
            $planId, $payload['adapter'], $date, $state, wp_json_encode($payload, JSON_THROW_ON_ERROR)
        ));
        if ($result === false) {
            throw new RuntimeException('Der Lauf konnte nicht reserviert werden.');
        }
        return $result === 1 ? $this->run((int)$this->db->insert_id) : null;
    }

    public function run(int $id): array
    {
        $rows = $this->queryRows($this->db->prepare("SELECT * FROM {$this->runs} WHERE id=%d", $id));
        if (!$rows) {
            throw new RuntimeException('Der Lauf wurde nicht gefunden.');
        }
        return $rows[0];
    }

    public function forExternal(string $adapter, int $id): ?array
    {
        return $this->queryRows($this->db->prepare("SELECT * FROM {$this->runs} WHERE adapter=%s AND external_id=%d", $adapter, $id))[0] ?? null;
    }

    public function history(int $planId, int $limit = 8): array
    {
        return $this->queryRows($this->db->prepare("SELECT * FROM {$this->runs} WHERE plan_id=%d ORDER BY id DESC LIMIT %d", $planId, $limit));
    }

    public function pending(int $planId): array
    {
        return $this->queryRows($this->db->prepare("SELECT * FROM {$this->runs} WHERE plan_id=%d AND state IN ('claimed','handing_over','queued','needs_review')", $planId));
    }

    public function writeRun(array $run): array
    {
        $id = $run['id'];
        $state = $run['state'];
        $external = $run['external_id'] ?? null;
        unset($run['id'], $run['state'], $run['plan_id'], $run['occurrence'], $run['external_id']);
        if ($this->db->update($this->runs, ['state' => $state, 'external_id' => $external, 'payload' => wp_json_encode($run, JSON_THROW_ON_ERROR)], ['id' => $id]) === false) {
            throw new RuntimeException('Der Laufstatus konnte nicht gespeichert werden.');
        }
        return $this->run($id);
    }
}
