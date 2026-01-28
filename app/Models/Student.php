<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Student extends Model
{
    use HasFactory;

    protected $table = 'ww_student';

    protected $primaryKey = 'fldId';

    public $timestamps = false;

    protected $fillable = [
        'fldCloudId',
        'fldLcsId',
        'fldLunchId',
        'fldFamPermId',
        'fldFirstName',
        'fldLastName',
        'fldGrade',
        'fldSchool',
        'fldHomeroom',
        'fldEthnicCode',
        'fldDob',
        'fldGender',
        'fldEnrolled',
        'fldReferenceId',
        'fldGPA',
        'fldEmail',
        'fldFamilyData',
        'fldExtraData',
        'fldAllergyData',
        'fldStatusId',
        'fldCreatedDate',
        'fldDeleted',
        'fldSyncModifiedDate',
        'fldIsSynced',
        'fldPictureHash'
    ];

    protected $casts = [
        'fldDob' => 'date',
        'fldCreatedDate' => 'datetime',
        'fldSyncModifiedDate' => 'datetime',
        'fldEnrolled' => 'boolean',
        'fldDeleted' => 'boolean',
        'fldIsSynced' => 'boolean',
        'fldFamilyData' => 'array',
        'fldExtraData' => 'array',
        'fldAllergyData' => 'array'
    ];

    public function allergies()
    {
        return $this->belongsToMany(Allergy::class, 'ww_student_allergies', 'fldStudentId', 'fldAllergyId');
    }
}
