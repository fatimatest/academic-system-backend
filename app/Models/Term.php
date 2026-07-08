<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    protected $table = 'terms';
    public $timestamps = false;

    protected $fillable = ['name', 'start_date', 'end_date', 'status'];

    public function courseOfferings()
    {
        return $this->hasMany(CourseOffering::class, 'term_id');
    }
}