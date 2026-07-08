<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'departments';
    public $timestamps = false;

    protected $fillable = ['name', 'study_type', 'college_id', 'code', 'description', 'levels_count'];

    // العلاقات
    public function college()
    {
        return $this->belongsTo(College::class, 'college_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class, 'department_id');
    }

    public function courseOfferings()
    {
        return $this->hasMany(CourseOffering::class, 'department_id');
    }
}