<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSession extends Model
{
    protected $table = 'attendance_sessions';
    public $timestamps = false;

    protected $fillable = [
        'course_offering_id', 'doctor_id', 'lecture_id', 'session_token',
        'qr_code_value', 'session_date', 'start_time', 'end_time', 'status'
    ];

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'attendance_session_id');
    }

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'attendance_session_departments', 'attendance_session_id', 'department_id');
    }
}