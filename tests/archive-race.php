<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$store = new Starclock\Store($GLOBALS['wpdb']);
$engine = new Starclock\Engine($store, ['mailpoet' => new Starclock\MailPoetAdapter()], WP_HOME, true);
$plan = $store->plan((int)getenv('STARCLOCK_RACE_PLAN'));
try {
    $engine->action($plan['id'], $plan['revision'], 'archive', new DateTimeImmutable('2026-12-21 18:30 Europe/Berlin'));
    echo 'archived';
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'gerade verarbeitet') && !str_contains($error->getMessage(), 'zwischenzeitlich geändert')
        && $store->plan($plan['id'])['status'] !== 'archived') {
        throw $error;
    }
    echo 'busy-or-archived';
}
