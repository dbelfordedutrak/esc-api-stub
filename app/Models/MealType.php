<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealType extends Model
{
    protected $table = 'ww_mealtype';

    protected $primaryKey = 'fldId';

    public $timestamps = false;

    protected $fillable = [
        'fldMealType',
        'fldMealDescription',
        'fldIsFRProgram',
        'fldIsChildcare',
        'fldDeleted',
    ];

    protected $casts = [
        'fldIsFRProgram' => 'boolean',
        'fldIsChildcare' => 'boolean',
        'fldDeleted' => 'boolean',
    ];

    /**
     * Get all lines for this meal type
     */
    public function lines()
    {
        return $this->hasMany(Line::class, 'fldMealType', 'fldMealType');
    }

    /**
     * Scope to exclude childcare
     */
    public function scopeNotChildcare($query)
    {
        return $query->where('fldIsChildcare', 0);
    }

    /**
     * Scope to exclude deleted
     */
    public function scopeActive($query)
    {
        return $query->where(function($q) {
            $q->whereNull('fldDeleted')->orWhere('fldDeleted', 0);
        });
    }
}
