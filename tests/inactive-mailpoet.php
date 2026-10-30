<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

if (defined('MAILPOET_VERSION')) {
    throw new RuntimeException('This test requires inactive MailPoet.');
}
$users = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($users[0]->ID);
$_GET = ['new' => 1];
ob_start();
(new Starclock\Admin(Starclock\Plugin::engine(), dirname(__DIR__) . '/starclock.php'))->page();
$html = ob_get_clean();
$checks = [
    'Inactive MailPoet is reported' => str_contains($html, 'MailPoet ist nicht aktiv'),
    'No enable control without MailPoet' => !str_contains($html, 'Automatik aktivieren'),
    'No clock health section without MailPoet' => !str_contains($html, 'sc-health'),
];
if (in_array(false, $checks, true)) {
    throw new RuntimeException(json_encode($checks));
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
