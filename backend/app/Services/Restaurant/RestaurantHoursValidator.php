<?php

namespace App\Services\Restaurant;

use Illuminate\Validation\ValidationException;

class RestaurantHoursValidator
{
    /** @var list<string> */
    private array $allowedServices = ['all', 'pickup', 'restaurant_delivery'];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function validateRegularHours(array $rows): void
    {
        $errors = [];
        $grouped = [];

        foreach ($rows as $index => $row) {
            $service = $row['service_type'] ?? 'all';
            if (! in_array($service, $this->allowedServices, true)) {
                $errors["hours.{$index}.service_type"] = ['Unsupported service type.'];
                continue;
            }

            $day = (int) $row['day_of_week'];
            $isClosed = (bool) $row['is_closed'];

            if ($isClosed) {
                if (! empty($row['opens_at']) || ! empty($row['closes_at'])) {
                    $errors["hours.{$index}.is_closed"] = ['Closed days cannot include opening times.'];
                }
                continue;
            }

            if (empty($row['opens_at']) || empty($row['closes_at'])) {
                $errors["hours.{$index}.opens_at"] = ['Open periods require both open and close times.'];
                continue;
            }

            if ($row['opens_at'] === $row['closes_at']) {
                $errors["hours.{$index}.closes_at"] = ['Period length must be greater than zero.'];
                continue;
            }

            $key = "{$day}|{$service}";
            $grouped[$key][] = [
                'index' => $index,
                'opens' => $this->toMinutes($row['opens_at']),
                'closes' => $this->toMinutes($row['closes_at']),
            ];
        }

        foreach ($grouped as $periods) {
            $this->assertNoDuplicates($periods, $errors);
            $this->assertNoSameDayOverlap($periods, $errors);
        }

        $this->assertNoOvernightNextDayOverlap($grouped, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     */
    public function validateSpecialHour(array $row, array $existing = [], ?int $ignoreId = null): void
    {
        $errors = [];
        $date = $row['date'] ?? null;
        if ($date) {
            foreach ($existing as $other) {
                if ($ignoreId && ($other['id'] ?? null) === $ignoreId) {
                    continue;
                }
                if (($other['date'] ?? null) === $date) {
                    $errors['date'] = ['Special hours already exist for this date.'];
                    break;
                }
            }
        }

        if (! ($row['is_closed'] ?? false)) {
            if (empty($row['opens_at']) || empty($row['closes_at'])) {
                $errors['opens_at'] = ['Special open periods require both times.'];
            } elseif ($row['opens_at'] === $row['closes_at']) {
                $errors['closes_at'] = ['Period length must be greater than zero.'];
            }
        } elseif (! empty($row['opens_at']) || ! empty($row['closes_at'])) {
            $errors['is_closed'] = ['Closed special hours cannot include times.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array{index: int, opens: int, closes: int}>  $periods
     * @param  array<string, list<string>>  $errors
     */
    private function assertNoDuplicates(array $periods, array &$errors): void
    {
        $seen = [];
        foreach ($periods as $p) {
            $sig = "{$p['opens']}-{$p['closes']}";
            if (isset($seen[$sig])) {
                $errors["hours.{$p['index']}.opens_at"] = ['Duplicate period.'];
            }
            $seen[$sig] = true;
        }
    }

    /**
     * @param  array<int, array{index: int, opens: int, closes: int}>  $periods
     * @param  array<string, list<string>>  $errors
     */
    private function assertNoSameDayOverlap(array $periods, array &$errors): void
    {
        $ranges = [];
        foreach ($periods as $p) {
            [$start, $end] = $this->expandRange($p['opens'], $p['closes']);
            if ($end <= 24 * 60) {
                $ranges[] = ['start' => $start, 'end' => $end, 'index' => $p['index']];
            } else {
                $ranges[] = ['start' => $start, 'end' => 24 * 60, 'index' => $p['index']];
            }
        }

        usort($ranges, fn ($a, $b) => $a['start'] <=> $b['start']);
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i]['start'] < $ranges[$i - 1]['end']) {
                $errors["hours.{$ranges[$i]['index']}.opens_at"] = ['Overlapping period for the same service.'];
            }
        }
    }

    /**
     * @param  array<string, array<int, array{index: int, opens: int, closes: int}>>  $grouped
     * @param  array<string, list<string>>  $errors
     */
    private function assertNoOvernightNextDayOverlap(array $grouped, array &$errors): void
    {
        foreach ($grouped as $key => $periods) {
            [$dayStr, $service] = explode('|', $key, 2);
            $day = (int) $dayStr;
            $nextKey = (($day + 1) % 7).'|'.$service;
            if (! isset($grouped[$nextKey])) {
                continue;
            }

            foreach ($periods as $p) {
                if ($p['closes'] <= $p['opens']) {
                    $spillEnd = $p['closes'];
                    foreach ($grouped[$nextKey] as $next) {
                        if ($next['opens'] < $spillEnd) {
                            $errors["hours.{$next['index']}.opens_at"] = ['Overnight period overlaps the next day.'];
                        }
                    }
                }
            }
        }
    }

    /** @return array{0: int, 1: int} */
    private function expandRange(int $opens, int $closes): array
    {
        if ($closes > $opens) {
            return [$opens, $closes];
        }

        return [$opens, $closes + 24 * 60];
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
