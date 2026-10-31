<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$store = new Starclock\Store($GLOBALS['wpdb']);
$adapter = new Starclock\MailPoetAdapter();
$engine = new Starclock\Engine($store, ['mailpoet' => $adapter], WP_HOME, true);
$plan = $store->plan((int)getenv('STARCLOCK_RACE_PLAN'));
$now = new DateTimeImmutable('2026-12-21 18:30 Europe/Berlin');
if ($plan['status'] !== 'archived') {
    $plan = $engine->action($plan['id'], $plan['revision'], 'archive', $now);
}
$runs = $store->history($plan['id']);
$stopped = true;
foreach ($runs as $run) {
    $stopped = $stopped && !$engine->permits('mailpoet', $run['external_id'], $now)
        && $adapter->deliveryState($run['external_id']) === 'paused';
}
$engine->tick($now->modify('+1 week'));
$checks = [
    'Concurrent archive and ticks settle archived with at most one retained handoff' => $plan['status'] === 'archived' && count($runs) <= 1,
    'No pending or permitted owned campaign after successful archive' => !$store->pending($plan['id']) && $stopped,
    'Later ticks cannot change archived receipts' => $store->history($plan['id']) === $runs,
];
if (in_array(false, $checks, true)) {
    throw new RuntimeException(json_encode($checks));
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
