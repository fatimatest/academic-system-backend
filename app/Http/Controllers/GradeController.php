<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class GradeController extends Controller
{
    public function sendEmail(Request $request)
    {
        $email = $request->email;
        $students = $request->students;

        Mail::raw(json_encode($students, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), function ($message) use ($email) {
            $message->to($email)
                    ->subject('كشف الدرجات');
        });

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال البريد'
        ]);
    }
}