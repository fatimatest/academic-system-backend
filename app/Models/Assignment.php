<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $table = 'assignments';
    public $timestamps = false;

    protected $fillable = [
        'offering_id', 'creator_id', 'title', 'type',
        'description', 'max_grade', 'due_date', 'file_path', 'category', 'target_all'
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

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }

    public function offerings()
    {
        return $this->belongsToMany(CourseOffering::class, 'assignment_offering', 'assignment_id', 'offering_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class, 'assignment_id');
    }
}