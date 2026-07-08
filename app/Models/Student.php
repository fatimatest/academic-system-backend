<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $table = 'users';
    public $timestamps = true; // ✅

    protected static function booted()
    {
        static::addGlobalScope('students', function ($query) {
            $query->where('role', 'student'); // ✅ استخدم role بدلاً من user_type
        });
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class, 'student_id');
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'student_id');
    }

    public function joinRequests()
    {
        return $this->hasMany(JoinRequest::class, 'student_id');
    }
}