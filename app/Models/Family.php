<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Family extends Model
{
    use HasFactory;

    protected $table = 'ww_family';
    protected $primaryKey = 'fldId';
    public $timestamps = false;

    protected $fillable = [
        'fldFamPermId',
        'fldFamilyId',
        'fldFirstName',
        'fldLastName',
        'fldAddress',
        'fldCity',
        'fldState',
        'fldZip',
        'fldPhone',
        'fldEmail',
        'fldSpecialCode',
        'fldFamilyExtraData',
        'fld_contact_person_dcid',
        'fldBeginningBalance',
        'fldBalanceArchive',
        'fldBypass',
        'fldDeleted',
        'fldSyncModifiedDate1',
        'fldIsSynced1',
        'fldBalance',
        'fldSyncModifiedDate2',
        'fldIsSynced2'
    ];

    protected $casts = [
        'fldBypass' => 'boolean',
        'fldDeleted' => 'boolean',
        'fldIsSynced1' => 'boolean',
        'fldIsSynced2' => 'boolean',
        'fldSyncModifiedDate1' => 'datetime',
        'fldSyncModifiedDate2' => 'datetime',
        'fldBeginningBalance' => 'decimal:2',
        'fldBalance' => 'decimal:2'
    ];

    public function students()
    {
        return $this->hasMany(Student::class, 'fldFamilyId', 'fldFamilyId');
    }

    public function schoolCode()
    {
        return $this->belongsTo(SchoolCode::class, 'fldSchoolCode', 'fldSchoolCode');
    }
}
