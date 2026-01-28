<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    protected $table = 'ww_pos_stations';

    protected $primaryKey = 'fldId';

    public $timestamps = false;

    protected $fillable = [
        'fldDeviceId',
        'fldMacAddress',
        'fldBrowser',
        'fldIsPrivate',
        'fldIpAddress',
        'fldFirstSeenAt',
        'fldLastSeenAt',
    ];

    protected $casts = [
        'fldFirstSeenAt' => 'datetime',
        'fldLastSeenAt' => 'datetime',
        'fldIsPrivate' => 'boolean',
    ];

    /**
     * Get all sessions for this station
     */
    public function sessions()
    {
        return $this->hasMany(StationSession::class, 'fldStationId', 'fldId');
    }

    /**
     * Get active sessions for this station
     */
    public function activeSessions()
    {
        return $this->sessions()->where('fldSyncStatus', 'active');
    }

    /**
     * Find or create a station by device ID, browser, and private mode
     */
    public static function findOrCreateByDevice(
        string $deviceId,
        string $browser,
        bool $isPrivate = false,
        ?string $macAddress = null,
        ?string $ipAddress = null
    ): self {
        $station = self::where('fldDeviceId', $deviceId)
            ->where('fldBrowser', $browser)
            ->where('fldIsPrivate', $isPrivate)
            ->first();

        if ($station) {
            // Update IP, MAC (if provided), and last seen
            $updateData = [
                'fldIpAddress' => $ipAddress,
                'fldLastSeenAt' => now(),
            ];

            // Only update MAC if provided (don't overwrite with null)
            if ($macAddress) {
                $updateData['fldMacAddress'] = $macAddress;
            }

            $station->update($updateData);
            return $station;
        }

        // Create new station
        return self::create([
            'fldDeviceId' => $deviceId,
            'fldMacAddress' => $macAddress,
            'fldBrowser' => $browser,
            'fldIsPrivate' => $isPrivate,
            'fldIpAddress' => $ipAddress,
            'fldFirstSeenAt' => now(),
            'fldLastSeenAt' => now(),
        ]);
    }
}
