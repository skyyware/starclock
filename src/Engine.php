<?php
declare(strict_types=1);

namespace Starclock;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class Engine
{
    public function __construct(public readonly Store $store, private array $adapters, private string $site, private bool $canActivate = true)
    {
    }

    public function adapter(string $key): Adapter
    {
        $adapter = $this->adapters[$key] ?? null;
        if (!$adapter instanceof Adapter) {
            throw new RuntimeException('Die Erweiterung für diesen Zeitplan ist nicht verfügbar.');
        }
        return $adapter;
    }

    public function adapters(): array
    {
        return $this->adapters;
    }

    public function save(array $input, int $id, int $revision): array
    {
        return $this->store->locked('plans', function () use ($input, $id, $revision): array {
            $schedule = Schedule::validate($input);
            $title = sanitize_text_field($input['title'] ?? '');
            $template = filter_var($input['template_id'] ?? null, FILTER_VALIDATE_INT);
            $key = sanitize_key($input['adapter'] ?? '');
            $this->adapter($key);
            if (!$title || strlen($title) > 160 || !$template || $template < 1) {
                throw new RuntimeException('Bitte einen Namen und eine Vorlage wählen.');
            }
            if ($id) {
                $old = $this->store->plan($id);
                $this->assertRevision($old, $revision);
                $this->stopPending($old);
            }
            return $this->store->save($schedule + ['id' => $id, 'title' => $title, 'adapter' => $key,
                'template_id' => $template, 'status' => 'paused', 'approved' => null, 'site' => $this->site,
                'not_before' => 0, 'notice' => 'Gespeichert. Bitte die Vorlage prüfen.'], $revision);
        });
    }

    private function assertRevision(array $plan, int $revision): void
    {
        if ($plan['revision'] !== $revision) {
            throw new RuntimeException('Der Zeitplan wurde zwischenzeitlich geändert. Bitte neu laden.');
        }
    }

    public function action(int $id, int $revision, string $action, DateTimeImmutable $now): array
    {
        return $this->store->locked('plans', function () use ($id, $revision, $action, $now): array {
            $plan = $this->store->plan($id);
            $this->assertRevision($plan, $revision);
            if ($action === 'resolve') {
                $plan['status'] = 'paused';
                $plan['approved'] = null;
                $plan['notice'] = 'Unterbrochene Läufe geschlossen. Diese Termine werden nicht erneut übergeben.';
                $plan = $this->store->save($plan, $plan['revision']);
                $this->stopPending($plan);
                foreach ($this->store->pending($id) as $run) {
                    $run['state'] = 'closed';
                    $this->store->writeRun($run);
                }
                return $plan;
            }
            if ($action === 'pause' || $action === 'review') {
                $plan['status'] = 'paused';
                $plan['approved'] = null;
                $plan['notice'] = 'Pausiert. Bereits versandte E-Mails bleiben versandt.';
                $plan = $this->store->save($plan, $plan['revision']);
                $this->stopPending($plan);
                if ($action === 'review') {
                    $plan['approved'] = $this->adapter($plan['adapter'])->inspect($plan['template_id']);
                    $plan['status'] = 'ready';
                    $plan['notice'] = 'Vorlage geprüft. Der Versand ist noch nicht aktiviert.';
                    $plan = $this->store->save($plan, $plan['revision']);
                }
                return $plan;
            }
            if ($action === 'enable') {
                if (!$this->canActivate || $plan['site'] !== $this->site || $plan['status'] !== 'ready') {
                    throw new RuntimeException('Aktivierung gesperrt. Umgebung und geprüften Zeitplan kontrollieren.');
                }
                $this->validateApproved($plan);
                foreach ($this->store->plans() as $other) {
                    if ($other['id'] !== $id && $other['status'] === 'active' && $other['adapter'] === $plan['adapter'] && $other['template_id'] === $plan['template_id']) {
                        throw new RuntimeException('Diese Vorlage wird bereits durch einen aktiven Zeitplan verwendet.');
                    }
                }
                $plan['status'] = 'active';
                $plan['not_before'] = $now->getTimestamp() + 1;
                $plan['notice'] = 'Aktiv. Start zum nächsten Termin; keine Nachholung vergangener Termine.';
            } elseif ($action === 'skip') {
                if ($plan['status'] !== 'active') {
                    throw new RuntimeException('Nur aktive Zeitpläne haben einen auslassbaren nächsten Lauf.');
                }
                $next = $this->next($plan, $now);
                if (!$next || !$this->store->claim($id, $next->format('Y-m-d'), ['adapter' => $plan['adapter'], 'created_at' => $now->getTimestamp()], 'skipped')) {
                    throw new RuntimeException('Der nächste Lauf ist bereits reserviert. Bitte den Status neu laden.');
                }
                $plan['notice'] = 'Der nächste Lauf wird ausgelassen.';
            } else {
                throw new RuntimeException('Unbekannte Aktion.');
            }
            return $this->store->save($plan, $plan['revision']);
        });
    }

    private function validateApproved(array $plan): void
    {
        $current = $this->adapter($plan['adapter'])->inspect($plan['template_id']);
        if (empty($plan['approved']['fingerprint']) || !hash_equals($plan['approved']['fingerprint'], $current['fingerprint'])) {
            throw new RuntimeException('Vorlage, Empfänger oder Absender wurden geändert. Bitte den Plan erneut prüfen und aktivieren.');
        }
    }

    private function executionKey(array $plan): string
    {
        return hash('sha256', json_encode([Schedule::validate($plan), $plan['adapter'], $plan['template_id'],
            $plan['approved']['fingerprint'], $plan['not_before'], $plan['site']], JSON_THROW_ON_ERROR));
    }

    public function next(array $plan, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $skip = array_column($this->store->history($plan['id'], 100), 'occurrence');
        for ($i = 0; $i < 52; $i++) {
            $next = Schedule::next($plan, $now);
            if (!$next || !in_array($next->format('Y-m-d'), $skip, true)) {
                return $next;
            }
            $now = $next;
        }
        return null;
    }

    public function tick(DateTimeImmutable $now): array
    {
        return $this->store->locked('plans', function () use ($now): array {
            $outcomes = [];
            foreach ($this->store->plans() as $plan) {
                if ($plan['status'] !== 'active') {
                    continue;
                }
                try {
                    if ($plan['site'] !== $this->site || !$this->canActivate) {
                        throw new RuntimeException('Die Website oder Umgebung wurde geändert. Den Zeitplan am Ziel erneut prüfen.');
                    }
                    $this->validateApproved($plan);
                    $this->reconcile($plan, $now);
                    $due = Schedule::due($plan, $now);
                    if (!$due) {
                        continue;
                    }
                    $run = $this->store->claim($plan['id'], $due->format('Y-m-d'), [
                        'adapter' => $plan['adapter'], 'created_at' => $now->getTimestamp(), 'revision' => $plan['revision'],
                        'execution_key' => $this->executionKey($plan),
                        'fingerprint' => $plan['approved']['fingerprint'], 'expires_at' => Schedule::expires($plan, $due)->getTimestamp(),
                    ]);
                    if (!$run) {
                        continue;
                    }
                    $outcomes[] = $this->handoff($plan, $run);
                } catch (Throwable $error) {
                    $plan = $this->store->plan($plan['id']);
                    $plan['status'] = 'needs_review';
                    $plan['notice'] = $error->getMessage();
                    $this->store->save($plan, $plan['revision']);
                    try {
                        $this->stopPending($plan);
                    } catch (Throwable $stopError) {
                        $outcomes[] = ['state' => 'needs_review', 'plan_id' => $plan['id'], 'message' => $stopError->getMessage()];
                    }
                    $outcomes[] = ['state' => 'needs_review', 'plan_id' => $plan['id'], 'message' => $error->getMessage()];
                }
            }
            return $outcomes;
        });
    }

    private function handoff(array $plan, array $run): array
    {
        try {
            $adapter = $this->adapter($plan['adapter']);
            $run['external_id'] = $adapter->duplicate($plan['template_id']);
            $run['state'] = 'handing_over';
            $run = $this->store->writeRun($run);
            $this->validateApproved($plan);
            if (!hash_equals($run['fingerprint'], $adapter->inspect($run['external_id'])['fingerprint'])) {
                throw new RuntimeException('Die Wochenkopie stimmt nicht mit der geprüften Vorlage überein.');
            }
            $run['receipt'] = $adapter->enqueue($run['external_id']);
            $run['state'] = 'queued';
            return $this->store->writeRun($run);
        } catch (Throwable $error) {
            $run['state'] = 'needs_review';
            $run['message'] = $error->getMessage();
            $this->store->writeRun($run);
            throw $error;
        }
    }

    public function permits(string $adapter, int $externalId, DateTimeImmutable $now): bool
    {
        $run = $this->store->forExternal($adapter, $externalId);
        if (!$run) {
            return true;
        }
        $plan = $this->store->plan($run['plan_id']);
        if (!$this->canActivate || $plan['site'] !== $this->site || $plan['status'] !== 'active'
            || !in_array($run['state'], ['handing_over', 'queued'], true)
            || $now->getTimestamp() >= ($run['expires_at'] ?? 0)
            || !hash_equals($run['execution_key'], $this->executionKey($plan))) {
            return false;
        }
        $this->validateApproved($plan);
        return hash_equals($run['fingerprint'], $this->adapter($adapter)->inspect($externalId, false)['fingerprint']);
    }

    private function reconcile(array $plan, DateTimeImmutable $now): void
    {
        foreach ($this->store->pending($plan['id']) as $run) {
            if (in_array($run['state'], ['claimed', 'handing_over', 'needs_review'], true)) {
                throw new RuntimeException('Ein vorheriger Lauf wurde unterbrochen. Vor einer neuen Übergabe den MailPoet-Status prüfen.');
            }
            if ($run['external_id']) {
                $state = $this->adapter($plan['adapter'])->deliveryState($run['external_id']);
                if (in_array($state, ['completed', 'paused', 'cancelled'], true)) {
                    $run['state'] = $state;
                    $this->store->writeRun($run);
                } elseif ($now->getTimestamp() >= $run['expires_at']) {
                    $this->adapter($plan['adapter'])->pause($run['external_id']);
                    $run['state'] = 'expired';
                    $this->store->writeRun($run);
                }
            }
        }
    }

    public function stopPending(array $plan): void
    {
        foreach ($this->store->pending($plan['id']) as $run) {
            if ($run['external_id']) {
                $this->adapter($plan['adapter'])->pause($run['external_id']);
            }
            if (!in_array($run['state'], ['claimed', 'handing_over', 'needs_review'], true)) {
                $run['state'] = 'paused';
                $this->store->writeRun($run);
            }
        }
    }

    public function deactivate(): void
    {
        $this->store->locked('plans', function (): void {
            foreach ($this->store->plans() as $plan) {
                $plan['status'] = 'paused';
                $plan['approved'] = null;
                $plan['notice'] = 'Starclock wurde deaktiviert. Vor dem nächsten Start erneut prüfen.';
                $plan = $this->store->save($plan, $plan['revision']);
                $this->stopPending($plan);
            }
        });
    }
}
