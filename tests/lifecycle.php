<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

use MailPoet\DI\ContainerWrapper;
use MailPoet\Newsletter\NewslettersRepository;
use Starclock\{Engine, MailPoetAdapter, Store};

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    if (!$ok) {
        throw new RuntimeException($name);
    }
};
$rejects = static function (callable $action): bool {
    try {
        $action();
        return false;
    } catch (RuntimeException) {
        return true;
    }
};
$db = $GLOBALS['wpdb'];
$store = new Store($db);
$adapter = new MailPoetAdapter();
$engine = new Engine($store, ['mailpoet' => $adapter], WP_HOME, true);
$source = array_values(array_filter($store->plans(), static fn(array $plan): bool => isset($plan['template_id'])))[0];
$source['title'] = 'Synthetic lifecycle ' . bin2hex(random_bytes(4));
$plan = $engine->save($source, 0, 0);
$id = $plan['id'];
$now = new DateTimeImmutable('2027-01-04 18:29 Europe/Berlin');
$beforeArchive = $plan;
$plan = $engine->action($id, $plan['revision'], 'archive', $now);
$check('Archive removes a paused plan from execution', $plan['status'] === 'archived' && !$plan['approved'] && $engine->tick($now) === []);
$check('Stale forms cannot restore an archived plan', $rejects(fn() => $engine->action($id, $beforeArchive['revision'], 'restore', $now)));
$check('Archived plans cannot be edited or reviewed', $rejects(fn() => $engine->save($source, $id, $plan['revision']))
    && $rejects(fn() => $engine->action($id, $plan['revision'], 'review', $now)));
$engine->deactivate();
$check('Deactivation preserves the archive', $store->plan($id)['status'] === 'archived');
$plan = $engine->action($id, $plan['revision'], 'restore', $now);
$check('Restoration is paused and requires fresh approval', $plan['status'] === 'paused' && !$plan['approved']
    && $rejects(fn() => $engine->action($id, $plan['revision'], 'enable', $now)) && !$engine->tick($now));
$plan = $engine->action($id, $plan['revision'], 'review', $now);
$plan = $engine->action($id, $plan['revision'], 'enable', $now);
$check('Active plans cannot be deleted', $rejects(fn() => $engine->action($id, $plan['revision'], 'delete', $now)) && $store->plan($id)['status'] === 'active');
$now = $now->modify('+1 minute');
$engine->tick($now);
$run = $store->history($id)[0];
$check('Lifecycle fixture has a native handoff', $run['state'] === 'queued' && !empty($run['receipt']));
$container = ContainerWrapper::getInstance();
$newsletters = $container->get(NewslettersRepository::class);
$copy = $newsletters->findOneById($run['external_id']);
$task = $copy->getLatestQueue()->getTask();
$check('Native worker starts idle', !$task->getInProgress());
$table = $db->prefix . 'mailpoet_scheduled_tasks';
$db->update($table, ['in_progress' => 1], ['id' => (int)$run['receipt']]);
try {
    $check('Fresh worker state blocks archival despite a cached idle entity', $rejects(fn() => $engine->action($id, $plan['revision'], 'archive', $now)));
    $plan = $store->plan($id);
    $check('Incomplete archive fails closed without losing the pending receipt', $plan['status'] === 'paused'
        && !$engine->permits('mailpoet', $run['external_id'], $now) && count($store->pending($id)) === 1);
    $check('Running paused campaigns cannot be deleted', $rejects(fn() => $engine->action($id, $plan['revision'], 'delete', $now))
        && $store->plan($id)['status'] === 'paused');
} finally {
    $db->update($table, ['in_progress' => 0], ['id' => (int)$run['receipt'], 'in_progress' => 1]);
}
$plan = $store->plan($id);
$plan = $engine->action($id, $plan['revision'], 'archive', $now);
$check('Archive pauses native queues before completion', $plan['status'] === 'archived' && !$store->pending($id)
    && $adapter->deliveryState($run['external_id']) === 'paused');
$receipt = $store->run($run['id']);
$counts = static fn(): array => array_map(static fn(string $suffix): int => (int)$db->get_var('SELECT COUNT(*) FROM ' . $db->prefix . $suffix),
    ['mailpoet_newsletters', 'mailpoet_segments', 'mailpoet_subscribers', 'mailpoet_scheduled_tasks']);
$before = $counts();
$plan = $engine->action($id, $plan['revision'], 'delete', $now);
$check('Delete removes scheduling data but retains an audit identity', $plan['status'] === 'deleted' && !isset($plan['template_id'], $plan['weekday'])
    && isset($plan['deleted_at']) && $plan['title'] === $source['title']);
$check('Delete retains the exact receipt and campaign ownership', $store->run($run['id']) === $receipt
    && $store->forExternal('mailpoet', $run['external_id'])['plan_id'] === $id);
$check('Delete never removes MailPoet content or audience data', $counts() === $before);
foreach (['restore', 'review', 'enable', 'pause', 'resolve', 'archive', 'delete'] as $operation) {
    $check('Deleted plan rejects ' . $operation, $rejects(fn() => $engine->action($id, $plan['revision'], $operation, $now)));
}
$check('Deleted plan cannot be recreated through an old editor', $rejects(fn() => $engine->save($source, $id, $plan['revision'])));
$engine->deactivate();
$check('Deactivation preserves deleted records', $store->plan($id) === $plan);
$eligible = [];
add_filter('mailpoet_sending_queue_subscribers_to_process', static fn(array $subscribers, $mail): array =>
    $engine->permits('mailpoet', (int)$mail->getId(), $now) ? $subscribers : [], 100, 2);
add_filter('mailpoet_sending_queue_subscribers_to_process', static function (array $subscribers, $mail) use (&$eligible): array {
    $eligible[(int)$mail->getId()] = count($subscribers);
    return [];
}, PHP_INT_MAX, 2);
$db->update($table, ['status' => null], ['id' => (int)$run['receipt']]);
$container->get(MailPoetVendor\Doctrine\ORM\EntityManager::class)->clear();
$container->get(MailPoet\Cron\Workers\SendingQueue\SendingQueue::class)->process();
$check('Native worker cannot send a deleted plan even after a queue is resumed', ($eligible[$run['external_id']] ?? null) === 0);
$adapter->pause($run['external_id']);
$check('Future ticks leave deleted plans and receipts unchanged', $engine->tick($now->modify('+1 week')) === [] && $store->run($run['id']) === $receipt);
$check('Unrelated campaigns still pass the ownership guard', $engine->permits('mailpoet', 2147483000, $now));
$plan = $engine->save($source, 0, 0);
$pending = $store->claim($plan['id'], '2027-01-11', ['adapter' => 'mailpoet']);
$check('Ambiguous handoffs block archive completion', $rejects(fn() => $engine->action($plan['id'], $plan['revision'], 'archive', $now))
    && $store->plan($plan['id'])['status'] === 'paused' && $store->run($pending['id'])['state'] === 'claimed');
$plan = $store->plan($plan['id']);
$check('Unresolved handoffs block deletion', $rejects(fn() => $engine->action($plan['id'], $plan['revision'], 'delete', $now)));
$plan = $store->plan($plan['id']);
$plan = $engine->action($plan['id'], $plan['revision'], 'resolve', $now);
$plan = $engine->action($plan['id'], $plan['revision'], 'delete', $now);
$check('Explicitly closed handoffs retain their reservation after deletion', $plan['status'] === 'deleted'
    && $store->run($pending['id'])['state'] === 'closed');
$plan = $engine->save($source, 0, 0);
$now = new DateTimeImmutable('2027-01-18 18:29 Europe/Berlin');
$plan = $engine->action($plan['id'], $plan['revision'], 'review', $now);
$plan = $engine->action($plan['id'], $plan['revision'], 'enable', $now);
$now = $now->modify('+1 minute');
$engine->tick($now);
$run = $store->history($plan['id'])[0];
$blockedInsideWorker = false;
$archiveDuringBatch = static function (array $subscribers, $mail) use ($run, $engine, $store, $now, &$blockedInsideWorker): array {
    if ((int)$mail->getId() === $run['external_id']) {
        $current = $store->plan($run['plan_id']);
        try {
            $engine->action($current['id'], $current['revision'], 'archive', $now);
        } catch (RuntimeException $error) {
            $blockedInsideWorker = str_contains($error->getMessage(), 'Versandpaket');
        }
    }
    return [];
};
add_filter('mailpoet_sending_queue_subscribers_to_process', $archiveDuringBatch, 200, 2);
$container->get(MailPoet\Cron\Workers\SendingQueue\SendingQueue::class)->process();
remove_filter('mailpoet_sending_queue_subscribers_to_process', $archiveDuringBatch, 200);
$check('Real worker blocks archive completion after recipient selection', $blockedInsideWorker && $store->plan($plan['id'])['status'] === 'paused');
$plan = $store->plan($plan['id']);
$plan = $engine->action($plan['id'], $plan['revision'], 'archive', $now);
$check('Archive succeeds only after the real worker releases its batch', $plan['status'] === 'archived' && !$store->pending($plan['id']));
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
