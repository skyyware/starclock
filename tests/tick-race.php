<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';
$store = new Starclock\Store($GLOBALS['wpdb']);
$engine = new Starclock\Engine($store, ['mailpoet' => new Starclock\MailPoetAdapter()], WP_HOME, true);
try {
    echo json_encode($engine->tick(new DateTimeImmutable('2026-12-21 18:30 Europe/Berlin'))) . "\n";
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'gerade verarbeitet')) {
        throw $error;
    }
    echo "busy\n";
}
