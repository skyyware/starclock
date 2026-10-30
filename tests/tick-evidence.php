<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$engine = Starclock\Plugin::engine();
foreach ($engine->store->plans() as $plan) {
    if ($plan['status'] !== 'paused' || $engine->store->pending($plan['id'])) {
        throw new RuntimeException('All synthetic schedules must be paused before the evidence test.');
    }
}
if (!has_action(Starclock\Plugin::HOOK)) {
    Starclock\Plugin::boot(dirname(__DIR__) . '/starclock.php');
}
$before = time();
do_action(Starclock\Plugin::HOOK);
$checks = [
    'Successful hook retains the internal tick time' => (int)get_option('starclock_last_tick', 0) >= $before,
    'Successful hook clears the internal error' => get_option('starclock_tick_error') === false,
    'Paused schedules do not register a cron event' => wp_next_scheduled(Starclock\Plugin::HOOK) === false,
];
if (in_array(false, $checks, true)) {
    throw new RuntimeException(json_encode($checks));
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
