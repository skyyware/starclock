<?php
declare(strict_types=1);

namespace Starclock;

use Throwable;

final class Admin
{
    private const DAYS = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    private const STATES = ['paused' => 'Pausiert', 'ready' => 'Geprüft', 'active' => 'Aktiv', 'needs_review' => 'Prüfung nötig',
        'claimed' => 'Reserviert', 'handing_over' => 'Übergabe offen', 'queued' => 'An MailPoet übergeben', 'skipped' => 'Ausgelassen',
        'expired' => 'Zeitfenster abgelaufen', 'completed' => 'In MailPoet abgeschlossen', 'cancelled' => 'Abgebrochen', 'closed' => 'Geschlossen',
        'archived' => 'Archiviert', 'deleted' => 'Gelöscht'];

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
                wp_enqueue_style('starclock-admin', plugins_url('assets/admin.css', $this->file), [], '0.2.1');
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
        $id = absint($_POST['plan_id'] ?? 0);
        $action = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        $revision = absint($_POST['revision'] ?? 0);
        check_admin_referer($action === 'delete' ? 'starclock_delete_' . $id . '_' . $revision : 'starclock_change');
        try {
            if ($action === 'delete' && (string)($_POST['confirm_delete'] ?? '') !== (string)$id) {
                throw new \RuntimeException('Bitte das Löschen dieses Zeitplans ausdrücklich bestätigen.');
            }
            if ($action === 'save') {
                $input = wp_unslash($_POST);
                [$input['adapter'], $input['template_id']] = array_pad(explode(':', (string)($input['target'] ?? ''), 2), 2, '');
                $plan = $this->engine->save($input, $id, $revision);
                $id = $plan['id'];
            } else {
                $this->engine->action($id, $revision, $action, Plugin::now());
            }
            set_transient('starclock_notice_' . get_current_user_id(), ['ok' => true, 'text' => 'Änderung gespeichert.'], 60);
        } catch (Throwable $error) {
            set_transient('starclock_notice_' . get_current_user_id(), ['ok' => false, 'text' => $error->getMessage()], 60);
        }
        Plugin::syncCron($this->engine);
        wp_safe_redirect(admin_url('admin.php?page=starclock' . ($id ? '&plan=' . $id : '')));
        exit;
    }

    private function formStart(array $plan, string $action): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($action === 'delete' ? 'starclock_delete_' . $plan['id'] . '_' . $plan['revision'] : 'starclock_change');
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
            $plan = $selected ? $this->engine->store->plan($selected) : null;
            $view = $plan ? match ($plan['status']) {
                'archived' => 'archive', 'deleted' => 'deleted', default => 'plans',
            } : sanitize_key($_GET['view'] ?? 'plans');
            if (!in_array($view, ['plans', 'archive', 'deleted'], true)) {
                $view = 'plans';
            }
            echo '<nav class="sc-tabs" aria-label="Zeitplanansichten">';
            foreach (['plans' => 'Zeitpläne', 'archive' => 'Archiv', 'deleted' => 'Gelöscht / Nachweise'] as $key => $label) {
                echo '<a class="sc-tab"' . ($key === $view ? ' aria-current="page"' : '')
                    . ' href="' . esc_url(admin_url('admin.php?page=starclock&view=' . $key)) . '">' . esc_html($label) . '</a>';
            }
            echo '</nav>';
            if (isset($_GET['new'])) {
                $this->editor(null);
            } elseif ($plan) {
                if (absint($_GET['confirm_delete'] ?? 0) === $selected) {
                    $this->confirmDelete($plan);
                } else {
                    $this->card($plan, true);
                    if (!in_array($plan['status'], ['archived', 'deleted'], true)) {
                        $this->editor($plan);
                    }
                }
                $this->history($plan);
            } else {
                $plans = array_filter($plans, static fn(array $item): bool => match ($view) {
                    'archive' => $item['status'] === 'archived', 'deleted' => $item['status'] === 'deleted',
                    default => !in_array($item['status'], ['archived', 'deleted'], true),
                });
                if (!$plans) {
                    echo '<p class="sc-empty">' . match ($view) {
                        'archive' => 'Keine archivierten Zeitpläne.', 'deleted' => 'Keine gelöschten Zeitpläne.',
                        default => 'Keine Zeitpläne. Lege einen neuen Zeitplan an.',
                    } . '</p>';
                }
                echo '<div class="sc-grid">';
                foreach ($plans as $plan) {
                    $this->card($plan, false);
                }
                echo '</div>';
            }
        } catch (Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
        }
        echo '</div>';
    }

    private function card(array $plan, bool $detail): void
    {
        echo '<section class="sc-card"><div class="sc-card-heading"><h2>' . esc_html($plan['title']) . '</h2><span class="sc-state sc-' . esc_attr($plan['status']) . '">' . esc_html(self::STATES[$plan['status']] ?? $plan['status']) . '</span></div>';
        if ($plan['status'] === 'deleted') {
            echo '<p>Gelöscht am ' . esc_html(wp_date('d.m.Y · H:i', $plan['deleted_at'])) . '</p><p>Die Planung ist entfernt. Laufnachweise bleiben erhalten.</p>';
            if (!$detail) {
                echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=starclock&plan=' . $plan['id'])) . '">Nachweise ansehen</a>';
            }
            echo '</section>';
            return;
        }
        $next = $plan['status'] === 'archived' ? null : $this->engine->next($plan, Plugin::now());
        echo '<p class="sc-schedule">' . esc_html(self::DAYS[$plan['weekday']] . ' · ' . $plan['time'] . ' Uhr') . '</p>';
        echo '<p>' . esc_html($plan['timezone']) . ' · ab ' . esc_html(wp_date('d.m.Y', strtotime($plan['start_date'] . ' 12:00 UTC'))) . '</p>';
        if ($plan['status'] !== 'archived') {
            echo '<div class="sc-next"><span>' . ($plan['status'] === 'active' ? 'Nächster Lauf' : 'Nächster möglicher Termin') . '</span><strong>' . esc_html($next ? $next->format('d.m.Y · H:i') : 'Kein Termin') . '</strong></div>';
        }
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
        if ($plan['status'] === 'archived') {
            $this->button($plan, 'restore', 'Pausiert wiederherstellen', true);
        } elseif ($plan['status'] === 'active') {
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
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=starclock&plan=' . $plan['id'])) . '">'
                . ($plan['status'] === 'archived' ? 'Details & Verlauf' : 'Bearbeiten & Verlauf') . '</a>';
        }
        if ($plan['adapter'] === 'mailpoet') {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=mailpoet-newsletter-editor&id=' . $plan['template_id'])) . '">Vorlage in MailPoet öffnen</a>';
        }
        echo '</div>';
        echo '<div class="sc-lifecycle">';
        if ($plan['status'] !== 'archived') {
            $this->button($plan, 'archive', 'Archivieren');
        }
        if (in_array($plan['status'], ['paused', 'archived'], true)) {
            echo '<a class="sc-delete-link" href="' . esc_url(admin_url('admin.php?page=starclock&plan=' . $plan['id'] . '&confirm_delete=' . $plan['id'])) . '">Löschen…</a>';
        } else {
            echo '<span class="sc-help">Zum Löschen zuerst pausieren oder archivieren.</span>';
        }
        echo '</div>';
        if ($plan['status'] === 'ready') {
            echo '<p class="sc-help">Aktivieren erlaubt wiederkehrende echte E-Mails an die gewählten Listen. Neue bestätigte Kontakte sind beim nächsten Lauf dabei. Abmeldungen verwaltet MailPoet.</p>';
        }
        echo '</section>';
    }

    private function confirmDelete(array $plan): void
    {
        if (!in_array($plan['status'], ['paused', 'archived'], true)) {
            echo '<p class="sc-warning">Nur pausierte oder archivierte Zeitpläne können gelöscht werden.</p>';
            return;
        }
        echo '<section class="sc-card sc-delete-confirm"><h2>Zeitplan löschen?</h2><p><strong>' . esc_html($plan['title']) . '</strong></p>';
        echo '<p>Die Planung wird dauerhaft entfernt und kann nicht wiederhergestellt werden. Laufnachweise bleiben erhalten. MailPoet-Vorlagen, Kampagnen, Listen und Kontakte werden nicht gelöscht.</p>';
        $this->formStart($plan, 'delete');
        echo '<div class="sc-actions"><button class="button sc-delete-button" name="confirm_delete" value="' . esc_attr((string)$plan['id']) . '">Zeitplan endgültig löschen</button>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=starclock&plan=' . $plan['id'])) . '">Abbrechen</a></div></form></section>';
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
        $runs = $this->engine->store->history($plan['id']);
        foreach ($runs as $run) {
            echo '<li><strong>' . esc_html($run['occurrence']) . '</strong><span>' . esc_html(self::STATES[$run['state']] ?? $run['state']) . '</span>';
            if ($run['external_id'] && $run['adapter'] === 'mailpoet') {
                echo '<a href="' . esc_url(admin_url('admin.php?page=mailpoet-newsletters#/')) . '">In MailPoet prüfen · ' . esc_html((string)$run['external_id']) . '</a>';
            }
            if (!empty($run['message'])) {
                echo '<p>' . esc_html($run['message']) . '</p>';
            }
            echo '</li>';
        }
        echo '</ul><p class="sc-help">' . ($runs
            ? '„An MailPoet übergeben“ bestätigt die Übergabe, nicht die Zustellung. Versanddetails stehen in MailPoet.'
            : 'Für diesen Zeitplan liegen keine Läufe vor.') . '</p></section>';
    }

}
