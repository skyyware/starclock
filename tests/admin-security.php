<?php
declare(strict_types=1);

if (!defined('DB_NAME') || !str_starts_with(DB_NAME, 'starclock_test_') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Synthetic runtime required.');
}
require_once __DIR__ . '/../src/load.php';
$checks = [];
$engine = new Starclock\Engine(new Starclock\Store($GLOBALS['wpdb']), ['mailpoet' => new Starclock\MailPoetAdapter()], WP_HOME, false);
$admin = new Starclock\Admin($engine, dirname(__DIR__) . '/starclock.php');
$users = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($users[0]->ID);
$checks['Administrator has required capabilities'] = Starclock\Admin::authorized();
$denyMailPoet = static function (array $capabilities): array {
    $capabilities['mailpoet_manage_emails'] = false;
    return $capabilities;
};
add_filter('user_has_cap', $denyMailPoet);
$checks['MailPoet permission is required separately'] = !Starclock\Admin::authorized();
remove_filter('user_has_cap', $denyMailPoet);
add_filter('wp_die_handler', static fn() => static function ($message, $title, $args): never {
    throw new RuntimeException((string)($args['response'] ?? 500));
});
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    $admin->submit();
    $checks['GET cannot mutate'] = false;
} catch (RuntimeException $error) {
    $checks['GET cannot mutate'] = $error->getMessage() === '403';
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST['_wpnonce'] = 'invalid';
$before = $engine->store->plans();
try {
    $admin->submit();
    $checks['Invalid nonce cannot mutate'] = false;
} catch (RuntimeException $error) {
    $checks['Invalid nonce cannot mutate'] = $error->getMessage() === '403' && $before === $engine->store->plans();
}
if (in_array(false, $checks, true)) {
    throw new RuntimeException(json_encode($checks));
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
