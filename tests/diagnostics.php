<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

use Starclock\{Admin, Diagnostics, Engine, MailPoetAdapter, Plugin, Store};

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    if (!$ok) {
        throw new RuntimeException($name);
    }
};
$store = new Store($GLOBALS['wpdb']);
$store->install();
$engine = new Engine($store, ['mailpoet' => new MailPoetAdapter()], untrailingslashit(home_url()), true);
foreach ($store->plans() as $existing) {
    if (!in_array($existing['status'], ['paused', 'archived', 'deleted'], true) || $store->pending($existing['id'])) {
        throw new RuntimeException('Existing synthetic plans must be stopped before the diagnostics test.');
    }
}
Plugin::boot(dirname(__DIR__) . '/starclock.php');
$before = ['user' => get_current_user_id(), 'updates' => get_site_transient('update_plugins'),
    'tick' => get_option('starclock_last_tick'), 'error' => get_option('starclock_tick_error')];
$plan = $engine->save(['title' => 'Synthetic <script>alert(1)</script>', 'adapter' => 'mailpoet', 'template_id' => 2147483000,
    'weekday' => 1, 'time' => '18:30', 'timezone' => 'Europe/Berlin', 'start_date' => '2026-01-01', 'window_minutes' => 60], 0, 0);
try {
    $plan['status'] = 'needs_review';
    $plan['notice'] = 'Synthetic unsupported MailPoet <b>version</b>';
    $plan = $store->save($plan, $plan['revision']);
    Plugin::syncCron($engine);
    $check('Blocked plans retain the existing diagnostic tick', wp_next_scheduled(Plugin::HOOK) !== false);
    $event = wp_next_scheduled(Plugin::HOOK);
    Plugin::syncCron($engine);
    $check('Repeated cron synchronization creates no extra event', wp_next_scheduled(Plugin::HOOK) === $event);
    wp_clear_scheduled_hook(Plugin::HOOK);
    Plugin::ensureCron();
    $check('Cron recovery is registered for ordinary initialization', has_action('init', [Plugin::class, 'ensureCron']) !== false);
    $check('Initialization repairs the missing legacy blocked-plan tick', wp_next_scheduled(Plugin::HOOK) !== false);
    do_action(Plugin::HOOK);
    $check('Blocked tick retains a persistent explanation', str_contains((string)get_option('starclock_tick_error'), 'Synthetic unsupported'));
    $check('Blocked tick never reactivates or creates a receipt', $store->plan($plan['id']) === $plan && $store->history($plan['id']) === []);
    $diagnostics = new Diagnostics($engine);
    $check('Website health reports the blocked plan as critical', $diagnostics->siteHealth()['status'] === 'critical');
    $registered = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
    $check('The native website health test is registered', is_callable($registered['direct']['starclock']['test'] ?? null));
    wp_set_current_user(0);
    ob_start();
    $diagnostics->notice();
    $check('Anonymous visitors cannot read the diagnostic notice', ob_get_clean() === '');
    $admin = get_users(['role' => 'administrator', 'number' => 1]);
    wp_set_current_user($admin[0]->ID);
    ob_start();
    $diagnostics->notice();
    $html = ob_get_clean();
    $check('Administrators see a persistent actionable explanation', str_contains($html, 'Synthetic unsupported') && str_contains($html, 'page=starclock'));
    $check('Diagnostics escape untrusted plan and error text', !str_contains($html, '<b>version</b>') && str_contains($html, '&lt;b&gt;version&lt;/b&gt;'));
    $check('Unknown MailPoet versions are still rejected', MailPoetAdapter::compatibilityProblem('999.0.0') !== null);
    $check('The installed matrix version is approved', MailPoetAdapter::compatibilityProblem(MAILPOET_VERSION) === null);
    foreach (['', '5.45', '5.45.1-beta', '5.45.2', '6.0.0'] as $version) {
        $check('Unverified version is not broadly approved: ' . $version, MailPoetAdapter::compatibilityProblem($version) !== null);
    }
    $plan['status'] = 'active';
    $plan = $store->save($plan, $plan['revision']);
    delete_option('starclock_tick_error');
    set_site_transient('update_plugins', (object)['response' => ['mailpoet/mailpoet.php' => (object)['new_version' => '999.0.0']]]);
    $check('An offered untested update has a visible preflight warning', $diagnostics->siteHealth()['status'] === 'recommended'
        && str_contains($diagnostics->siteHealth()['description'], '999.0.0'));
    $check('The warning does not disable security updates', apply_filters('auto_update_plugin', true,
        (object)['plugin' => 'mailpoet/mailpoet.php', 'new_version' => '999.0.0']) === true);
    wp_clear_scheduled_hook(Plugin::HOOK);
    $check('A missing active-plan tick is not healthy', $diagnostics->siteHealth()['status'] === 'critical');
    $plan['status'] = 'paused';
    $plan = $store->save($plan, $plan['revision']);
    do_action(Plugin::HOOK);
    $check('Paused plans clear diagnostics and stop the tick', get_option('starclock_tick_error') === false
        && wp_next_scheduled(Plugin::HOOK) === false && $diagnostics->siteHealth()['status'] === 'good');
} finally {
    $current = $store->plan($plan['id']);
    if (!in_array($current['status'], ['paused', 'archived', 'deleted'], true)) {
        $engine->action($current['id'], $current['revision'], 'pause', Plugin::now());
    }
    Plugin::syncCron($engine);
    wp_set_current_user($before['user']);
    if ($before['updates'] === false) {
        delete_site_transient('update_plugins');
    } else {
        set_site_transient('update_plugins', $before['updates']);
    }
    foreach (['tick' => 'starclock_last_tick', 'error' => 'starclock_tick_error'] as $key => $option) {
        $before[$key] === false ? delete_option($option) : update_option($option, $before[$key]);
    }
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
