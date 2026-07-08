<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class College extends Model
{
    protected $table = 'colleges';
    public $timestamps = false;

    protected $fillable = ['name', 'manager_id', 'is_active'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function departments()
    {
        return $this->hasMany(Department::class, 'college_id');
    }

    public function studentsCount()
    {
        $deptIds = $this->departments()->pluck('id');
        return User::where('role', 'student')->whereIn('department_id', $deptIds)->count();
    }
}