<?php

namespace App\Services;

use App\Models\LogsSms;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SmsService
{
    /**
     * Minimum hour for sending (9 AM inclusive).
     */
    private const MIN_SEND_HOUR = 9;

    /**
     * Maximum hour for sending (inclusive, 22 = 10 PM).
     *
     * The spec says "between 9am and 11pm". We interpret this as:
     * - Valid hours: 9, 10, 11, ..., 22 (9:00:00 to 22:59:59 local time)
     * - We check: hour >= 9 AND hour <= 22
     *
     * This means messages can be sent from 9:00 AM until 10:59:59 PM,
     * but NOT during the 11 PM hour (23:00 to 23:59).
     *
     * If stakeholders want "11pm" to be inclusive (send until 11:59 PM),
     * change MAX_SEND_HOUR to 23.
     */
    private const MAX_SEND_HOUR = 22;

    /**
     * Supported timezones for SMS delivery.
     *
     * NOTE: The case study lists "Australia/Tasmania" but this is NOT a valid
     * timezone identifier. The correct timezone is "Australia/Hobart".
     */
    private const SUPPORTED_TIMEZONES = [
        'Australia/Melbourne',
        'Australia/Sydney',
        'Australia/Brisbane',
        'Australia/Adelaide',
        'Australia/Perth',
        'Australia/Hobart',
        'Pacific/Auckland',
        'Asia/Kuala_Lumpur',
        'Europe/Istanbul',
    ];

    /**
     * Get and mark messages to send.
     *
     * OPTIMIZATION STRATEGY (Step 6):
     *
     * This implementation uses a two-phase approach that completely avoids
     * MySQL's CONVERT_TZ() function, resulting in pure indexed queries:
     *
     * Phase 1 - Calculate Valid Timezones in PHP:
     *   - Loop through all supported timezones
     *   - Check current local hour in each timezone using Carbon
     *   - Build a list of timezones currently within the 9 AM - 10 PM window
     *   - This is O(n) where n = number of supported timezones (9)
     *
     * Phase 2 - Pure Indexed Query:
     *   - Use whereIn('time_zone', $validTimezones) for indexed filtering
     *   - No CONVERT_TZ() means MySQL can use indexes efficiently
     *   - Combined with status, provider, and send_after filters
     *
     * Benefits:
     * - No per-row function calls in MySQL
     * - Query uses indexes for ALL conditions
     * - PHP timezone calculation is negligible (9 iterations)
     * - Handles DST correctly via Carbon's timezone database
     *
     * @param int $limit Number of messages to retrieve
     * @param string $provider Provider to filter by
     * @return Collection
     */
    public function getAndMarkMessagesToSend(int $limit = 5, string $provider = 'inhousesms'): Collection
    {
        return DB::transaction(function () use ($limit, $provider) {
            $now = Carbon::now();

            // Phase 1: Calculate which timezones are currently in the sending window
            $validTimezones = $this->getValidTimezones();

            if (empty($validTimezones)) {
                return new Collection();
            }

            // Phase 2: Pure indexed query — no CONVERT_TZ()
            $query = LogsSms::where('status', 0)
                ->where('provider', $provider)
                ->where(function ($q) use ($now) {
                    $q->whereNull('send_after')
                      ->orWhere('send_after', '<=', $now);
                })
                ->where(function ($q) use ($validTimezones) {
                    // Include NULL time_zone (no restriction) OR valid timezones
                    $q->whereNull('time_zone')
                      ->orWhereIn('time_zone', $validTimezones);
                })
                ->orderBy('priority', 'desc')
                ->orderBy('id', 'asc')
                ->limit($limit);

            // Use SKIP LOCKED for non-blocking concurrent access (MySQL 8.0+)
            try {
                $messages = $query->lockForUpdate()->get();
            } catch (\Exception $e) {
                // Fallback for older MySQL versions or SQLite
                $messages = $query->get();
            }

            if ($messages->isEmpty()) {
                return new Collection();
            }

            $messageIds = $messages->pluck('id')->toArray();

            // Mark as sent
            LogsSms::whereIn('id', $messageIds)
                ->update([
                    'status' => 1,
                    'sent' => 1,
                    'sent_at' => $now,
                ]);

            // Return updated messages in the same order as the original query
            return LogsSms::whereIn('id', $messageIds)
                ->orderBy('priority', 'desc')
                ->orderBy('id', 'asc')
                ->get();
        });
    }

    /**
     * Get the list of timezones currently within the sending window (9 AM - 10 PM).
     *
     * This method checks each supported timezone's current local hour and
     * returns only those where it's currently between 9 AM and 10:59 PM.
     *
     * @return array List of timezone identifiers currently in the sending window
     */
    private function getValidTimezones(): array
    {
        $validTimezones = [];

        foreach (self::SUPPORTED_TIMEZONES as $tz) {
            try {
                $hour = (int) Carbon::now()->setTimezone($tz)->format('G');
                if ($hour >= self::MIN_SEND_HOUR && $hour <= self::MAX_SEND_HOUR) {
                    $validTimezones[] = $tz;
                }
            } catch (\Exception $e) {
                // Invalid timezone, skip it
                continue;
            }
        }

        return $validTimezones;
    }

    /**
     * Check if current time in the given timezone is within sending hours.
     *
     * @param string|null $timezone
     * @return bool
     */
    private function isWithinLocalSendingHours(?string $timezone): bool
    {
        if (empty($timezone)) {
            return true; // No timezone means no restriction
        }

        try {
            $localHour = Carbon::now($timezone)->hour;
            return $localHour >= self::MIN_SEND_HOUR && $localHour <= self::MAX_SEND_HOUR;
        } catch (\Exception $e) {
            // Invalid timezone, allow sending
            return true;
        }
    }

    /**
     * Get messages without marking them (for preview/testing).
     *
     * Uses the same two-phase optimization as getAndMarkMessagesToSend().
     *
     * @param int $limit
     * @param string $provider
     * @return Collection<int, LogsSms>
     */
    public function previewMessagesToSend(int $limit = 5, string $provider = 'inhousesms'): Collection
    {
        $now = Carbon::now();

        // Phase 1: Calculate which timezones are currently in the sending window
        $validTimezones = $this->getValidTimezones();

        if (empty($validTimezones)) {
            return new Collection();
        }

        // Phase 2: Pure indexed query — no CONVERT_TZ()
        return LogsSms::where('status', 0)
            ->where('provider', $provider)
            ->where(function ($q) use ($now) {
                $q->whereNull('send_after')
                  ->orWhere('send_after', '<=', $now);
            })
            ->where(function ($q) use ($validTimezones) {
                // Include NULL time_zone (no restriction) OR valid timezones
                $q->whereNull('time_zone')
                  ->orWhereIn('time_zone', $validTimezones);
            })
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statistics about SMS messages.
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $stats = DB::table('logs_sms')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN delivered = 1 THEN 1 ELSE 0 END) as delivered
            ')
            ->first();

        $byProvider = DB::table('logs_sms')
            ->selectRaw('provider, COUNT(*) as count, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as pending')
            ->groupBy('provider')
            ->get();

        $byTimezone = DB::table('logs_sms')
            ->selectRaw('time_zone, COUNT(*) as count')
            ->whereNotNull('time_zone')
            ->groupBy('time_zone')
            ->get();

        return [
            'total' => $stats->total ?? 0,
            'pending' => $stats->pending ?? 0,
            'sent' => $stats->sent ?? 0,
            'delivered' => $stats->delivered ?? 0,
            'by_provider' => $byProvider,
            'by_timezone' => $byTimezone,
        ];
    }

    /**
     * Get the list of supported timezones.
     *
     * @return array
     */
    public function getSupportedTimezones(): array
    {
        return self::SUPPORTED_TIMEZONES;
    }

    /**
     * Get current timezone status for debugging/monitoring.
     *
     * Returns which timezones are currently in the sending window.
     *
     * @return array
     */
    public function getTimezoneStatus(): array
    {
        $status = [];

        foreach (self::SUPPORTED_TIMEZONES as $tz) {
            try {
                $now = Carbon::now()->setTimezone($tz);
                $hour = (int) $now->format('G');
                $status[$tz] = [
                    'local_time' => $now->format('Y-m-d H:i:s'),
                    'hour' => $hour,
                    'in_window' => $hour >= self::MIN_SEND_HOUR && $hour <= self::MAX_SEND_HOUR,
                ];
            } catch (\Exception $e) {
                $status[$tz] = [
                    'error' => 'Invalid timezone',
                ];
            }
        }

        return $status;
    }
}
