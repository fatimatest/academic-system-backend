<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JoinRequest extends Model
{
    protected $table = 'join_requests';
    public $timestamps = false;

    protected $fillable = ['student_id', 'offering_id', 'status', 'rejection_reason'];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'offering_id');
    }
}