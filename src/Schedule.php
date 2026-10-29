<?php
declare(strict_types=1);

namespace Starclock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Schedule
{
    public static function validate(array $input): array
    {
        $day = filter_var($input['weekday'] ?? null, FILTER_VALIDATE_INT);
        $time = (string)($input['time'] ?? '');
        $zone = (string)($input['timezone'] ?? '');
        $start = (string)($input['start_date'] ?? '');
        $window = filter_var($input['window_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($day < 1 || $day > 7 || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new InvalidArgumentException('Bitte einen Wochentag und eine gültige Uhrzeit wählen.');
        }
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true) && $zone !== 'UTC') {
            throw new InvalidArgumentException('Bitte eine gültige Zeitzone wählen.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $start) {
            throw new InvalidArgumentException('Bitte ein gültiges Startdatum wählen.');
        }
        if (!in_array($window, [15, 60, 180, 360], true)) {
            throw new InvalidArgumentException('Bitte ein verfügbares Versandfenster wählen.');
        }
        return ['weekday' => $day, 'time' => $time, 'timezone' => $zone, 'start_date' => $start, 'window_minutes' => $window];
    }

    public static function onDate(array $plan, string $date): ?DateTimeImmutable
    {
        $zone = new DateTimeZone($plan['timezone']);
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $plan['time'], new DateTimeZone('UTC'));
        if (!$wall || $wall->format('Y-m-d') !== $date || (int)$wall->format('N') !== (int)$plan['weekday'] || $date < $plan['start_date']) {
            return null;
        }
        $offsets = [$zone->getOffset($wall)];
        foreach ($zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = $wall->setTimestamp($wall->getTimestamp() - $offset)->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i') === $date . ' ' . $plan['time']) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        ksort($matches);
        return $matches ? reset($matches) : null;
    }

    public static function due(array $plan, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $local = $now->setTimezone(new DateTimeZone($plan['timezone']));
        $due = self::onDate($plan, $local->format('Y-m-d'));
        $notBefore = (int)($plan['not_before'] ?? 0);
        return $due && $due->getTimestamp() >= $notBefore && $now >= $due && $now < self::expires($plan, $due) ? $due : null;
    }

    public static function expires(array $plan, DateTimeImmutable $due): DateTimeImmutable
    {
        $windowEnd = $due->setTimestamp($due->getTimestamp() + (int)$plan['window_minutes'] * 60);
        $midnight = $due->modify('tomorrow')->setTime(0, 0);
        return $windowEnd < $midnight ? $windowEnd : $midnight;
    }

    public static function next(array $plan, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $cursor = $now->setTimezone(new DateTimeZone($plan['timezone']))->setTime(12, 0);
        if ((int)($plan['not_before'] ?? 0) > $now->getTimestamp()) {
            $cursor = $cursor->setTimestamp((int)$plan['not_before'])->setTime(12, 0);
        }
        if ($cursor->format('Y-m-d') < $plan['start_date']) {
            $cursor = new DateTimeImmutable($plan['start_date'] . ' 12:00', new DateTimeZone($plan['timezone']));
        }
        for ($i = 0; $i < 22; $i++, $cursor = $cursor->modify('+1 day')) {
            $candidate = self::onDate($plan, $cursor->format('Y-m-d'));
            if ($candidate && $candidate > $now && $candidate->getTimestamp() >= (int)($plan['not_before'] ?? 0)) {
                return $candidate;
            }
        }
        return null;
    }
}
