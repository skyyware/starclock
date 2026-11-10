<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

use Starclock\{Adapter, Engine, MailPoetAdapter, Plugin, Store};
use MailPoet\DI\ContainerWrapper;
use MailPoet\Newsletter\NewslettersRepository;
use MailPoetVendor\Doctrine\ORM\EntityManager;

final class VersionDrift implements Adapter
{
    public bool $unverified = false;
    public function __construct(private MailPoetAdapter $inner) {}
    public function id(): string { return 'mailpoet'; }
    public function label(): string { return 'MailPoet'; }
    public function available(): void
    {
        if ($this->unverified) {
            throw new RuntimeException(MailPoetAdapter::compatibilityProblem('999.0.0'));
        }
        $this->inner->available();
    }
    public function choices(): array { $this->available(); return $this->inner->choices(); }
    public function inspect(int $id, bool $requireDraft = true): array { $this->available(); return $this->inner->inspect($id, $requireDraft); }
    public function duplicate(int $id): int { $this->available(); return $this->inner->duplicate($id); }
    public function enqueue(int $id): string { $this->available(); return $this->inner->enqueue($id); }
    public function pause(int $id): void { $this->available(); $this->inner->pause($id); }
    public function deliveryState(int $id): string { $this->available(); return $this->inner->deliveryState($id); }
}

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    if (!$ok) {
        throw new RuntimeException($name);
    }
};
$store = new Store($GLOBALS['wpdb']);
$native = new MailPoetAdapter();
$adapter = new VersionDrift($native);
$engine = new Engine($store, ['mailpoet' => $adapter], WP_HOME, true);
$source = $store->plans()[0];
$source['title'] = 'Synthetic dependency drift';
$source['template_id'] = $native->duplicate($source['template_id']);
$plan = $engine->save($source, 0, 0);
$id = $plan['id'];
$c = ContainerWrapper::getInstance();
$repo = $c->get(NewslettersRepository::class);
$em = $c->get(EntityManager::class);
$now = new DateTimeImmutable('2026-11-02 18:29 Europe/Berlin');
if (!has_action(Plugin::HOOK)) {
    Plugin::boot(dirname(__DIR__) . '/starclock.php');
}
try {
    $plan = $engine->action($id, $plan['revision'], 'review', $now);
    $plan = $engine->action($id, $plan['revision'], 'enable', $now);
    $now = $now->modify('+1 minute');
    $engine->tick($now);
    $first = $store->history($id)[0];
    $mail = $repo->findOneById($first['external_id']);
    $mail->getLatestQueue()->getTask()->setStatus('completed');
    $mail->getLatestQueue()->getTask()->setInProgress(false);
    $mail->setStatus('sent');
    $em->flush();
    $engine->tick($now->modify('+1 minute'));
    $check('The first native receipt is completed before dependency drift', $store->run($first['id'])['state'] === 'completed');
    $adapter->unverified = true;
    $engine->tick(new DateTimeImmutable('2026-11-03 12:00 Europe/Berlin'));
    $blocked = $store->plan($id);
    $check('Unverified dependency drift blocks the active plan with its version', $blocked['status'] === 'needs_review'
        && str_contains($blocked['notice'], '999.0.0'));
    Plugin::syncCron($engine);
    $check('A dependency-blocked plan keeps its diagnostic tick', wp_next_scheduled(Plugin::HOOK) !== false);
    $engine->tick(new DateTimeImmutable('2026-11-09 18:30 Europe/Berlin'));
    $check('The blocked second week has no handoff or reservation', count($store->history($id)) === 1);
    $adapter->unverified = false;
    $now = new DateTimeImmutable('2026-11-10 12:00 Europe/Berlin');
    $engine->tick($now);
    $check('A compatibility fix alone never reactivates the plan', $store->plan($id) === $blocked && count($store->history($id)) === 1);
    $master = $repo->findOneById($source['template_id']);
    $master->setStatus('sent');
    $master->setSubject('Synthetic manually sent reminder');
    $em->flush();
    $sentFingerprint = MailPoetAdapter::fingerprint($master);
    $refused = false;
    try {
        $engine->action($id, $blocked['revision'], 'review', $now);
    } catch (RuntimeException $error) {
        $refused = str_contains($error->getMessage(), 'unveröffentlichten');
    }
    $check('A manually sent master is refused as a future template', $refused && $store->plan($id)['status'] === 'paused');
    $replacement = $native->duplicate($source['template_id']);
    $source['template_id'] = $replacement;
    $plan = $engine->save($source, $id, $store->plan($id)['revision']);
    $plan = $engine->action($id, $plan['revision'], 'review', $now);
    $plan = $engine->action($id, $plan['revision'], 'enable', $now);
    $check('Explicit recovery starts at the next Monday, not the missed one', $engine->next($plan, $now)?->format('Y-m-d') === '2026-11-16');
    $engine->tick($now);
    $check('Recovery does not catch up the missed occurrence', count($store->history($id)) === 1);
    $check('The completed first campaign stays blocked after recovery', !$engine->permits('mailpoet', $first['external_id'], $now));
    $now = new DateTimeImmutable('2026-11-16 18:30 Europe/Berlin');
    $engine->tick($now);
    $next = $store->history($id)[0];
    $engine->tick($now);
    $check('The next regular date has exactly one fresh receipt', count($store->history($id)) === 2
        && $next['occurrence'] === '2026-11-16' && $next['state'] === 'queued' && $next['receipt'] !== $first['receipt']);
    $repo->refresh($master);
    $check('Recovery leaves the manually sent master untouched', $master->getStatus() === 'sent'
        && MailPoetAdapter::fingerprint($master) === $sentFingerprint);
    $check('A later tick cannot revive the completed first campaign', !$engine->permits('mailpoet', $first['external_id'], $now)
        && $store->run($first['id'])['state'] === 'completed');
} finally {
    $adapter->unverified = false;
    $plan = $store->plan($id);
    $engine->action($id, $plan['revision'], 'pause', $now);
    Plugin::syncCron($engine);
}
$check('Recovery test ends paused without pending work', $store->plan($id)['status'] === 'paused'
    && !$store->pending($id) && !wp_next_scheduled(Plugin::HOOK));
$check('Recovery test never reaches transport', (int)$GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '
    . $GLOBALS['wpdb']->prefix . 'mailpoet_statistics_newsletters') === 0);
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
