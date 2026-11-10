<?php
declare(strict_types=1);

namespace Starclock;

use Throwable;

final class Diagnostics
{
    public function __construct(private Engine $engine)
    {
    }

    public function siteHealth(): array
    {
        $critical = $recommended = [];
        try {
            $plans = array_filter($this->engine->store->plans(),
                static fn(array $plan): bool => in_array($plan['status'], ['active', 'needs_review'], true));
            foreach ($plans as $plan) {
                $problem = $plan['status'] === 'needs_review'
                    ? ($plan['notice'] ?? 'Vorlage und Zeitplan erneut prüfen und aktivieren.')
                    : $this->engine->activationProblem($plan);
                if ($problem) {
                    $critical[] = $plan['title'] . ': ' . $problem;
                }
            }
            if ($plans) {
                $next = wp_next_scheduled(Plugin::HOOK);
                if (!$next || $next < time() - 300) {
                    $critical[] = 'Der WordPress-Takt fehlt oder ist überfällig. Den unabhängigen Cron-Aufruf beim Hosting prüfen.';
                }
                $error = get_option('starclock_tick_error');
                if (!$critical && is_string($error) && $error !== '') {
                    $critical[] = $error;
                }
                $usesMailPoet = array_filter($plans, static fn(array $plan): bool => $plan['adapter'] === 'mailpoet');
                $updates = get_site_transient('update_plugins');
                $offered = $updates->response['mailpoet/mailpoet.php']->new_version ?? null;
                if ($usesMailPoet && is_string($offered) && MailPoetAdapter::compatibilityProblem($offered)) {
                    $recommended[] = 'Das angebotene MailPoet-Update ' . $offered . ' ist noch nicht mit Starclock geprüft. '
                        . 'Vor dem Update die Kompatibilität testen und Starclock aktualisieren. '
                        . 'Ungeprüfte Versionen stoppen den automatischen Versand; Sicherheitsupdates werden nicht blockiert.';
                }
            }
        } catch (Throwable $error) {
            $critical[] = $error->getMessage();
        }
        $status = $critical ? 'critical' : ($recommended ? 'recommended' : 'good');
        $description = '<ul>';
        foreach (array_unique(array_merge($critical, $recommended)) as $message) {
            $description .= '<li>' . esc_html($message) . '</li>';
        }
        $description .= '</ul>';
        return ['label' => match ($status) {
            'critical' => 'Starclock: Automatischer Versand benötigt eine Prüfung',
            'recommended' => 'Starclock: MailPoet-Update vorab prüfen',
            default => 'Starclock: Kein blockierter Zeitplan',
        }, 'status' => $status, 'badge' => ['label' => 'Starclock', 'color' => 'blue'],
            'description' => $status === 'good' ? '<p>Kein blockierter Zeitplan erkannt. Das bestätigt keine E-Mail-Zustellung.</p>' : $description,
            'actions' => '<p><a href="' . esc_url(admin_url('admin.php?page=starclock')) . '">Zeitpläne prüfen</a></p>',
            'test' => 'starclock'];
    }

    public function notice(): void
    {
        if (!Admin::authorized()) {
            return;
        }
        $health = $this->siteHealth();
        if ($health['status'] === 'good') {
            return;
        }
        echo '<div class="notice notice-' . ($health['status'] === 'critical' ? 'error' : 'warning') . '"><p><strong>'
            . esc_html($health['label']) . '</strong></p>' . $health['description'] . $health['actions'] . '</div>';
    }
}
