<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public $timestamps = true; // ✅ لأن الجدول يحتوي على created_at و updated_at

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',              // ✅ استخدم role بدلاً من user_type
        'academic_number',
        'study_type',
        'qr_token',
        'phone',
        'department_id',
        'is_active',
        'level',
        'avatar_type',
        'reset_otp',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // العلاقات
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function courseOfferings()
    {
        return $this->hasMany(CourseOffering::class, 'doctor_id');
    }

    public function studentEnrollments()
    {
        return $this->hasMany(StudentEnrollment::class, 'student_id');
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'student_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class, 'student_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function managedCollege()
    {
        return $this->hasOne(College::class, 'manager_id');
    }
}