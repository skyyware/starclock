<?php
declare(strict_types=1);

namespace Starclock;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class Plugin
{
    public const HOOK = 'starclock_tick';

    public static function engine(): Engine
    {
        $adapters = apply_filters('starclock_adapters', ['mailpoet' => new MailPoetAdapter()]);
        $valid = [];
        foreach ($adapters as $key => $adapter) {
            if ($adapter instanceof Adapter && $key === $adapter->id() && preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $key)) {
                $valid[$key] = $adapter;
            }
        }
        return new Engine(new Store($GLOBALS['wpdb']), $valid, untrailingslashit(home_url()),
            wp_get_environment_type() === 'production' || (defined('STARCLOCK_ALLOW_NONPRODUCTION') && STARCLOCK_ALLOW_NONPRODUCTION));
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(esc_html__('Starclock bitte einzeln pro Website aktivieren.', 'starclock'));
        }
        (new Store($GLOBALS['wpdb']))->install();
    }

    public static function deactivate(): void
    {
        try {
            self::engine()->deactivate();
            wp_clear_scheduled_hook(self::HOOK);
        } catch (Throwable $error) {
            wp_die(esc_html($error->getMessage()), esc_html__('Offene MailPoet-Läufe prüfen', 'starclock'), ['back_link' => true]);
        }
    }

    public static function syncCron(Engine $engine): void
    {
        $active = array_filter($engine->store->plans(), static fn(array $plan): bool => $plan['status'] === 'active');
        if (!$active) {
            wp_clear_scheduled_hook(self::HOOK);
        } elseif (!wp_next_scheduled(self::HOOK)) {
            $result = wp_schedule_event(time() + 60, 'starclock_minute', self::HOOK, [], true);
            if (is_wp_error($result)) {
                throw new \RuntimeException('Der WordPress-Takt konnte nicht eingerichtet werden. Bitte die Website-Konfiguration prüfen.');
            }
        }
    }

    public static function boot(string $file): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['starclock_minute'] = ['interval' => 60, 'display' => 'Starclock: jede Minute'];
            return $schedules;
        });
        add_action(self::HOOK, static function (): void {
            try {
                $engine = self::engine();
                $engine->tick(self::now());
                update_option('starclock_last_tick', time(), false);
                delete_option('starclock_tick_error');
                self::syncCron($engine);
            } catch (Throwable $error) {
                update_option('starclock_tick_error', sanitize_text_field($error->getMessage()), false);
            }
        });
        add_filter('mailpoet_sending_queue_subscribers_to_process', static function (array $subscribers, $newsletter): array {
            try {
                return self::engine()->permits('mailpoet', (int)$newsletter->getId(), self::now()) ? $subscribers : [];
            } catch (Throwable $error) {
                update_option('starclock_tick_error', sanitize_text_field($error->getMessage()), false);
                return [];
            }
        }, 100, 2);
        if (is_admin()) {
            (new Admin(self::engine(), $file))->hooks();
        }
    }
}
