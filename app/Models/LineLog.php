<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineLog extends Model
{
    protected $table = 'ww_pos_log_lines';

    protected $primaryKey = 'fldId';

    public $timestamps = false;

    protected $fillable = [
        'fldMealType',
        'fldLineNum',
        'fldLineDate',
        'fldPlateCount',
        'fldMealPlateCount',
        'fldMealDescription',
        'fldPrepDate',
        'fldPrepStatus',
        'fldOpenDate',
        'fldOpenUser',
        'fldCloseDate',
        'fldCloseUser',
        'fldCloserIsAdmin',
        'fldProcessDate',
        'fldProcessStatus',
        'fldProcessUser',
    ];

    protected $casts = [
        'fldLineDate' => 'date',
        'fldPrepDate' => 'datetime',
        'fldOpenDate' => 'datetime',
        'fldCloseDate' => 'datetime',
        'fldProcessDate' => 'datetime',
        'fldPrepStatus' => 'boolean',
        'fldProcessStatus' => 'boolean',
        'fldCloserIsAdmin' => 'boolean',
    ];

    /**
     * Get the line definition
     */
    public function line()
    {
        return Line::where('fldMealType', $this->fldMealType)
            ->where('fldLineNum', $this->fldLineNum)
            ->first();
    }

    /**
     * Get status string
     */
    public function getStatusAttribute(): string
    {
        if ($this->fldCloseDate) {
            return 'closed';
        }
        if ($this->fldOpenDate) {
            return 'open';
        }
        return 'not_opened';
    }

    /**
     * Check if line is open
     */
    public function isOpen(): bool
    {
        return $this->fldOpenDate && !$this->fldCloseDate;
    }

    /**
     * Check if line is closed
     */
    public function isClosed(): bool
    {
        return (bool) $this->fldCloseDate;
    }

    /**
     * Find or create log for today
     */
    public static function findOrCreateForToday(string $mealType, int $lineNum): self
    {
        $today = now()->toDateString();

        $log = self::where('fldMealType', $mealType)
            ->where('fldLineNum', $lineNum)
            ->where('fldLineDate', $today)
            ->first();

        if ($log) {
            return $log;
        }

        return self::create([
            'fldMealType' => $mealType,
            'fldLineNum' => $lineNum,
            'fldLineDate' => $today,
            'fldPlateCount' => 0,
            'fldMealPlateCount' => 0,
        ]);
    }

    /**
     * Open the line
     */
    public function open(int $userId): bool
    {
        if ($this->fldCloseDate) {
            return false; // Already closed, cannot reopen
        }

        if ($this->fldOpenDate) {
            return true; // Already open
        }

        return $this->update([
            'fldOpenDate' => now(),
            'fldOpenUser' => $userId,
        ]);
    }

    /**
     * Get active station sessions for this line log
     */
    public function activeSessions()
    {
        return StationSession::where('fldLineLogId', $this->fldId)
            ->where('fldSyncStatus', 'active')
            ->whereNull('fldClosedAt')
            ->get();
    }

    /**
     * Check if all sessions have synced (for closure)
     */
    public function allSessionsSynced(): bool
    {
        return StationSession::where('fldLineLogId', $this->fldId)
            ->where('fldSyncStatus', '!=', 'synced')
            ->whereNull('fldClosedAt')
            ->count() === 0;
    }

    /**
     * Get sync info for this line log
     */
    public function getSyncInfo(): array
    {
        $sessions = StationSession::where('fldLineLogId', $this->fldId)->get();

        $total = $sessions->count();
        $synced = $sessions->where('fldSyncStatus', 'synced')->count();
        $active = $sessions->where('fldSyncStatus', 'active')->count();
        $syncing = $sessions->where('fldSyncStatus', 'syncing')->count();
        $abandoned = $sessions->where('fldSyncStatus', 'abandoned')->count();

        // Ready to close if: line is open, not already closed, and no active/syncing sessions
        $readyToClose = $this->isOpen() && ($active + $syncing) === 0;

        return [
            'totalStations' => $total,
            'syncedStations' => $synced,
            'activeStations' => $active,
            'syncingStations' => $syncing,
            'abandonedStations' => $abandoned,
            'readyToClose' => $readyToClose,
        ];
    }
}
