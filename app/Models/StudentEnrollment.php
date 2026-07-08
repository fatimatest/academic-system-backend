<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentEnrollment extends Model
{
    protected $table = 'student_enrollments';
    public $timestamps = false;

    protected $fillable = ['student_id', 'offering_id', 'enrolled_at'];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }
}