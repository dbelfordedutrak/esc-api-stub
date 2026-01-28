<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolCode extends Model
{
    use HasFactory;

    protected $table = 'ww_school_code';
    protected $primaryKey = 'fldId';
    public $timestamps = false;

    protected $fillable = [
        'fldLcsSchoolId',
        'fldSchoolId',
        'fldSchoolName',
        'fldSchoolType',
        'fldDeleted',
        'fldActive',
        'fldCreatedDate',
        'fldIsSynced',
        'fldSyncModifiedDate'
    ];

    protected $casts = [
        'fldDeleted' => 'boolean',
        'fldActive' => 'boolean',
        'fldIsSynced' => 'boolean',
        'fldCreatedDate' => 'datetime',
        'fldSyncModifiedDate' => 'datetime'
    ];


}
