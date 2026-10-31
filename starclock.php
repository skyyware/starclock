<?php
/**
 * Plugin Name: Starclock
 * Description: Wiederkehrende WordPress-Zeitpläne mit geprüfter MailPoet-Anbindung.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: SKYYWARE
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: starclock
 * Update URI: https://github.com/skyyware/starclock
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/load.php';

register_activation_hook(__FILE__, [\Starclock\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\Starclock\Plugin::class, 'deactivate']);
add_action('plugins_loaded', static function (): void {
    \Starclock\Plugin::boot(__FILE__);
});
