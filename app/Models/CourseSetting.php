<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseSetting extends Model
{
    protected $table = 'course_settings';
    protected $fillable = ['offering_id', 'lecture_count', 'attendance_session_count', 'assignment_count', 'quiz_count'];
    public $timestamps = true;

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }
}
