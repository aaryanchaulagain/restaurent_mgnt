<?php

namespace App\Services\Reporting;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Normalized report date window in a resolved timezone.
 */
final class ReportDateRange
{
    public const PRESETS = [
        'today',
        'last_7_days',
        'last_30_days',
        'this_month',
        'previous_month',
        'custom',
    ];

    public const MAX_DAYS = 366;

    public function __construct(
        public readonly string $preset,
        public readonly Carbon $startAt,
        public readonly Carbon $endAt,
        public readonly string $timezone,
    ) {}

    /**
     * @param  array{range?: string, start?: string, end?: string}  $input
     */
    public static function fromRequest(array $input, string $timezone): self
    {
        $preset = strtolower(trim((string) ($input['range'] ?? 'last_30_days')));
        if (! in_array($preset, self::PRESETS, true)) {
            throw ValidationException::withMessages([
                'code' => ['REPORT_DATE_RANGE_INVALID'],
                'range' => ['Invalid report date range.'],
            ]);
        }

        try {
            $now = Carbon::now($timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'code' => ['REPORT_TIMEZONE_INVALID'],
                'timezone' => ['Invalid reporting timezone.'],
            ]);
        }

        if ($preset === 'custom') {
            if (empty($input['start']) || empty($input['end'])) {
                throw ValidationException::withMessages([
                    'code' => ['REPORT_DATE_RANGE_INVALID'],
                    'range' => ['Custom range requires start and end dates.'],
                ]);
            }
            try {
                $start = Carbon::parse($input['start'], $timezone)->startOfDay();
                $end = Carbon::parse($input['end'], $timezone)->endOfDay();
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'code' => ['REPORT_DATE_RANGE_INVALID'],
                    'range' => ['Custom range dates are invalid.'],
                ]);
            }
            if ($end->lt($start)) {
                throw ValidationException::withMessages([
                    'code' => ['REPORT_DATE_RANGE_INVALID'],
                    'range' => ['End date must be on or after start date.'],
                ]);
            }
            if ($start->diffInDays($end) > self::MAX_DAYS) {
                throw ValidationException::withMessages([
                    'code' => ['REPORT_DATE_RANGE_TOO_LARGE'],
                    'range' => ['Date range cannot exceed '.self::MAX_DAYS.' days.'],
                ]);
            }

            return new self($preset, $start, $end, $timezone);
        }

        [$start, $end] = match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'previous_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };

        return new self($preset, $start, $end, $timezone);
    }

    /** @return array{range: string, start_at: string, end_at: string, timezone: string, generated_at: string} */
    public function meta(): array
    {
        return [
            'range' => $this->preset,
            'start_at' => $this->startAt->toIso8601String(),
            'end_at' => $this->endAt->toIso8601String(),
            'timezone' => $this->timezone,
            'generated_at' => Carbon::now($this->timezone)->toIso8601String(),
        ];
    }

    public function startUtc(): Carbon
    {
        return $this->startAt->copy()->utc();
    }

    public function endUtc(): Carbon
    {
        return $this->endAt->copy()->utc();
    }
}
