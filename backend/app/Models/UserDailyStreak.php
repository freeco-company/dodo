<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SPEC-daily-login-streak — per-App 每日連續登入 streak.
 *
 * @property int $id
 * @property int $user_id
 * @property int $current_streak
 * @property int $longest_streak
 * @property ?Carbon $last_login_date
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UserDailyStreak extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'current_streak',
        'longest_streak',
        'last_login_date',
    ];

    protected $casts = [
        'last_login_date' => 'date',
        'current_streak' => 'integer',
        'longest_streak' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
