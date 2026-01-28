<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StationSession extends Model
{
    protected $table = 'ww_pos_station_sessions';

    protected $primaryKey = 'fldId';

    public $timestamps = false;

    protected $fillable = [
        'fldStationId',
        'fldUsername',
        'fldLineLogId',
        'fldToken',
        'fldAbilities',
        'fldOpenedAt',
        'fldLastActivityAt',
        'fldSyncStatus',
        'fldClosedAt',
    ];

    protected $casts = [
        'fldAbilities' => 'array',
        'fldOpenedAt' => 'datetime',
        'fldLastActivityAt' => 'datetime',
        'fldClosedAt' => 'datetime',
    ];

    protected $hidden = [
        'fldToken',
    ];

    /**
     * Get the station for this session
     */
    public function station()
    {
        return $this->belongsTo(Station::class, 'fldStationId', 'fldId');
    }

    /**
     * Get the user for this session
     */
    public function user()
    {
        return User::where('fldUsername', $this->fldUsername)->first();
    }

    /**
     * Get the line log for this session
     */
    public function lineLog()
    {
        return $this->belongsTo(LineLog::class, 'fldLineLogId', 'fldId');
    }

    /**
     * Generate a new token
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Find session by token
     */
    public static function findByToken(?string $token): ?self
    {
        if (!$token) {
            return null;
        }

        return self::where('fldToken', $token)
            ->where('fldSyncStatus', 'active')
            ->whereNull('fldClosedAt')
            ->first();
    }

    /**
     * Create a new session for a user on a station
     */
    public static function createForUser(User $user, Station $station, array $abilities = []): self
    {
        return self::create([
            'fldStationId' => $station->fldId,
            'fldUsername' => $user->fldUsername,
            'fldToken' => self::generateToken(),
            'fldAbilities' => $abilities,
            'fldOpenedAt' => now(),
            'fldLastActivityAt' => now(),
            'fldSyncStatus' => 'active',
        ]);
    }

    /**
     * Revoke this session
     */
    public function revoke(): void
    {
        $this->update([
            'fldSyncStatus' => 'abandoned',
            'fldClosedAt' => now(),
        ]);
    }

    /**
     * Revoke all other sessions for this user
     */
    public static function revokeOtherSessionsForUser(string $username, int $exceptSessionId = null): int
    {
        $query = self::where('fldUsername', $username)
            ->where('fldSyncStatus', 'active')
            ->whereNull('fldClosedAt');

        if ($exceptSessionId) {
            $query->where('fldId', '!=', $exceptSessionId);
        }

        return $query->update([
            'fldSyncStatus' => 'abandoned',
            'fldClosedAt' => now(),
        ]);
    }

    /**
     * Update last activity timestamp
     */
    public function updateLastActivity(): bool
    {
        return $this->update(['fldLastActivityAt' => now()]);
    }

    /**
     * Check if session has a specific ability
     */
    public function hasAbility(string $ability): bool
    {
        $abilities = $this->fldAbilities ?? [];

        // Check for exact match
        if (in_array($ability, $abilities)) {
            return true;
        }

        // Check for wildcard (e.g., "line:*" matches "line:10")
        foreach ($abilities as $a) {
            if (str_contains($a, ':*')) {
                $prefix = str_replace(':*', ':', $a);
                if (str_starts_with($ability, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
