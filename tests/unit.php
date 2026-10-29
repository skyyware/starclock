<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Schedule.php';

use Starclock\Schedule;

$checks = [];
$check = static function (string $label, bool $ok) use (&$checks): void {
    $checks[$label] = $ok;
    if (!$ok) {
        throw new RuntimeException($label);
    }
};
$base = Schedule::validate(['weekday' => 1, 'time' => '18:30', 'timezone' => 'Europe/Berlin', 'start_date' => '2026-01-01', 'window_minutes' => 60]);
foreach (['2026-03-23' => '17:30', '2026-03-30' => '16:30', '2026-10-19' => '16:30', '2026-10-26' => '17:30'] as $date => $time) {
    $now = new DateTimeImmutable("$date $time UTC");
    $check('Berlin DST ' . $date, Schedule::due($base, $now)?->format('Y-m-d') === $date);
    $check('Not early ' . $date, Schedule::due($base, $now->modify('-1 second')) === null);
}
$check('Window is exclusive at the end', Schedule::due($base, new DateTimeImmutable('2026-10-26 19:30 Europe/Berlin')) === null);
$check('Never catches up on Tuesday', Schedule::due($base, new DateTimeImmutable('2026-10-27 01:00 Europe/Berlin')) === null);
$check('Start date respected', Schedule::due(array_replace($base, ['start_date' => '2026-11-01']), new DateTimeImmutable('2026-10-26 18:30 Europe/Berlin')) === null);
$check('Enabling after due waits a week', Schedule::due($base + ['not_before' => strtotime('2026-10-26 18:31 Europe/Berlin')], new DateTimeImmutable('2026-10-26 18:32 Europe/Berlin')) === null);
$sunday = array_replace($base, ['weekday' => 7, 'time' => '02:30']);
$check('Nonexistent DST wall time skipped', Schedule::onDate($sunday, '2026-03-29') === null);
$check('Repeated DST time uses first occurrence', Schedule::onDate($sunday, '2026-10-25')?->format('c') === '2026-10-25T02:30:00+02:00');
$check('DST gap advances next week', Schedule::next($sunday, new DateTimeImmutable('2026-03-28 Europe/Berlin'))?->format('Y-m-d H:i') === '2026-04-05 02:30');
$late = array_replace($base, ['time' => '23:30', 'window_minutes' => 180]);
$check('Window capped at local midnight', Schedule::expires($late, Schedule::onDate($late, '2026-10-26'))->format('Y-m-d H:i') === '2026-10-27 00:00');
foreach ([['weekday' => 0], ['weekday' => 8], ['time' => '24:00'], ['time' => '9:5'], ['timezone' => '+02:00'], ['start_date' => '2026-02-30'], ['window_minutes' => 0]] as $bad) {
    $failed = false;
    try {
        Schedule::validate(array_replace($base, $bad));
    } catch (InvalidArgumentException) {
        $failed = true;
    }
    $check('Reject invalid ' . json_encode($bad), $failed);
}
echo json_encode(['checks' => $checks, 'passed' => count($checks)], JSON_PRETTY_PRINT) . "\n";
