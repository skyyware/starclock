<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
if (!has_filter('pre_wp_mail') || !has_filter('pre_http_request')
    || apply_filters('action_scheduler_allow_async_request_runner', true) !== false) {
    throw new RuntimeException('The synthetic WordPress mail, HTTP and asynchronous runner guards are required.');
}
if (is_plugin_active('starclock/starclock.php')) {
    throw new RuntimeException('Keep Starclock inactive during controlled-time integration tests.');
}
echo json_encode(['wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
    'mailpoet' => defined('MAILPOET_VERSION') ? MAILPOET_VERSION : null]) . "\n";
