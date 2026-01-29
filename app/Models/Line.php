<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Line extends Model
{
    use HasFactory;

    protected $table = 'ww_line';

    protected $primaryKey = 'fldId';

    public $timestamps = false;

    protected $fillable = [
        'fldMealType',
        'fldLineNum',
        'fldDescription',
        'fldSchoolList',
        'fldSchoolCash',
        'fldGradeList',
        'fldSyncModifiedDate',
        'fldIsSynced',
    ];

    protected $casts = [
        'fldIsSynced' => 'boolean',
        'fldSyncModifiedDate' => 'datetime',
        'fldSchoolList' => 'array',
        'fldGradeList' => 'array',
    ];

    /**
     * Get the line code (e.g., "L1", "B2")
     */
    public function getLineCodeAttribute(): string
    {
        return $this->fldMealType . $this->fldLineNum;
    }

    /**
     * Get meal type info
     */
    public function mealType()
    {
        return $this->belongsTo(MealType::class, 'fldMealType', 'fldMealType');
    }

    /**
     * Get today's line log entry (if exists)
     */
    public function todayLog()
    {
        return LineLog::where('fldMealType', $this->fldMealType)
            ->where('fldLineNum', $this->fldLineNum)
            ->where('fldLineDate', now()->toDateString())
            ->first();
    }

    /**
     * Get status for today
     */
    public function getTodayStatusAttribute(): string
    {
        $log = $this->todayLog();

        if (!$log) {
            return 'not_opened';
        }

        if ($log->fldCloseDate) {
            return 'closed';
        }

        if ($log->fldOpenDate) {
            return 'open';
        }

        return 'not_opened';
    }

    /**
     * Find line by code (e.g., "L1")
     */
    public static function findByCode(string $code): ?self
    {
        $mealType = substr($code, 0, 1);
        $lineNum = substr($code, 1);

        return self::where('fldMealType', $mealType)
            ->where('fldLineNum', $lineNum)
            ->first();
    }
}
