<?php
declare(strict_types=1);

if (!defined('DB_NAME') || !str_starts_with(DB_NAME, 'starclock_test_') || WP_HOME !== 'http://starclock-test.invalid'
    || wp_get_environment_type() !== 'local' || function_exists('mail')) {
    throw new RuntimeException('Isolated synthetic runtime required.');
}
$socket = @fsockopen('tcp://1.1.1.1', 443, $errno, $message, 1);
if ($socket || $errno !== 1) {
    throw new RuntimeException('OS transport block required.');
}
require_once __DIR__ . '/../src/load.php';

final class LostReceipt implements Starclock\Adapter
{
    private Starclock\MailPoetAdapter $inner;
    public function __construct() { $this->inner = new Starclock\MailPoetAdapter(); }
    public function id(): string { return 'mailpoet'; }
    public function label(): string { return 'MailPoet'; }
    public function available(): void { $this->inner->available(); }
    public function choices(): array { return $this->inner->choices(); }
    public function inspect(int $id, bool $requireDraft = true): array { return $this->inner->inspect($id, $requireDraft); }
    public function duplicate(int $id): int { return $this->inner->duplicate($id); }
    public function pause(int $id): void { $this->inner->pause($id); }
    public function deliveryState(int $id): string { return $this->inner->deliveryState($id); }
    public function enqueue(int $id): string
    {
        $this->inner->enqueue($id);
        throw new RuntimeException('Injected loss of acknowledgement after native queue creation.');
    }
}

$store = new Starclock\Store($GLOBALS['wpdb']);
$adapter = new LostReceipt();
$engine = new Starclock\Engine($store, ['mailpoet' => $adapter], WP_HOME, true);
$source = $store->plans()[0];
$source['title'] = 'Ambiguous handoff test';
$plan = $engine->save($source, 0, 0);
$id = $plan['id'];
$now = new DateTimeImmutable('2026-12-14 18:29 Europe/Berlin');
$plan = $engine->action($id, $plan['revision'], 'review', $now);
$plan = $engine->action($id, $plan['revision'], 'enable', $now);
$now = $now->modify('+1 minute');
$engine->tick($now);
$run = $store->history($id)[0];
$checks = [
    'Unclear handoff requires review' => $run['state'] === 'needs_review' && $store->plan($id)['status'] === 'needs_review',
    'Created queue is paused after lost receipt' => $adapter->deliveryState($run['external_id']) === 'paused',
    'Final guard rejects ambiguous copy' => !$engine->permits('mailpoet', $run['external_id'], $now),
];
$engine->tick($now);
$checks['Retry does not create a second copy'] = count($store->history($id)) === 1;
$plan = $store->plan($id);
$plan = $engine->action($id, $plan['revision'], 'resolve', $now);
$checks['Explicit closure preserves receipt and reservation'] = $store->run($run['id'])['state'] === 'closed' && $store->run($run['id'])['external_id'] === $run['external_id'];
$engine->deactivate();
if (in_array(false, $checks, true)) {
    throw new RuntimeException(json_encode($checks));
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
