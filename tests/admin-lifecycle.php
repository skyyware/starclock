<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$engine = new Starclock\Engine(new Starclock\Store($GLOBALS['wpdb']), ['mailpoet' => new Starclock\MailPoetAdapter()], WP_HOME, false);
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
$html = $render([]);
$check('Plan list offers archive and delete confirmation', str_contains($html, 'value="archive"') && str_contains($html, 'confirm_delete=' . $id));
$before = $engine->store->plans();
$html = $render(['plan' => $id, 'confirm_delete' => $id]);
$check('GET confirmation does not mutate', $before === $engine->store->plans());
$check('Confirmation identifies the plan and offers a cancel path', str_contains($html, esc_html($source['title']))
    && str_contains($html, 'name="confirm_delete"') && str_contains($html, 'Abbrechen'));
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
$submit($post);
$check('Confirmed POST deletes and redirects to retained evidence', $engine->store->plan($id)['status'] === 'deleted' && str_contains((string)$redirect, 'plan=' . $id));
$check('Deleted plans leave working and archive views', !str_contains($render([]), $source['title'])
    && !str_contains($render(['view' => 'archive']), $source['title']));
$html = $render(['view' => 'deleted']);
$detail = $render(['plan' => $id]);
$check('Deleted records remain read-only and findable', str_contains($html, $source['title']) && str_contains($detail, 'Letzte Läufe')
    && !str_contains($detail, 'name="operation"') && !str_contains($detail, 'name="target"'));
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
