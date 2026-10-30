<?php
declare(strict_types=1);

function starclock_require_isolation(): void
{
    if (!defined('DB_NAME') || !str_starts_with(DB_NAME, 'starclock_test_')
        || !defined('WP_HOME') || WP_HOME !== 'http://starclock-test.invalid'
        || wp_get_environment_type() !== 'local'
        || !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON !== true || function_exists('mail')) {
        throw new RuntimeException('Isolated synthetic WordPress and blocked PHP mail required.');
    }
    if (PHP_OS_FAMILY === 'Linux') {
        if (!function_exists('socket_create')) {
            throw new RuntimeException('The sockets extension is required to verify Linux isolation.');
        }
        foreach ([AF_INET, AF_INET6] as $family) {
            socket_clear_error();
            $socket = @socket_create($family, SOCK_STREAM, SOL_TCP);
            if ($socket !== false) {
                socket_close($socket);
                throw new RuntimeException('Internet sockets must be denied by the operating system.');
            }
            if (!in_array(socket_last_error(), [1, 97], true)) {
                throw new RuntimeException('Expected an operating-system address-family denial.');
            }
        }
        return;
    }
    $socket = @fsockopen('tcp://1.1.1.1', 443, $errno, $message, 1);
    if ($socket) {
        fclose($socket);
    }
    if ($socket || $errno !== 1) {
        throw new RuntimeException('OS network denial is required.');
    }
}
