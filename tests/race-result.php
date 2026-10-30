<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$store = new Starclock\Store($GLOBALS['wpdb']);
$runs = $store->history((int)getenv('STARCLOCK_RACE_PLAN'));
$queued = array_filter($runs, static fn(array $run): bool => $run['state'] === 'queued' && !empty($run['receipt']));
if (count($queued) !== 1) {
    throw new RuntimeException('Concurrent ticks must create exactly one handoff receipt.');
}
echo json_encode(['checks' => ['Concurrent ticks produce one native handoff' => true], 'passed' => 1]) . "\n";
