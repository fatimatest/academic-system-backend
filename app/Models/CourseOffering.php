<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseOffering extends Model
{
    protected $table = 'course_offerings';
    public $timestamps = false;

    protected $fillable = [
        'subject_id', 'doctor_id', 'ta_id', 'department_id',
        'level', 'term_id', 'study_type'
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function ta()
    {
        return $this->belongsTo(User::class, 'ta_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function term()
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'offering_id');
    }

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class, 'offering_id');
    }

    public function joinRequests()
    {
        return $this->hasMany(JoinRequest::class, 'offering_id');
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'offering_id');
    }

    public function attendanceSessions()
    {
        return $this->hasMany(AttendanceSession::class, 'course_offering_id');
    }
}