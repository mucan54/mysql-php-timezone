<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $parent_table
 * @property int|null $parent_id
 * @property string $phone
 * @property string $message
 * @property int $priority
 * @property string|null $device_id
 * @property float $cost
 * @property int $sent
 * @property int $delivered
 * @property string|null $error
 * @property string $provider
 * @property int $status
 * @property Carbon|null $fetched_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $send_after
 * @property string|null $time_zone
 */
class LogsSms extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'logs_sms';

    /**
     * Indicates if the model should be timestamped.
     * Using custom timestamp columns with MySQL defaults.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'parent_table',
        'parent_id',
        'phone',
        'message',
        'priority',
        'device_id',
        'cost',
        'sent',
        'delivered',
        'error',
        'provider',
        'status',
        'fetched_at',
        'sent_at',
        'delivered_at',
        'send_after',
        'time_zone',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'fetched_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'send_after' => 'datetime',
        'status' => 'integer',
        'sent' => 'integer',
        'delivered' => 'integer',
        'priority' => 'integer',
        'cost' => 'float',
    ];

    /**
     * Valid timezone values for SMS messages.
     *
     * WARNING: The case study specifies "Australia/Tasmania" but this is NOT a valid
     * timezone in MySQL's tz tables. Using CONVERT_TZ() with "Australia/Tasmania"
     * returns NULL, which would silently drop those rows from query results.
     *
     * The correct timezone for Tasmania is "Australia/Hobart".
     *
     * This array contains the CORRECTED timezones for MySQL compatibility.
     */
    public const VALID_TIMEZONES = [
        'Australia/Melbourne',
        'Australia/Sydney',
        'Australia/Brisbane',
        'Australia/Adelaide',
        'Australia/Perth',
        'Australia/Hobart', // Tasmania - NOTE: Original spec used "Australia/Tasmania" which is INVALID
        'Pacific/Auckland',
        'Asia/Kuala_Lumpur',
        'Europe/Istanbul',
    ];

    /**
     * Timezones as specified in the original case study.
     * WARNING: "Australia/Tasmania" is NOT valid in MySQL timezone tables.
     */
    public const CASE_STUDY_TIMEZONES = [
        'Australia/Melbourne',
        'Australia/Sydney',
        'Australia/Brisbane',
        'Australia/Adelaide',
        'Australia/Perth',
        'Australia/Tasmania', // INVALID - should be Australia/Hobart
        'Pacific/Auckland',
        'Asia/Kuala_Lumpur',
        'Europe/Istanbul',
    ];

    /**
     * Mapping from invalid/legacy timezone names to valid MySQL timezone names.
     */
    public const TIMEZONE_CORRECTIONS = [
        'Australia/Tasmania' => 'Australia/Hobart',
    ];

    /**
     * Valid provider values.
     */
    public const VALID_PROVIDERS = [
        'inhousesms',
        'wholesalesms',
        'prowebsms',
        'onverify',
        'inhousesms-nz',
        'inhousesms-my',
        'inhousesms-au',
        'inhousesms-au-marketing',
        'inhousesms-nz-marketing',
    ];

    /**
     * Valid parent table values.
     */
    public const VALID_PARENT_TABLES = [
        'cart_order',
        'reservation',
        'marketing_campaign',
    ];

    /**
     * Get the local time for this SMS based on its timezone.
     */
    public function getLocalTime(): ?Carbon
    {
        if (!$this->time_zone) {
            return null;
        }

        return Carbon::now($this->time_zone);
    }

    /**
     * Check if current local time is within sending hours (9am - 11pm).
     * Valid hours: 9, 10, 11, ..., 22 (sending allowed from 9:00:00 to 22:59:59 local time).
     */
    public function isWithinSendingHours(): bool
    {
        $localTime = $this->getLocalTime();

        if (!$localTime) {
            return true; // No timezone restriction
        }

        $hour = $localTime->hour;
        return $hour >= 9 && $hour < 23;
    }

    /**
     * Scope for pending messages (status = 0).
     */
    public function scopePending($query)
    {
        return $query->where('status', 0);
    }

    /**
     * Scope for specific provider.
     */
    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope for messages ready to send (send_after is in the past or null).
     */
    public function scopeReadyToSend($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('send_after')
              ->orWhere('send_after', '<=', now());
        });
    }

    /**
     * Mark this SMS as sent.
     */
    public function markAsSent(): bool
    {
        $this->status = 1;
        $this->sent = 1;
        $this->sent_at = now();

        return $this->save();
    }
}
