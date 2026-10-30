<?php
declare(strict_types=1);

require_once __DIR__ . '/isolation.php';
starclock_require_isolation();
require_once __DIR__ . '/../src/load.php';

$store = new Starclock\Store($GLOBALS['wpdb']);
$engine = new Starclock\Engine($store, ['mailpoet' => new Starclock\MailPoetAdapter()], WP_HOME, true);
$source = $store->plans()[0];
$source['title'] = 'Synthetic concurrent handoff';
$plan = $engine->save($source, 0, 0);
$now = new DateTimeImmutable('2026-12-21 18:29 Europe/Berlin');
$plan = $engine->action($plan['id'], $plan['revision'], 'review', $now);
$plan = $engine->action($plan['id'], $plan['revision'], 'enable', $now);
echo json_encode(['plan_id' => $plan['id']]) . "\n";
