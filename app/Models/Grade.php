<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $table = 'grades';
    public $timestamps = false;

    protected $fillable = [
        'student_id', 'offering_id', 'attendance_grade',
        'assignments_grade', 'quizzes_grade', 'midterm_grade',
        'final_exam_grade', 'total_grade'
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }
}