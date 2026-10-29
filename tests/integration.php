<?php
declare(strict_types=1);

if (!defined('DB_NAME') || !str_starts_with(DB_NAME, 'starclock_test_') || wp_get_environment_type() !== 'local'
    || !defined('WP_HOME') || WP_HOME !== 'http://starclock-test.invalid' || function_exists('mail')) {
    throw new RuntimeException('This test requires an isolated synthetic WordPress database and blocked transport.');
}
$probe = @fsockopen('tcp://1.1.1.1', 443, $errno, $message, 1);
if ($probe || $errno !== 1) {
    if ($probe) {
        fclose($probe);
    }
    throw new RuntimeException('OS network denial is required.');
}
require_once __DIR__ . '/../src/load.php';

use Starclock\{Store, Engine, MailPoetAdapter, Admin};
use MailPoet\DI\ContainerWrapper;
use MailPoet\Entities\{SubscriberEntity, SegmentEntity, SubscriberSegmentEntity};
use MailPoet\Newsletter\{NewsletterSaveController, NewslettersRepository};
use MailPoet\Settings\SettingsController;
use MailPoetVendor\Doctrine\ORM\EntityManager;

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    if (!$ok) {
        throw new RuntimeException($name);
    }
};
$store = new Store($GLOBALS['wpdb']);
$store->install();
$adapter = new MailPoetAdapter();
$engine = new Engine($store, ['mailpoet' => $adapter], WP_HOME, true);
$tag = 'sc_' . bin2hex(random_bytes(4));
$c = ContainerWrapper::getInstance();
$em = $c->get(EntityManager::class);
$c->get(SettingsController::class)->set('sender', ['name' => 'Synthetic', 'address' => 'sender@example.invalid']);
$c->get(SettingsController::class)->set('mta', ['method' => 'PHPMail']);
$c->get(SettingsController::class)->set('cron_trigger', ['method' => 'WordPress']);
$list = new SegmentEntity($tag, SegmentEntity::TYPE_DEFAULT, 'Synthetic test contacts');
$em->persist($list);
$em->flush();
$contacts = $memberships = [];
foreach (['subscribed', 'unconfirmed', 'unsubscribed', 'bounced', 'outside', 'removed_later'] as $key) {
    $subscriber = new SubscriberEntity();
    $subscriber->setEmail($tag . '-' . $key . '@example.invalid');
    $subscriber->setStatus(in_array($key, ['outside', 'removed_later'], true) ? 'subscribed' : $key);
    $em->persist($subscriber);
    if ($key !== 'outside') {
        $membership = new SubscriberSegmentEntity($list, $subscriber, 'subscribed');
        $em->persist($membership);
        $memberships[$key] = $membership;
    }
    $contacts[$key] = $subscriber;
}
$em->flush();
$save = $c->get(NewsletterSaveController::class);
$blank = (new MailPoet\Config\PopulatorData\Templates\NewsletterBlank1Column(WP_HOME . '/assets'))->get();
$body = json_decode($blank['body'], true, 512, JSON_THROW_ON_ERROR);
$body['content']['blocks'] = [['type' => 'text', 'text' => '<p>Synthetic reminder only.</p><p>[link:subscription_unsubscribe]</p>', 'styles' => ['block' => ['textAlign' => 'left']]]];
$master = $save->save(['type' => 'standard', 'subject' => 'Erinnerung an Dienstag', 'body' => json_encode($body),
    'sender_name' => 'Synthetic', 'sender_address' => 'sender@example.invalid', 'reply_to_name' => 'Synthetic', 'reply_to_address' => 'reply@example.invalid',
    'segments' => [['id' => $list->getId()]]]);
$input = ['title' => 'Dienstags-Erinnerung', 'adapter' => 'mailpoet', 'template_id' => $master->getId(), 'weekday' => 1,
    'time' => '18:30', 'timezone' => 'Europe/Berlin', 'start_date' => '2026-01-01', 'window_minutes' => 60];
$plan = $engine->save($input, 0, 0);
$id = $plan['id'];
$now = new DateTimeImmutable('2026-10-26 18:29 Europe/Berlin');
$observed = $eligible = [];
add_filter('mailpoet_sending_queue_subscribers_to_process', static function (array $subscribers, $mail) use ($engine, &$now, &$observed): array {
    $observed[$mail->getId()] = array_map(static fn($s) => $s->getId(), $subscribers);
    return $engine->permits('mailpoet', $mail->getId(), $now) ? $subscribers : [];
}, 100, 2);
add_filter('mailpoet_sending_queue_subscribers_to_process', static function (array $subscribers, $mail) use (&$eligible): array {
    $eligible[$mail->getId()] = count($subscribers);
    return [];
}, PHP_INT_MAX, 2);
$check('New plan is paused', $plan['status'] === 'paused' && !$plan['approved']);
$check('Paused tick creates nothing', $engine->tick($now) === [] && $store->history($id) === []);
$failed = false;
try {
    $engine->action($id, $plan['revision'], 'enable', $now);
} catch (RuntimeException) {
    $failed = true;
}
$check('No activation before review', $failed);
$plan = $engine->action($id, $plan['revision'], 'review', $now);
$check('Review captures sender and recipients without sending', $plan['status'] === 'ready' && count($plan['approved']['lists']) === 1 && !$store->history($id));
$wrongSite = new Engine($store, ['mailpoet' => $adapter], 'http://clone.invalid', true);
$failed = false;
try {
    $wrongSite->action($id, $plan['revision'], 'enable', $now);
} catch (RuntimeException) {
    $failed = true;
}
$check('Clone cannot activate source schedule', $failed);
$plan = $engine->action($id, $plan['revision'], 'enable', $now);
$check('Activation starts in future', $plan['status'] === 'active' && !$engine->tick($now));
$now = new DateTimeImmutable('2026-10-26 18:30 Europe/Berlin');
$result = $engine->tick($now);
$run = $store->history($id)[0];
$check('Native MailPoet handoff has receipt', $run['state'] === 'queued' && $run['external_id'] > 0 && !empty($run['receipt']));
$check('Repeated tick does not duplicate', $engine->tick($now) === [] && count($store->history($id)) === 1);
$copy = $c->get(NewslettersRepository::class)->findOneById($run['external_id']);
$check('Master unchanged and copy matches', $master->getStatus() === 'draft' && MailPoetAdapter::fingerprint($copy) === $plan['approved']['fingerprint']);
$queued = array_map('intval', $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare('SELECT subscriber_id FROM %i WHERE task_id=%d', $GLOBALS['wpdb']->prefix . 'mailpoet_scheduled_task_subscribers', $run['receipt'])));
sort($queued);
$expected = [$contacts['subscribed']->getId(), $contacts['removed_later']->getId()];
sort($expected);
$check('Only opted-in list contacts are queued', $queued === $expected);
$memberships['removed_later']->setStatus('unsubscribed');
$em->flush();
$worker = $c->get(MailPoet\Cron\Workers\SendingQueue\SendingQueue::class);
$worker->process();
$check('Worker rechecks opt-out before transport', ($eligible[$run['external_id']] ?? null) === 1 && $observed[$run['external_id']] === [$contacts['subscribed']->getId()]);
$check('Unowned MailPoet campaign is unaffected', $engine->permits('mailpoet', 2147483000, $now));
$plan = $store->plan($id);
$failed = false;
try {
    $engine->save($input, $id, $plan['revision'] - 1);
} catch (RuntimeException) {
    $failed = true;
}
$check('Stale admin form is rejected', $failed && $store->plan($id)['status'] === 'active');
$plan = $engine->action($id, $plan['revision'], 'skip', $now);
$check('Skip advances next occurrence', $engine->next($plan, $now)?->format('Y-m-d') === '2026-11-09');
$now = new DateTimeImmutable('2026-11-02 18:30 Europe/Berlin');
$check('Skipped occurrence produces no handoff', $engine->tick($now) === []);
$now = new DateTimeImmutable('2026-11-09 18:30 Europe/Berlin');
$engine->tick($now);
$run = $store->history($id)[0];
$check('Next week has independent receipt', $run['state'] === 'queued' && $run['occurrence'] === '2026-11-09');
$plan = $engine->action($id, $plan['revision'], 'pause', $now);
$check('Pause stops owned pending MailPoet queue', $adapter->deliveryState($run['external_id']) === 'paused' && !$engine->permits('mailpoet', $run['external_id'], $now));
$plan = $engine->action($id, $plan['revision'], 'review', $now);
$plan = $engine->action($id, $plan['revision'], 'enable', $now);
$master->setSubject('Changed after approval');
$em->flush();
$now = new DateTimeImmutable('2026-11-16 18:30 Europe/Berlin');
$result = $engine->tick($now);
$check('Changed master stops before handoff', $store->plan($id)['status'] === 'needs_review' && $store->history($id)[0]['occurrence'] !== '2026-11-16');
$plan = $store->plan($id);
$plan = $engine->action($id, $plan['revision'], 'review', $now);
$plan = $engine->action($id, $plan['revision'], 'enable', $now);
$interrupted = $store->claim($id, '2026-11-23', ['adapter' => 'mailpoet', 'created_at' => $now->getTimestamp()]);
$now = new DateTimeImmutable('2026-11-23 18:30 Europe/Berlin');
$engine->tick($now);
$check('Interrupted handoff is not retried', $store->plan($id)['status'] === 'needs_review' && $store->history($id)[0]['id'] === $interrupted['id']);
$plan = $store->plan($id);
$plan = $engine->action($id, $plan['revision'], 'resolve', $now);
$check('Explicit close retains occurrence reservation', $store->run($interrupted['id'])['state'] === 'closed' && $store->claim($id, '2026-11-23', ['adapter' => 'mailpoet']) === null);
$plan = $engine->action($id, $plan['revision'], 'review', $now);
$plan = $engine->action($id, $plan['revision'], 'enable', $now);
$now = new DateTimeImmutable('2026-11-30 18:30 Europe/Berlin');
$engine->tick($now);
$run = $store->history($id)[0];
$now = $now->modify('+61 minutes');
$check('Final guard blocks expired campaign', !$engine->permits('mailpoet', $run['external_id'], $now));
$engine->tick($now);
$check('Expired pending campaign is paused in MailPoet', $store->run($run['id'])['state'] === 'expired' && $adapter->deliveryState($run['external_id']) === 'paused');
$now = new DateTimeImmutable('2026-12-07 18:30 Europe/Berlin');
$engine->tick($now);
$run = $store->history($id)[0];
$engine->deactivate();
$check('Deactivation pauses owned queues before guard removal', $adapter->deliveryState($run['external_id']) === 'paused' && $store->plan($id)['status'] === 'paused');
wp_set_current_user(0);
$check('Anonymous user has no admin mutation rights', !Admin::authorized());
$sent = (int)$GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'mailpoet_statistics_newsletters');
$check('No newsletter sent', $sent === 0);
echo json_encode(['wordpress' => get_bloginfo('version'), 'mailpoet' => MAILPOET_VERSION, 'transport' => 'OS blocked', 'plan_id' => $id, 'checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
