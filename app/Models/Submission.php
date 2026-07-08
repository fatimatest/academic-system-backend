<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    protected $table = 'submissions';
    public $timestamps = false;

    protected $fillable = [
        'assignment_id', 'student_id', 'file_path',
        'submission_text', 'submitted_at', 'grade',
        'notes', 'status', 'is_late'
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}