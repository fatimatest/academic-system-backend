<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $table = 'announcements';
    protected $fillable = [
        'offering_id', 'doctor_id', 'title', 'body', 'status',
        'target_department', 'target_all',
        'college_id', 'sender_id', 'target_type', 'attachment',
        'target_role', 'target_college_id',
    ];

    public function scopeForOffering($query, $offeringId, $subjectId = null)
    {
        $query->where(function ($q) use ($offeringId, $subjectId) {
            $q->where('offering_id', $offeringId)
              ->orWhere(function ($oq) use ($subjectId) {
                  $oq->where('target_all', true);
                  if ($subjectId) {
                      $oq->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                  }
              });
        });
    }
    public $timestamps = true;

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function college()
    {
        return $this->belongsTo(College::class, 'college_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function targetCollege()
    {
        return $this->belongsTo(College::class, 'target_college_id');
    }
}
