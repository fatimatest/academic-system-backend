<?php

namespace App\Http\Controllers;

use App\Models\Subject;

class CourseController extends Controller
{
    public function index()
    {
        return Subject::all();
    }
}