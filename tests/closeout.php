<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$engine = Starclock\Plugin::engine();
$engine->deactivate();
wp_clear_scheduled_hook(Starclock\Plugin::HOOK);
$plans = $engine->store->plans();
$pending = array_sum(array_map(fn(array $plan): int => count($engine->store->pending($plan['id'])), $plans));
$sent = (int)$GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'mailpoet_statistics_newsletters');
$checks = [
    'No active synthetic schedules' => !array_filter($plans, static fn(array $plan): bool => $plan['status'] === 'active'),
    'No pending owned handoffs' => $pending === 0,
    'No Starclock cron event' => wp_next_scheduled(Starclock\Plugin::HOOK) === false,
    'No newsletter send statistics' => $sent === 0,
];
if (in_array(false, $checks, true)) {
    throw new RuntimeException(json_encode($checks));
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
