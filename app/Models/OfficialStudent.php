<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficialStudent extends Model
{
    protected $table = 'official_students';

    protected $fillable = [
        'college_id', 'department_id', 'level', 'academic_number', 'student_name',
    ];

    public function college()
    {
        return $this->belongsTo(College::class, 'college_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}