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
