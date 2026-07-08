<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseMaterial extends Model
{
    protected $table = 'course_materials';

    protected $fillable = [
        'offering_id', 'doctor_id', 'file_name', 'file_path', 'file_size', 'target_all'
    ];

    public function scopeForOffering($query, $offeringId, $subjectId = null)
    {
        $query->where(function ($q) use ($offeringId, $subjectId) {
            $q->where('offering_id', $offeringId);
            if ($subjectId) {
                $q->orWhere(function ($oq) use ($subjectId) {
                    $oq->where('target_all', true)
                       ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                });
            }
        });
    }

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
