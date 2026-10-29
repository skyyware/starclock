<?php
declare(strict_types=1);

namespace Starclock;

use Throwable;

final class Admin
{
    private const DAYS = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    private const STATES = ['paused' => 'Pausiert', 'ready' => 'Geprüft', 'active' => 'Aktiv', 'needs_review' => 'Prüfung nötig',
        'claimed' => 'Reserviert', 'handing_over' => 'Übergabe offen', 'queued' => 'An MailPoet übergeben', 'skipped' => 'Ausgelassen',
        'expired' => 'Zeitfenster abgelaufen', 'completed' => 'In MailPoet abgeschlossen', 'cancelled' => 'Abgebrochen', 'closed' => 'Geschlossen'];

    public function __construct(private Engine $engine, private string $file)
    {
    }

    public function hooks(): void
    {
        add_action('admin_menu', function (): void {
            add_menu_page('Starclock', 'Starclock', 'manage_options', 'starclock', [$this, 'page'], 'dashicons-clock', 58);
        });
        add_action('admin_post_starclock', [$this, 'submit']);
        add_action('admin_enqueue_scripts', function (string $hook): void {
            if ($hook === 'toplevel_page_starclock') {
                wp_enqueue_style('starclock-admin', plugins_url('assets/admin.css', $this->file), [], '0.1.0');
            }
        });
    }

    public static function authorized(): bool
    {
        return current_user_can('manage_options') && (!defined('MAILPOET_VERSION') || current_user_can('mailpoet_manage_emails'));
    }

    public function submit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !self::authorized()) {
            wp_die(esc_html__('Keine Berechtigung für diese Änderung.', 'starclock'), '', ['response' => 403]);
        }
        check_admin_referer('starclock_change');
        $id = absint($_POST['plan_id'] ?? 0);
        try {
            $action = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
            $revision = absint($_POST['revision'] ?? 0);
            if ($action === 'save') {
                $input = wp_unslash($_POST);
                [$input['adapter'], $input['template_id']] = array_pad(explode(':', (string)($input['target'] ?? ''), 2), 2, '');
                $plan = $this->engine->save($input, $id, $revision);
                $id = $plan['id'];
            } else {
                $this->engine->action($id, $revision, $action, Plugin::now());
            }
            Plugin::syncCron($this->engine);
            set_transient('starclock_notice_' . get_current_user_id(), ['ok' => true, 'text' => 'Änderung gespeichert.'], 60);
        } catch (Throwable $error) {
            set_transient('starclock_notice_' . get_current_user_id(), ['ok' => false, 'text' => $error->getMessage()], 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=starclock' . ($id ? '&plan=' . $id : '')));
        exit;
    }

    private function formStart(array $plan, string $action): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('starclock_change');
        foreach (['action' => 'starclock', 'operation' => $action, 'plan_id' => $plan['id'] ?? 0, 'revision' => $plan['revision'] ?? 0] as $key => $value) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">';
        }
    }

    private function button(array $plan, string $operation, string $label, bool $primary = false, bool $disabled = false): void
    {
        $this->formStart($plan, $operation);
        echo '<button class="button ' . ($primary ? 'button-primary' : '') . '"' . ($disabled ? ' disabled' : '') . '>' . esc_html($label) . '</button></form>';
    }

    public function page(): void
    {
        if (!self::authorized()) {
            wp_die(esc_html__('Du brauchst Website- und MailPoet-Verwaltungsrechte.', 'starclock'), '', ['response' => 403]);
        }
        echo '<div class="wrap starclock"><h1>Starclock</h1><hr class="wp-header-end"><header class="sc-header"><p>Wiederkehrende E-Mails</p>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=starclock&new=1')) . '">Neuer Zeitplan</a></header>';
        $notice = get_transient('starclock_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('starclock_notice_' . get_current_user_id());
            echo '<div class="notice ' . ($notice['ok'] ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($notice['text']) . '</p></div>';
        }
        try {
            $plans = $this->engine->store->plans();
            $selected = absint($_GET['plan'] ?? 0);
            if (isset($_GET['new']) || !$plans) {
                $this->editor(null);
            } elseif ($selected) {
                $plan = $this->engine->store->plan($selected);
                $this->card($plan, true);
                $this->editor($plan);
                $this->history($plan);
            } else {
                echo '<div class="sc-grid">';
                foreach ($plans as $plan) {
                    $this->card($plan, false);
                }
                echo '</div>';
            }
            $this->health($plans);
        } catch (Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
        }
        echo '</div>';
    }

    private function card(array $plan, bool $detail): void
    {
        $next = $this->engine->next($plan, Plugin::now());
        echo '<section class="sc-card"><div class="sc-card-heading"><h2>' . esc_html($plan['title']) . '</h2><span class="sc-state sc-' . esc_attr($plan['status']) . '">' . esc_html(self::STATES[$plan['status']] ?? $plan['status']) . '</span></div>';
        echo '<p class="sc-schedule">' . esc_html(self::DAYS[$plan['weekday']] . ' · ' . $plan['time'] . ' Uhr') . '</p>';
        echo '<p>' . esc_html($plan['timezone']) . ' · ab ' . esc_html(wp_date('d.m.Y', strtotime($plan['start_date'] . ' 12:00 UTC'))) . '</p>';
        echo '<div class="sc-next"><span>' . ($plan['status'] === 'active' ? 'Nächster Lauf' : 'Nächster möglicher Termin') . '</span><strong>' . esc_html($next ? $next->format('d.m.Y · H:i') : 'Kein Termin') . '</strong></div>';
        if ($plan['status'] !== 'active') {
            echo '<p>Der automatische Versand ist ausgeschaltet.</p>';
        }
        if (!empty($plan['approved'])) {
            echo '<dl class="sc-summary"><dt>Mail</dt><dd>' . esc_html($plan['approved']['subject']) . '</dd><dt>Empfänger</dt><dd>' . esc_html(implode(', ', $plan['approved']['lists'])) . '</dd><dt>Absender</dt><dd>' . esc_html($plan['approved']['sender']) . '</dd><dt>Antworten an</dt><dd>' . esc_html($plan['approved']['reply_to']) . '</dd></dl>';
        }
        echo '<p class="sc-note">' . esc_html($plan['notice'] ?? '') . '</p>';
        $activationProblem = $plan['status'] === 'ready' ? $this->engine->activationProblem($plan) : null;
        if ($activationProblem) {
            echo '<p class="sc-warning">' . esc_html($activationProblem) . '</p>';
        }
        echo '<div class="sc-actions">';
        if ($plan['status'] === 'active') {
            $this->button($plan, 'pause', 'Pausieren');
            $this->button($plan, 'skip', 'Nächsten Lauf auslassen');
        } elseif ($plan['status'] === 'ready') {
            $this->button($plan, 'enable', 'Automatik aktivieren', true, $activationProblem !== null);
            $this->button($plan, 'pause', 'Pausiert lassen');
        } elseif ($this->engine->store->pending($plan['id'])) {
            $this->button($plan, 'resolve', 'Unterbrochene Läufe schließen', true);
        } else {
            $this->button($plan, 'review', 'Vorlage und Zeitplan prüfen', true);
        }
        if (!$detail) {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=starclock&plan=' . $plan['id'])) . '">Bearbeiten & Verlauf</a>';
        }
        if ($plan['adapter'] === 'mailpoet') {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=mailpoet-newsletter-editor&id=' . $plan['template_id'])) . '">Vorlage in MailPoet öffnen</a>';
        }
        echo '</div>';
        if ($plan['status'] === 'ready') {
            echo '<p class="sc-help">Aktivieren erlaubt wiederkehrende echte E-Mails an die gewählten Listen. Neue bestätigte Kontakte sind beim nächsten Lauf dabei. Abmeldungen verwaltet MailPoet.</p>';
        }
        echo '</section>';
    }

    private function editor(?array $plan): void
    {
        $values = $plan ?? ['title' => '', 'adapter' => 'mailpoet', 'template_id' => 0, 'weekday' => 1, 'time' => '18:30',
            'timezone' => wp_timezone_string(), 'start_date' => wp_date('Y-m-d'), 'window_minutes' => 60];
        if (!in_array($values['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $values['timezone'] = 'UTC';
        }
        echo '<section class="sc-card"><h2>' . ($plan ? 'Zeitplan bearbeiten' : 'Neuer Zeitplan') . '</h2>';
        $this->formStart($plan ?? [], 'save');
        $this->input('title', 'Name', $values['title'], 'text', 'maxlength="160"');
        $choices = [];
        foreach ($this->engine->adapters() as $key => $adapter) {
            try {
                $choices[$key] = ['label' => $adapter->label(), 'items' => array_filter($adapter->choices(), fn(array $item): bool => !$this->engine->store->forExternal($key, $item['id']))];
            } catch (Throwable $error) {
                echo '<p class="sc-warning">' . esc_html($error->getMessage()) . '</p>';
            }
        }
        if (!$choices) {
            echo '<p>Eine verfügbare Erweiterung und ein Newsletter-Entwurf sind nötig, um den Zeitplan zu speichern.</p></form></section>';
            return;
        }
        $templates = ['' => 'Vorlage wählen'];
        foreach ($choices as $key => $group) {
            foreach ($group['items'] as $choice) {
                $templates[$key . ':' . $choice['id']] = $group['label'] . ' · ' . ($choice['label'] ?: '(Ohne Betreff)');
            }
        }
        $this->select('target', 'Vorlage', $templates, $values['adapter'] . ':' . $values['template_id']);
        echo '<p class="sc-help">Den Standard-Newsletter als Entwurf in MailPoet anlegen. Inhalt, Listen, Absender und Abmeldelink werden dort gepflegt.</p>';
        echo '<div class="sc-fields">';
        $this->select('weekday', 'Wochentag', self::DAYS, $values['weekday']);
        $this->input('time', 'Uhrzeit', $values['time'], 'time');
        $this->input('start_date', 'Frühestens ab', $values['start_date'], 'date');
        echo '</div>';
        $this->select('timezone', 'Zeitzone', array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()), $values['timezone']);
        $this->select('window_minutes', 'Versandfenster', [15 => '15 Minuten', 60 => '1 Stunde', 180 => '3 Stunden', 360 => '6 Stunden'], $values['window_minutes']);
        echo '<p class="sc-help">Ist der Server länger verspätet, fällt der Termin aus. Offene Restmengen werden nach Ablauf pausiert, spätestens um Mitternacht. Bereits versandte E-Mails bleiben versandt.</p>';
        echo '<div class="sc-actions"><button class="button button-primary">Pausiert speichern</button></div><p class="sc-help">Änderungen pausieren den Plan und seine noch offenen MailPoet-Läufe. Danach erneut prüfen und aktivieren.</p></form></section>';
    }

    private function input(string $name, string $label, string $value, string $type, string $extra = ''): void
    {
        echo '<div class="sc-field"><label for="sc-' . esc_attr($name) . '">' . esc_html($label) . '</label><input required id="sc-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '" ' . $extra . '></div>';
    }

    private function select(string $name, string $label, array $options, mixed $value): void
    {
        echo '<div class="sc-field"><label for="sc-' . esc_attr($name) . '">' . esc_html($label) . '</label><select required id="sc-' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        foreach ($options as $key => $text) {
            echo '<option value="' . esc_attr((string)$key) . '" ' . selected((string)$value, (string)$key, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></div>';
    }

    private function history(array $plan): void
    {
        echo '<section class="sc-card"><h2>Letzte Läufe</h2><ul class="sc-history">';
        foreach ($this->engine->store->history($plan['id']) as $run) {
            echo '<li><strong>' . esc_html($run['occurrence']) . '</strong><span>' . esc_html(self::STATES[$run['state']] ?? $run['state']) . '</span>';
            if ($run['external_id'] && $run['adapter'] === 'mailpoet') {
                echo '<a href="' . esc_url(admin_url('admin.php?page=mailpoet-newsletters#/')) . '">In MailPoet prüfen · ' . esc_html((string)$run['external_id']) . '</a>';
            }
            if (!empty($run['message'])) {
                echo '<p>' . esc_html($run['message']) . '</p>';
            }
            echo '</li>';
        }
        echo '</ul><p class="sc-help">„An MailPoet übergeben“ bestätigt die Übergabe, nicht die Zustellung. Versanddetails stehen in MailPoet.</p></section>';
    }

    private function health(array $plans): void
    {
        $active = array_filter($plans, static fn(array $plan): bool => $plan['status'] === 'active');
        $last = (int)get_option('starclock_last_tick', 0);
        echo '<section class="sc-health"><h2>WordPress-Takt</h2><p>';
        echo $last ? 'Zuletzt ausgeführt: ' . esc_html(wp_date('d.m.Y · H:i', $last)) : 'Noch kein automatischer Lauf.';
        echo '</p><p>WordPress-Cron läuft normalerweise bei Seitenaufrufen. Für verlässliche Uhrzeiten sollte der Hoster WordPress-Cron jede Minute ausführen. Starclock richtet keinen Server-Cron ein.</p>';
        if ($active && (!$last || time() - $last > 600)) {
            echo '<p class="sc-warning">Der regelmäßige Takt ist noch nicht bestätigt. Bitte den Hoster-Takt vor dem ersten Versand prüfen.</p>';
        }
        if ($error = get_option('starclock_tick_error')) {
            echo '<p class="sc-warning">' . esc_html((string)$error) . '</p>';
        }
        if (wp_get_environment_type() !== 'production') {
            echo '<p>Testumgebung: ' . esc_html(wp_get_environment_type()) . '. Die normale Aktivierung bleibt hier gesperrt.</p>';
        }
        echo '</section>';
    }
}
