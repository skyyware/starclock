<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
if (!preg_match('/^[0-9]+$/D', (string)getenv('STARCLOCK_RACE_PLAN'))) {
    throw new RuntimeException('Explicit synthetic test plan required.');
}
require_once __DIR__ . '/../src/load.php';
$store = new Starclock\Store($GLOBALS['wpdb']);
$result = $store->claim((int)getenv('STARCLOCK_RACE_PLAN'), '2026-12-28', ['adapter' => 'mailpoet', 'created_at' => time()], 'skipped');
echo $result ? "winner\n" : "duplicate\n";
