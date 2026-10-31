<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$engine = new Starclock\Engine(new Starclock\Store($GLOBALS['wpdb']), ['mailpoet' => new Starclock\MailPoetAdapter()], WP_HOME, true);
$admin = new Starclock\Admin($engine, dirname(__DIR__) . '/starclock.php');
$users = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($users[0]->ID);
$source = array_values(array_filter($engine->store->plans(), static fn(array $plan): bool => isset($plan['template_id'])))[0];
$source['title'] = 'Synthetic admin lifecycle ' . bin2hex(random_bytes(4));
$plan = $engine->save($source, 0, 0);
$id = $plan['id'];
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    if (!$ok) {
        throw new RuntimeException($name);
    }
};
$render = static function (array $query) use ($admin): string {
    $_GET = $query;
    ob_start();
    $admin->page();
    return ob_get_clean();
};
$navigation = static function (string $html, string $current): bool {
    $document = new DOMDocument();
    $errorMode = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($errorMode);
    }
    if (!$loaded) {
        return false;
    }
    $xpath = new DOMXPath($document);
    $links = $xpath->query('//nav[@aria-label="Zeitplanansichten"]/a');
    $labels = ['plans' => 'Zeitpläne', 'archive' => 'Archiv'];
    if ($links->length !== count($labels)) {
        return false;
    }
    foreach (array_keys($labels) as $index => $view) {
        $link = $links->item($index);
        if ($link->textContent !== $labels[$view]
            || $link->getAttribute('href') !== admin_url('admin.php?page=starclock&view=' . $view)
            || $link->getAttribute('aria-current') !== ($view === $current ? 'page' : '')
            || $link->hasAttribute('role') || $link->hasAttribute('tabindex')) {
            return false;
        }
    }
    return true;
};
foreach (['plans', 'archive'] as $view) {
    $check('Navigation preserves labels, targets and current page for ' . $view, $navigation($render(['view' => $view]), $view));
}
$check('Unknown view falls back to the working navigation', $navigation($render(['view' => 'unknown']), 'plans'));
$check('Retired deleted view falls back to the working list', $render(['view' => 'deleted']) === $render([]));
$now = new DateTimeImmutable('2027-01-25 18:29 Europe/Berlin');
$plan = $engine->action($id, $plan['revision'], 'review', $now);
$plan = $engine->action($id, $plan['revision'], 'enable', $now);
$plan = $engine->action($id, $plan['revision'], 'skip', $now);
$receipt = $engine->store->history($id)[0];
$html = $render(['plan' => $id]);
$check('Active plans keep their actions and run history', $navigation($html, 'plans') && str_contains($html, $source['title'])
    && str_contains($html, 'value="pause"') && str_contains($html, 'Letzte Läufe') && str_contains($html, $receipt['occurrence']));
$plan = $engine->action($id, $plan['revision'], 'pause', $now);
$html = $render([]);
$check('Plan list offers archive and delete confirmation', str_contains($html, 'value="archive"') && str_contains($html, 'confirm_delete=' . $id));
$before = $engine->store->plans();
$html = $render(['plan' => $id, 'confirm_delete' => $id]);
$check('GET confirmation does not mutate', $before === $engine->store->plans());
$check('Confirmation identifies the plan and offers a cancel path', str_contains($html, esc_html($source['title']))
    && str_contains($html, 'name="confirm_delete"') && str_contains($html, 'Abbrechen'));
$check('Confirmation explains permanence without offering deleted evidence', str_contains($html, 'nicht wiederhergestellt')
    && str_contains($html, 'MailPoet') && !str_contains($html, 'Nachweise') && !str_contains($html, 'Laufnachweise'));
add_filter('wp_die_handler', static fn() => static function ($message, $title, $args): never {
    throw new RuntimeException('denied-' . ($args['response'] ?? 500));
});
$redirect = null;
add_filter('wp_redirect', static function (string $url) use (&$redirect): never {
    $redirect = $url;
    throw new RuntimeException('redirect-captured');
}, 1);
$submit = static function (array $post) use ($admin): string {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $_REQUEST = $post;
    try {
        $admin->submit();
    } catch (RuntimeException $error) {
        return $error->getMessage();
    }
    return 'unexpected';
};
$post = ['operation' => 'delete', 'plan_id' => $id, 'revision' => $plan['revision'], '_wpnonce' => wp_create_nonce('starclock_change')];
$check('Generic mutation nonce cannot delete a plan', $submit($post) === 'denied-403' && $engine->store->plan($id) === $plan);
$post['_wpnonce'] = wp_create_nonce('starclock_delete_' . $id . '_' . $plan['revision']);
$submit($post);
$check('Missing explicit confirmation cannot delete', $engine->store->plan($id) === $plan);
$post = ['operation' => 'archive', 'plan_id' => $id, 'revision' => $plan['revision'], '_wpnonce' => wp_create_nonce('starclock_change')];
$submit($post);
$plan = $engine->store->plan($id);
$check('Archive POST persists archived state', $plan['status'] === 'archived');
$check('Archived plans are absent from the working list', !str_contains($render([]), $source['title']));
$html = $render(['view' => 'archive']);
$check('Archived detail selects the archive navigation', $navigation($render(['plan' => $id]), 'archive'));
$detail = $render(['plan' => $id]);
$check('Archive retains run history', str_contains($detail, 'Letzte Läufe') && str_contains($detail, $receipt['occurrence']));
$check('Archive view offers paused restoration without editing', str_contains($html, $source['title']) && str_contains($html, 'value="restore"')
    && !str_contains($render(['plan' => $id]), 'name="target"'));
$post['operation'] = 'restore';
$post['revision'] = $plan['revision'];
$submit($post);
$plan = $engine->store->plan($id);
$check('Restore POST returns a paused plan to the working list', $plan['status'] === 'paused'
    && str_contains($render([]), $source['title']) && !str_contains($render(['view' => 'archive']), $source['title']));
$post = ['operation' => 'delete', 'plan_id' => $id, 'revision' => $plan['revision'],
    '_wpnonce' => wp_create_nonce('starclock_delete_' . $id . '_' . $plan['revision']), 'confirm_delete' => (string)$id];
wp_set_current_user(0);
$check('Unauthorized confirmed delete is denied', $submit($post) === 'denied-403' && $engine->store->plan($id) === $plan);
wp_set_current_user($users[0]->ID);
$stale = $post;
$stale['revision']--;
$stale['_wpnonce'] = wp_create_nonce('starclock_delete_' . $id . '_' . $stale['revision']);
$submit($stale);
$check('Stale confirmed deletion preserves the newer plan', $engine->store->plan($id) === $plan);
$check('Failed deletion keeps the detail and reports failure', $redirect === admin_url('admin.php?page=starclock&plan=' . $id)
    && get_transient('starclock_notice_' . get_current_user_id())['ok'] === false);
$submit($post);
$check('Confirmed POST deletes and redirects to the working list', $engine->store->plan($id)['status'] === 'deleted'
    && $redirect === admin_url('admin.php?page=starclock'));
$notice = get_transient('starclock_notice_' . get_current_user_id());
$check('Successful deletion has a concise confirmation', $notice === ['ok' => true, 'text' => 'Zeitplan gelöscht.']);
$check('Deleted plans leave working and archive views', !str_contains($render([]), $source['title'])
    && !str_contains($render(['view' => 'archive']), $source['title']));
$check('Retired view reveals no deleted plan or evidence link', $render(['view' => 'deleted']) === $render([])
    && !str_contains($render([]), 'Nachweise'));
$withReceipt = array_values(array_filter($engine->store->plans(), static fn(array $item): bool => $item['status'] === 'deleted'
    && (bool)array_filter($engine->store->history($item['id']), static fn(array $run): bool => !empty($run['receipt']))));
$check('A deleted native handoff fixture is available', (bool)$withReceipt);
$deleted = [$engine->store->plan($id), $withReceipt[0]];
foreach ($deleted as $item) {
    $history = $engine->store->history($item['id']);
    foreach ([[], ['view' => 'deleted'], ['view' => 'archive'], ['confirm_delete' => $item['id']], ['new' => 1]] as $query) {
        $html = $render(['plan' => $item['id']] + $query);
        $check('Deleted direct URL hides identity, history and controls ' . $item['id'] . ':' . json_encode($query),
            str_contains($html, 'Dieser Zeitplan wurde nicht gefunden.') && !str_contains($html, esc_html($item['title']))
            && !str_contains($html, 'Letzte Läufe') && !str_contains($html, 'name="operation"') && !str_contains($html, 'name="target"')
            && $html === $render(['plan' => 2147483000] + $query));
    }
    $check('Reading deleted URLs preserves internal state ' . $item['id'], $engine->store->plan($item['id']) === $item
        && $engine->store->history($item['id']) === $history);
}
$check('Deletion and hidden detail reads retain the exact run receipt', $engine->store->run($receipt['id']) === $receipt);
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
