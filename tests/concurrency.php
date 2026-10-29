<?php
declare(strict_types=1);

if (!defined('DB_NAME') || !str_starts_with(DB_NAME, 'starclock_test_') || wp_get_environment_type() !== 'local'
    || WP_HOME !== 'http://starclock-test.invalid' || !preg_match('/^[0-9]+$/D', (string)getenv('STARCLOCK_RACE_PLAN'))) {
    throw new RuntimeException('Synthetic test database and explicit test plan required.');
}
require_once __DIR__ . '/../src/load.php';
$store = new Starclock\Store($GLOBALS['wpdb']);
$result = $store->claim((int)getenv('STARCLOCK_RACE_PLAN'), '2026-12-28', ['adapter' => 'mailpoet', 'created_at' => time()], 'skipped');
echo $result ? "winner\n" : "duplicate\n";
