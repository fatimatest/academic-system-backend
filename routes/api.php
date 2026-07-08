<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Models\Notification;
use App\Models\Submission;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\AdminController;

// فحص الاتصال
function notifyCollegeManager($offeringId, $title, $message, $type, $referenceType = null, $referenceId = null) {
    try {
        $offering = App\Models\CourseOffering::with('department.college')->find($offeringId);
        if ($offering && $offering->department && $offering->department->college) {
            $managerId = $offering->department->college->manager_id;
            if ($managerId) {
                App\Models\Notification::create([
                    'user_id' => $managerId,
                    'title' => $title,
                    'type' => $type,
                    'message' => $message,
                    'notification_type' => $type,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'offering_id' => $offeringId,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }
        }
    } catch (\Exception $e) {
        Log::error('notifyCollegeManager: ' . $e->getMessage());
    }
}

// تحديد نوع الدكتور لمقرر معين: theory / practical / none
function getDoctorRole($userId, $offeringId) {
    $offering = App\Models\CourseOffering::find($offeringId);
    if (!$offering) return 'none';
    if ($offering->doctor_id == $userId) return 'theory';
    if ($offering->ta_id == $userId) return 'practical';
    return 'none';
}

// معاينة صلاحية الدكتور العملي قبل العمليات الحساسة — ترجع offering_id أو null
function isPracticalDoctorFor($userId, $offeringId) {
    return getDoctorRole($userId, $offeringId) === 'practical';
}

// معاينة صلاحية الدكتور النظري
function isTheoryDoctorFor($userId, $offeringId) {
    return getDoctorRole($userId, $offeringId) === 'theory';
}

Route::get('/health', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Laravel API is running',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// تسجيل الدخول
Route::post('/login', function (Request $request) {
    try {
        $request->validate([
            'email' => 'required',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)
            ->orWhere('academic_number', $request->email)
            ->orWhere('id', $request->email)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'المستخدم غير موجود'
            ], 404);
        }

        $passwordOk = false;

        try {
            if (Hash::check($request->password, $user->password)) {
                $passwordOk = true;
            }
        } catch (\Throwable $e) {
            // stored password is not bcrypt — fall through to plain text check
        }

        if (!$passwordOk && $request->password === $user->password) {
            $passwordOk = true;
            $user->password = Hash::make($request->password);
            $user->save();
        }

        if (!$passwordOk) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة المرور غير صحيحة'
            ], 401);
        }

        $collegeId = null;
        if ($user->role === 'college_manager') {
            $collegeId = \App\Models\College::where('manager_id', $user->id)->value('id');
        } elseif ($user->department_id) {
            $collegeId = \App\Models\Department::where('id', $user->department_id)->value('college_id');
        }

        // Log login
        try {
            activity($user->id, 'تسجيل دخول', 'تسجيل دخول: ' . $user->name, $user->id, 'user');
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'role' => $user->role,
                'title' => $user->title ?? '',
                'department_id' => $user->department_id,
                'college_id' => $collegeId,
                'avatar_type' => $user->avatar_type ?? 1,
                'level' => $user->level ?? 1,
                'academic_number' => $user->academic_number ?? '',
                'uuid' => $user->uuid ?? '',
                'personal_token' => $user->personal_token ?? '',
            ]
        ]);

    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'خطأ في السيرفر: ' . $e->getMessage()
        ], 500);
    }
});

// Logout
Route::post('/logout', function (Request $request) {
    try {
        activity($request->user_id, 'تسجيل خروج', 'تسجيل خروج للمستخدم', $request->user_id, 'user');
    } catch (\Throwable $e) {}
    return response()->json(['success' => true, 'message' => 'تم تسجيل الخروج']);
});

// ✅ ✅ ✅ أضف هذا المسار الجديد ✅ ✅ ✅
Route::get('/users/{id}', function ($id) {
    $user = User::find($id);
    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'المستخدم غير موجود'
        ], 404);
    }
    
    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
        'title' => $user->title ?? '',
        'department_id' => $user->department_id,
        'department_name' => $user->department ? $user->department->name : '',
    ]);
});

// الإشعارات
Route::get('/users/{id}/notifications', function ($id) {
    $notifications = Notification::where('user_id', $id)
        ->orderBy('created_at', 'desc')
        ->limit(50)
        ->get();
    return response()->json(['success' => true, 'data' => $notifications]);
});

Route::get('/users/{id}/notifications/unread-count', function ($id) {
    $count = Notification::where('user_id', $id)->where('is_read', false)->count();
    return response()->json(['success' => true, 'count' => $count]);
});

Route::post('/notifications', function (Request $request) {
    $notif = Notification::create([
        'user_id' => $request->user_id,
        'title' => $request->title,
        'message' => $request->message,
        'notification_type' => $request->notification_type ?? 'general',
        'reference_type' => $request->reference_type,
        'reference_id' => $request->reference_id,
        'offering_id' => $request->offering_id,
        'is_read' => false,
    ]);
    return response()->json(['success' => true, 'data' => $notif]);
});

Route::put('/notifications/{id}/read', function ($id) {
    $notif = Notification::find($id);
    if ($notif) { $notif->update(['is_read' => true]); }
    return response()->json(['success' => true]);
});

Route::post('/notifications/read-all', function (Request $request) {
    Notification::where('user_id', $request->user_id)->where('is_read', false)->update(['is_read' => true]);
    return response()->json(['success' => true]);
});

// Routes الخاصة بالدكتور
Route::prefix('doctor')->group(function () {
    Route::post('attendance-sessions/{id}/refresh-token', function ($id) {
    $session = App\Models\AttendanceSession::find($id);
    if (!$session) {
        return response()->json(['error' => 'Session not found'], 404);
    }
    $newToken = 'SES_' . $session->course_offering_id . '_' . sha1(uniqid() . time() . rand(1000, 9999));
    $session->session_token = $newToken;
    $session->qr_code_value = 'RABET_SESSION:' . $newToken;
    $session->save();
    return response()->json(['session_token' => $session->session_token, 'qr_code_value' => $session->qr_code_value]);
});
    Route::post('attendance-sessions', [DoctorController::class, 'createAttendanceSession']);
    Route::post('attendance-sessions/create-for-subject', [DoctorController::class, 'createAttendanceSessionForSubject']);
    Route::post('attendance-sessions/{id}/close', [DoctorController::class, 'closeAttendanceSession']);
    Route::get('attendance-sessions/{id}/attendees', [DoctorController::class, 'getSessionAttendees']);
    Route::delete('attendance-sessions/{id}', [DoctorController::class, 'deleteAttendanceSession']);
    Route::post('attendance/record', [DoctorController::class, 'recordAttendance']);
    Route::get('active-subjects/{doctorId}', [DoctorController::class, 'getActiveSubjects']);
    Route::get('courses/{doctorId}', [DoctorController::class, 'getCourses']);
    Route::get('unique-subjects/{doctorId}', [DoctorController::class, 'getUniqueSubjects']);
    Route::get('stats/{doctorId}', [DoctorController::class, 'getStats']);
    Route::get('join-requests/{doctorId}', [DoctorController::class, 'getJoinRequests']);
    Route::get('quizzes/{doctorId}', [DoctorController::class, 'getQuizzes']);
    Route::get('assignments/{doctorId}', [DoctorController::class, 'getAssignments']);
    Route::get('grades/{doctorId}', [DoctorController::class, 'getGrades']);
    Route::get('attendance-sessions/{doctorId}', [DoctorController::class, 'getAttendanceSessions']);
});

// معالجة طلبات الانضمام دفعة واحدة (لـ "قبول الكل" و "قبول الكل موثق")
Route::post('join-requests/batch-verify', [DoctorController::class, 'batchVerifyJoinRequests']);
// معالجة طلب انضمام فردي (قبول/رفض)
Route::post('join-requests/{id}/process', [DoctorController::class, 'processJoinRequest']);

// المقررات الدراسية (Course Offerings)
Route::get('course-offerings/{id}', [DoctorController::class, 'getOffering']);
Route::post('course-offerings/{id}/materials', [DoctorController::class, 'uploadMaterial']);
Route::get('course-offerings/{id}/materials', [DoctorController::class, 'getMaterials']);
Route::delete('materials/{id}', [DoctorController::class, 'deleteMaterial']);
Route::get('course-offerings/{id}/students', [DoctorController::class, 'getOfferingStudents']);
Route::get('course-offerings/{id}/departments', [DoctorController::class, 'getOfferingDepartments']);
Route::delete('student-enrollments/{id}', [DoctorController::class, 'removeEnrollment']);
Route::get('course-offerings/{id}/grade-details/{studentId}', [DoctorController::class, 'getGradeDetails']);
Route::put('grades/{id}', [DoctorController::class, 'updateGrade']);

// جلب جميع شعب المادة في الترم النشط (للتبديل بين الشعب في واجهة الدكتور)
Route::get('subject-offerings/{subjectId}', function ($subjectId) {
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
    $offerings = App\Models\CourseOffering::with('department')
        ->where('subject_id', $subjectId)
        ->where('term_id', $activeTermId)
        ->get()
        ->map(fn($o) => [
            'id' => $o->id,
            'department_id' => $o->department_id,
            'department_name' => $o->department->name ?? '',
            'level' => $o->level,
            'doctor_id' => $o->doctor_id,
            'ta_id' => $o->ta_id,
            'enrolled_count' => App\Models\StudentEnrollment::where('offering_id', $o->id)->count(),
        ]);
    return response()->json(['success' => true, 'data' => $offerings]);
});

// التسليمات (Submissions)
Route::get('submissions/doctor/{doctorId}', function ($doctorId) {
    $submissions = Submission::with(['assignment', 'student'])
        ->whereHas('assignment', function ($q) use ($doctorId) {
            $q->where('creator_id', $doctorId)
              ->orWhereHas('offering', function ($oq) use ($doctorId) {
                  $oq->where(function ($sq) use ($doctorId) {
                      $sq->where('doctor_id', $doctorId)
                         ->orWhere('ta_id', $doctorId);
                  });
              })
              ->orWhereHas('offerings', function ($oq) use ($doctorId) {
                  $oq->where(function ($sq) use ($doctorId) {
                      $sq->where('doctor_id', $doctorId)
                         ->orWhere('ta_id', $doctorId);
                  });
              });
        })
        ->get()
        ->map(fn($s) => [
            'id' => $s->id,
            'assignment_id' => $s->assignment_id,
            'student_id' => $s->student_id,
            'student_name' => $s->student->name ?? '',
            'academic_number' => $s->student->academic_number ?? '',
            'assignment_title' => $s->assignment->title ?? '',
            'submitted_at' => $s->submitted_at,
            'file_path' => $s->file_path,
            'notes' => $s->notes,
            'grade' => $s->grade,
            'status' => $s->grade !== null ? 'graded' : 'pending',
        ]);
    return response()->json(['success' => true, 'data' => $submissions]);
});

// ================= Admin Routes =================
Route::prefix('admin')->group(function () {
    Route::get('stats', [AdminController::class, 'getStats']);
    Route::get('activities', [AdminController::class, 'getActivities']);
    Route::get('notifications', [AdminController::class, 'getNotifications']);
    Route::get('colleges', [AdminController::class, 'getColleges']);
    Route::post('colleges', [AdminController::class, 'createCollege']);
    Route::put('colleges/{id}', [AdminController::class, 'updateCollege']);
    Route::delete('colleges/{id}', [AdminController::class, 'deleteCollege']);
    Route::put('colleges/{id}/toggle-status', [AdminController::class, 'toggleCollegeStatus']);
    Route::get('college-managers', [AdminController::class, 'getCollegeManagers']);
    Route::get('colleges-for-select', [AdminController::class, 'getCollegesForSelect']);
    Route::get('departments', [AdminController::class, 'getDepartments']);
    Route::get('departments/{collegeId}', [AdminController::class, 'getDepartmentsByCollege']);
    Route::get('department-levels', [AdminController::class, 'getDepartmentLevels']);
    Route::post('departments', [AdminController::class, 'createDepartment']);
    Route::put('departments/{id}', [AdminController::class, 'updateDepartment']);
    Route::delete('departments/{id}', [AdminController::class, 'deleteDepartment']);

    Route::get('users', [AdminController::class, 'getUsers']);
    Route::get('users/{id}', [AdminController::class, 'getUser']);
    Route::post('users', [AdminController::class, 'createUser']);
    Route::put('users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('users/{id}', [AdminController::class, 'deleteUser']);
    Route::put('users/{id}/toggle-status', [AdminController::class, 'toggleUserStatus']);
    Route::post('users/{id}/regenerate-qr', [AdminController::class, 'regenerateUserQr']);

    Route::get('terms', [AdminController::class, 'getTerms']);
    Route::post('terms', [AdminController::class, 'createTerm']);
    Route::put('terms/{id}', [AdminController::class, 'updateTerm']);
    Route::delete('terms/{id}', [AdminController::class, 'deleteTerm']);
    Route::put('terms/{id}/set-active', [AdminController::class, 'setActiveTerm']);

    Route::get('roles', [AdminController::class, 'getRoles']);

    Route::get('audit-logs', [AdminController::class, 'getAuditLogs']);
    Route::get('audit-logs/csv', [AdminController::class, 'exportAuditLogs_csv']);
    Route::get('audit-logs/excel', [AdminController::class, 'exportAuditLogs_excel']);
    Route::get('audit-logs/pdf', [AdminController::class, 'exportAuditLogs_pdf']);

    Route::get('reports/subjects', [AdminController::class, 'getSubjectsReport']);
    Route::get('reports/course-offerings', [AdminController::class, 'getCourseOfferingsReport']);
    Route::get('reports/{type}', [AdminController::class, 'getReport']);
    Route::get('reports/{type}/csv', [AdminController::class, 'exportReportCsv']);
    Route::get('subjects', [AdminController::class, 'getSubjects']);
    Route::get('subjects/{id}', [AdminController::class, 'getSubject']);
    Route::post('subjects', [AdminController::class, 'createSubject']);
    Route::put('subjects/{id}', [AdminController::class, 'updateSubject']);
    Route::delete('subjects/{id}', [AdminController::class, 'deleteSubject']);

    Route::get('course-offerings', [AdminController::class, 'getCourseOfferingsForCollege']);
    Route::post('course-offerings', [AdminController::class, 'createCourseOffering']);
    Route::delete('course-offerings/{id}', [AdminController::class, 'deleteCourseOffering']);

    Route::get('settings', [AdminController::class, 'getSettings']);
    Route::put('settings', [AdminController::class, 'updateSettings']);

    Route::get('search', [AdminController::class, 'search']);

    Route::get('profile/{id}', [AdminController::class, 'getProfileWithQR']);
    Route::put('profile/{id}', [AdminController::class, 'updateAdminProfile']);

    Route::post('fix-phones', [AdminController::class, 'fixPhoneNumbers']);
    Route::post('migrate-colleges', [AdminController::class, 'addIsActiveToColleges']);

    // Official Students (سجل الطلاب)
    Route::get('official-students', [AdminController::class, 'getOfficialStudents']);
    Route::post('official-students', [AdminController::class, 'createOfficialStudent']);
    Route::post('official-students/import', [AdminController::class, 'importOfficialStudents']);
    Route::put('official-students/{id}', [AdminController::class, 'updateOfficialStudent']);
    Route::delete('official-students/{id}', [AdminController::class, 'deleteOfficialStudent']);

    // College announcements
    Route::get('announcements', [AdminController::class, 'getAnnouncements']);
    Route::post('announcements', [AdminController::class, 'createAnnouncement']);
    Route::put('announcements/{id}', [AdminController::class, 'updateAnnouncement']);
    Route::delete('announcements/{id}', [AdminController::class, 'deleteAnnouncement']);

    // Send notification to role (system manager)
    Route::post('send-notification', [AdminController::class, 'sendNotification']);
});

Route::post('assignments', [DoctorController::class, 'storeAssignment']);
Route::put('assignments/{id}', [DoctorController::class, 'updateAssignment']);
Route::delete('assignments/{id}', [DoctorController::class, 'deleteAssignment']);
Route::get('assignments/{id}/submissions', function (Request $request, $id) {
    $assignment = App\Models\Assignment::with('offerings')->find($id);
    if (!$assignment) {
        return response()->json(['success' => false, 'message' => 'التكليف غير موجود'], 404);
    }

    $offeringIds = $assignment->offerings->pluck('id')->toArray();
    if (empty($offeringIds)) {
        $offeringIds = [$assignment->offering_id];
    }

    // Get all enrolled students for these offerings (with offering->department)
    $enrollments = App\Models\StudentEnrollment::with(['student', 'offering.department'])
        ->whereIn('offering_id', $offeringIds)
        ->when($request->department_id, fn($q) => $q->whereHas('student', fn($sq) => $sq->where('department_id', $request->department_id)))
        ->get();

    // Get all submissions for this assignment
    $submissions = App\Models\Submission::with('student')
        ->where('assignment_id', $id)
        ->get()
        ->keyBy('student_id');

    $submitted = [];
    $notSubmitted = [];

    foreach ($enrollments as $enrollment) {
        $student = $enrollment->student;
        if (!$student) continue;

        $deptName = $enrollment->offering->department->name ?? '';
        $sub = $submissions->get($student->id);

        if ($sub) {
            $submitted[] = [
                'id' => $sub->id,
                'student_id' => $student->id,
                'student_name' => $student->name ?? '',
                'academic_number' => $student->academic_number ?? '',
                'department' => $deptName,
                'submitted_at' => $sub->submitted_at,
                'file_path' => $sub->file_path,
                'submission_text' => $sub->notes,
                'grade' => $sub->grade,
                'status' => $sub->grade !== null ? 'graded' : 'pending',
            ];
        } else {
            $notSubmitted[] = [
                'student_id' => $student->id,
                'student_name' => $student->name ?? '',
                'academic_number' => $student->academic_number ?? '',
                'department' => $deptName,
                'status' => 'not_submitted',
            ];
        }
    }

    return response()->json([
        'success' => true,
        'data' => [
            'submitted' => $submitted,
            'not_submitted' => $notSubmitted,
            'max_grade' => $assignment->max_grade,
            'title' => $assignment->title,
            'creator_id' => $assignment->creator_id,
        ]
    ]);
});
Route::post('submissions/{id}/grade', function (Request $request, $id) {
    $submission = Submission::find($id);
    if (!$submission) { return response()->json(['success' => false, 'message' => 'التسليم غير موجود'], 404); }
    $doctorId = $request->doctor_id ?: $request->input('doctor_id');
    if ($doctorId) {
        $assignment = App\Models\Assignment::find($submission->assignment_id);
        if ($assignment && (int)$assignment->creator_id !== (int)$doctorId) {
            return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية تصحيح هذا النشاط لأنه تم إنشاؤه بواسطة دكتور آخر'], 403);
        }
    }
    $submission->grade = $request->grade;
    $submission->notes = $request->doctor_notes ?? $submission->notes;
    $submission->save();

    // Notify student
    try {
        $assignment = App\Models\Assignment::with('offerings')->find($submission->assignment_id);
        if ($assignment) {
            $title = $assignment->title ?? 'التسليم';
            $gradeOfferingId = $assignment->offering_id ?? $assignment->offerings->first()->id ?? null;
            $notifType = ($assignment->type ?? 'assignment') === 'quiz' ? 'quiz_graded' : 'assignment_graded';
            App\Models\Notification::create([
                'user_id' => $submission->student_id,
                'title' => 'تصحيح التسليم',
                'type' => $notifType,
                'message' => "تم تصحيح {$title}: {$request->grade} درجة",
                'notification_type' => $notifType,
                'reference_type' => 'submission',
                'reference_id' => $submission->id,
                'offering_id' => $gradeOfferingId,
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
    } catch (\Exception $e) { Log::error('notif grade: ' . $e->getMessage()); }

    return response()->json(['success' => true, 'message' => 'تم تقييم التسليم']);
});

// Get all submissions by a student for a course offering
Route::post('student/submissions', function (Request $request) {
    $studentId = $request->student_id;
    $offeringId = $request->offering_id;
    $type = $request->type; // optional: 'assignment' or 'quiz'
    if (!$studentId || !$offeringId) return response()->json(['success' => false, 'message' => 'بيانات ناقصة'], 422);

    $offering = App\Models\CourseOffering::find($offeringId);
    $subjectId = $offering ? $offering->subject_id : null;
    $query = App\Models\Assignment::where(function ($q) use ($offeringId, $subjectId) {
        $q->where('offering_id', $offeringId);
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    });
    if ($type) $query->where('type', $type);
    $assignments = $query->get();
    $submissions = App\Models\Submission::where('student_id', $studentId)
        ->whereIn('assignment_id', $assignments->pluck('id'))
        ->get()->keyBy('assignment_id');

    $result = [];
    foreach ($assignments as $assignment) {
        $sub = $submissions->get($assignment->id);
        $result[] = [
            'assignment_id'    => $assignment->id,
            'title'            => $assignment->title,
            'type'             => $assignment->type,
            'max_grade'        => $assignment->max_grade,
            'due_date'         => $assignment->due_date,
            'category'         => $assignment->category,
            'creator_id'       => $assignment->creator_id,
            'submitted'        => $sub ? true : false,
            'submission_id'    => $sub ? $sub->id : null,
            'submitted_at'     => $sub ? $sub->submitted_at : null,
            'grade'            => $sub ? $sub->grade : null,
            'file_path'        => $sub ? $sub->file_path : null,
            'notes'            => $sub ? $sub->notes : null,
            'status'           => $sub ? ($sub->grade !== null ? 'graded' : 'pending') : 'not_submitted',
        ];
    }

    return response()->json(['success' => true, 'data' => $result]);
});

// ===================== Announcements CRUD =====================

// Get announcements for an offering
Route::get('announcements/{offeringId}', function ($offeringId) {
    $offering = App\Models\CourseOffering::find($offeringId);
    $subjectId = $offering ? $offering->subject_id : null;
    $anns = App\Models\Announcement::with('doctor')
        ->where(function ($q) use ($offeringId, $subjectId) {
            $q->where('offering_id', $offeringId);
            if ($subjectId) {
                $q->orWhere(function ($oq) use ($subjectId) {
                    $oq->where('target_all', true)
                       ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                });
            }
        })
        ->where('status', 'published')
        ->orderBy('created_at', 'desc')
        ->get()->map(fn($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'doctor_name' => $a->doctor->name ?? '',
            'target_department' => $a->target_department,
            'target_all' => $a->target_all,
            'created_at' => $a->created_at,
        ]);
    return response()->json(['success' => true, 'data' => $anns]);
});

// Get ALL announcements (including drafts) for a doctor's offering
Route::get('doctor/announcements/{offeringId}/{doctorId}', function ($offeringId, $doctorId) {
    $all = request()->boolean('all', false);
    $departmentName = request('department_name');
    $offering = App\Models\CourseOffering::find($offeringId);
    $subjectId = $offering ? $offering->subject_id : null;

    $query = App\Models\Announcement::with('doctor')
        ->where('doctor_id', $doctorId);

    if ($all && $subjectId) {
        $offeringIds = App\Models\CourseOffering::where('subject_id', $subjectId)->pluck('id');
        $query->where(function ($q) use ($offeringIds, $subjectId) {
            $q->whereIn('offering_id', $offeringIds);
            if ($subjectId) {
                $q->orWhere(function ($oq) use ($subjectId) {
                    $oq->where('target_all', true)
                       ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                });
            }
        });
    } else {
        $query->where(function ($q) use ($offeringId, $subjectId) {
            $q->where('offering_id', $offeringId);
            if ($subjectId) {
                $q->orWhere(function ($oq) use ($subjectId) {
                    $oq->where('target_all', true)
                       ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                });
            }
        });
    }

    // Filter by department name if provided
    if ($departmentName) {
        $query->where(function ($q) use ($departmentName) {
            $q->whereNull('target_department')
              ->orWhere('target_department', $departmentName);
        });
    }

    $anns = $query->orderBy('created_at', 'desc')
        ->get()->map(fn($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'status' => $a->status,
            'target_department' => $a->target_department,
            'target_all' => $a->target_all,
            'doctor_name' => $a->doctor->name ?? '',
            'offering_id' => $a->offering_id,
            'created_at' => $a->created_at,
            'updated_at' => $a->updated_at,
        ]);
    return response()->json(['success' => true, 'data' => $anns]);
});

// Create announcement
Route::post('announcements', function (Request $request) {
    $targetAll = $request->boolean('target_all', false);
    $ann = App\Models\Announcement::create([
        'offering_id' => $request->offering_id,
        'doctor_id' => $request->doctor_id,
        'title' => $request->title,
        'body' => $request->body ?? '',
        'status' => $request->status ?? 'published',
        'target_department' => $request->target_department,
        'target_all' => $targetAll,
    ]);

    // If published immediately, notify all students in all offerings of the same subject
    if ($ann->status === 'published') {
        try {
            $offering = App\Models\CourseOffering::find($ann->offering_id);
            $allSubjectOfferingIds = $offering
                ? App\Models\CourseOffering::where('subject_id', $offering->subject_id)->pluck('id')->toArray()
                : [$ann->offering_id];
            $query = App\Models\StudentEnrollment::whereIn('offering_id', $allSubjectOfferingIds);
            if ($ann->target_department) {
                $query->whereHas('student', function ($q) use ($ann) {
                    $q->whereHas('department', function ($q) use ($ann) {
                        $q->where('name', $ann->target_department);
                    });
                });
            }
            $students = $query->pluck('student_id');
            foreach ($students as $studentId) {
                App\Models\Notification::create([
                    'user_id' => $studentId,
                    'title' => $ann->title,
                    'type' => 'announcement',
                    'body' => $ann->body,
                    'message' => $ann->body,
                    'notification_type' => 'announcement',
                    'reference_type' => 'announcement',
                    'reference_id' => $ann->id,
                    'offering_id' => $ann->offering_id,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }
        } catch (\Exception $e) { Log::error('notif announcement: ' . $e->getMessage()); }
    }

    // Log announcement creation
    try {
        activity($request->doctor_id, 'إرسال إعلان', 'إرسال إعلان: ' . $ann->title, $ann->id, 'announcement');
    } catch (\Throwable $e) {}

    return response()->json(['success' => true, 'data' => $ann, 'message' => 'تم إنشاء الإعلان']);
});

// Update announcement
Route::put('announcements/{id}', function (Request $request, $id) {
    $ann = App\Models\Announcement::find($id);
    if (!$ann) return response()->json(['success' => false, 'message' => 'غير موجود'], 404);
    $oldStatus = $ann->status;
    $ann->title = $request->title ?? $ann->title;
    $ann->body = $request->body ?? $ann->body;
    $ann->status = $request->status ?? $ann->status;
    if ($request->has('target_all')) $ann->target_all = $request->boolean('target_all', false);
    $ann->save();

    // If transitioning to published, notify all students in all offerings of the same subject
    if ($ann->status === 'published' && $oldStatus !== 'published') {
        try {
            $offering = App\Models\CourseOffering::find($ann->offering_id);
            $allSubjectOfferingIds = $offering
                ? App\Models\CourseOffering::where('subject_id', $offering->subject_id)->pluck('id')->toArray()
                : [$ann->offering_id];
            $query = App\Models\StudentEnrollment::whereIn('offering_id', $allSubjectOfferingIds);
            if ($ann->target_department) {
                $query->whereHas('student', function ($q) use ($ann) {
                    $q->whereHas('department', function ($q) use ($ann) {
                        $q->where('name', $ann->target_department);
                    });
                });
            }
            $students = $query->pluck('student_id');
            foreach ($students as $studentId) {
                App\Models\Notification::create([
                    'user_id' => $studentId,
                    'title' => $ann->title,
                    'type' => 'announcement',
                    'body' => $ann->body,
                    'message' => $ann->body,
                    'notification_type' => 'announcement',
                    'reference_type' => 'announcement',
                    'reference_id' => $ann->id,
                    'offering_id' => $ann->offering_id,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }
        } catch (\Exception $e) { Log::error('notif announcement: ' . $e->getMessage()); }
    }

    return response()->json(['success' => true, 'data' => $ann, 'message' => 'تم تحديث الإعلان']);
});

// Delete announcement
Route::delete('announcements/{id}', function (Request $request, $id) {
    $ann = App\Models\Announcement::find($id);
    if (!$ann) return response()->json(['success' => false, 'message' => 'غير موجود'], 404);

    try {
        DB::beginTransaction();

        // حذف سجلات قراءة الإعلان
        DB::table('announcement_reads')->where('announcement_id', $id)->delete();

        // حذف الإشعارات المرتبطة بالإعلان
        App\Models\Notification::where('reference_type', 'announcement')
            ->where('reference_id', $id)
            ->delete();

        $ann->delete();

        DB::commit();

        // Log announcement deletion
        try {
            activity($ann->doctor_id, 'حذف إعلان', 'حذف إعلان: ' . $ann->title, $id, 'announcement');
        } catch (\Throwable $e) {}

        return response()->json(['success' => true, 'message' => 'تم حذف الإعلان وجميع البيانات المرتبطة به']);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error deleting announcement: ' . $e->getMessage());
        return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء حذف الإعلان'], 500);
    }
});

// App info for download page
Route::get('app-info', function () {
    $apkPath = public_path('downloads/app-release.apk');
    $fileSize = file_exists($apkPath) ? filesize($apkPath) : 0;
    $sizeMB = $fileSize > 0 ? round($fileSize / 1048576, 1) . ' MB' : '25 MB';
    $lastModified = file_exists($apkPath) ? date('Y-m-d', filemtime($apkPath)) : config('app.app_last_updated');

    return response()->json([
        'success' => true,
        'data' => [
            'app_name' => config('app.app_name'),
            'version' => config('app.app_version'),
            'size' => $sizeMB,
            'last_updated' => $lastModified,
            'min_android_version' => config('app.app_min_android'),
            'apk_url' => url('/downloads/app-release.apk'),
            'description' => config('app.app_description'),
        ],
    ]);
});

// Course settings
Route::get('course-settings/{offeringId}', function ($offeringId) {
    $settings = App\Models\CourseSetting::where('offering_id', $offeringId)->first();
    if (!$settings) {
        $settings = App\Models\CourseSetting::create([
            'offering_id' => $offeringId,
            'lecture_count' => 0,
            'attendance_session_count' => 0,
            'assignment_count' => 0,
            'quiz_count' => 0,
        ]);
    }
    // Get grade weights
    $weights = DB::table('grade_weights')->where('offering_id', $offeringId)->first();
    // Calculate grade balancing
    $totalLectures = $settings->lecture_count;
    $totalAttendance = $settings->attendance_session_count;
    $totalAssignments = $settings->assignment_count;
    $totalQuizzes = $settings->quiz_count;
    $totalItems = $totalLectures + $totalAttendance + $totalAssignments + $totalQuizzes;
    $maxGrade = 100;
    $balance = [];
    if ($totalItems > 0) {
        $perItem = $maxGrade / $totalItems;
        $balance = [
            'lecture_weight' => round($totalLectures * $perItem, 1),
            'attendance_weight' => round($totalAttendance * $perItem, 1),
            'assignment_weight' => round($totalAssignments * $perItem, 1),
            'quiz_weight' => round($totalQuizzes * $perItem, 1),
            'per_lecture' => round($perItem, 2),
            'per_attendance_session' => round($perItem, 2),
            'per_assignment' => round($perItem, 2),
            'per_quiz' => round($perItem, 2),
            'total' => round($totalItems * $perItem, 1),
            'midterm_weight' => $weights ? (float)$weights->midterm_weight : 20,
            'final_weight' => $weights ? (float)$weights->final_weight : 40,
        ];
    }
    return response()->json([
        'success' => true,
        'data' => $settings,
        'balance' => $balance,
        'grade_weights' => $weights ? [
            'attendance_weight' => (float)$weights->attendance_weight,
            'assignments_weight' => (float)$weights->assignments_weight,
            'quizzes_weight' => (float)$weights->quizzes_weight,
            'midterm_weight' => (float)$weights->midterm_weight,
            'final_weight' => (float)$weights->final_weight,
        ] : [
            'attendance_weight' => 10,
            'assignments_weight' => 10,
            'quizzes_weight' => 20,
            'midterm_weight' => 20,
            'final_weight' => 40,
        ],
    ]);
});

Route::put('course-settings/{offeringId}', function (Request $request, $offeringId) {
    $targetAll = $request->boolean('target_all', false);
    $offering = App\Models\CourseOffering::find($offeringId);
    $allOfferingIds = $targetAll && $offering
        ? App\Models\CourseOffering::where('subject_id', $offering->subject_id)->pluck('id')->toArray()
        : [$offeringId];

    $settingData = [
        'lecture_count' => $request->lecture_count ?? 0,
        'attendance_session_count' => $request->attendance_session_count ?? 0,
        'assignment_count' => $request->assignment_count ?? 0,
        'quiz_count' => $request->quiz_count ?? 0,
    ];
    foreach ($allOfferingIds as $oid) {
        App\Models\CourseSetting::updateOrCreate(['offering_id' => $oid], $settingData);
    }

    $weightData = [
        'attendance_weight' => $request->attendance_weight ?? 0,
        'assignments_weight' => $request->assignments_weight ?? 0,
        'quizzes_weight' => $request->quizzes_weight ?? 0,
        'midterm_weight' => $request->midterm_weight ?? 0,
        'final_weight' => $request->final_weight ?? 0,
    ];
    if ($request->has('attendance_weight') || $request->has('assignments_weight') || $request->has('quizzes_weight') || $request->has('midterm_weight') || $request->has('final_weight')) {
        foreach ($allOfferingIds as $oid) {
            DB::table('grade_weights')->updateOrInsert(['offering_id' => $oid], $weightData);
        }
    }

    $fresh = App\Models\CourseSetting::where('offering_id', $offeringId)->first();
    $weights = DB::table('grade_weights')->where('offering_id', $offeringId)->first();

    try {
        DB::table('audit_logs')->insert([
            'user_id' => auth()->id(),
            'action' => 'تعديل إعدادات المادة',
            'details' => 'تعديل إعدادات المقرر: ' . $offeringId . ($targetAll ? ' (الكل)' : ''),
            'target_id' => $offeringId,
            'target_type' => 'course_settings',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'created_at' => now(),
        ]);
    } catch (\Throwable $e) {}
    return response()->json([
        'success' => true,
        'data' => $fresh,
        'grade_weights' => $weights ? [
            'attendance_weight' => (float)$weights->attendance_weight,
            'assignments_weight' => (float)$weights->assignments_weight,
            'quizzes_weight' => (float)$weights->quizzes_weight,
            'midterm_weight' => (float)$weights->midterm_weight,
            'final_weight' => (float)$weights->final_weight,
        ] : [
            'attendance_weight' => 10,
            'assignments_weight' => 10,
            'quizzes_weight' => 20,
            'midterm_weight' => 20,
            'final_weight' => 40,
        ],
    ]);
});

// الملف الشخصي
Route::put('users/{id}', function (Request $request, $id) {
    $user = User::find($id);
    if (!$user) { return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404); }
    $user->email = $request->email ?? $user->email;
    $user->phone = $request->phone ?? $user->phone;
    $user->save();
    return response()->json(['success' => true, 'message' => 'تم تحديث البيانات']);
});

Route::put('users/{id}/password', function (Request $request, $id) {
    $user = User::find($id);
    if (!$user) { return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404); }
    if (!Hash::check($request->current_password, $user->password)) {
        return response()->json(['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة'], 400);
    }
    $user->password = Hash::make($request->new_password);
    $user->save();
    return response()->json(['success' => true, 'message' => 'تم تغيير كلمة المرور']);
});

// خدمة عرض الملفات - محاولة مسارات متعددة
Route::get('file/{type}/{path}', function ($type, $path) {
    $fullPath = storage_path('app/public/' . $type . '/' . $path);
    if (!file_exists($fullPath)) {
        $fullPath = public_path($type . '/' . $path);
    }
    if (!file_exists($fullPath)) {
        return response()->json(['error' => 'الملف غير موجود'], 404);
    }
    $mime = mime_content_type($fullPath);
    return response()->file($fullPath, ['Content-Type' => $mime]);
})->where('path', '.*');

// === Flutter-compatible routes (mirror doctor/ routes without the prefix) ===

// Attendance sessions
Route::post('attendance-sessions/list', function (Request $request) {
    $sessions = App\Models\AttendanceSession::with('offering.subject')
        ->where('course_offering_id', $request->offering_id)
        ->orderBy('start_time', 'desc')
        ->get()
        ->map(fn($s) => [
            'id' => $s->id,
            'course_offering_id' => $s->course_offering_id,
            'offering_name' => $s->offering->subject->name ?? '',
            'session_token' => $s->session_token,
            'qr_code_value' => $s->qr_code_value,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'is_active' => $s->status === 'Open',
            'status' => $s->status,
            'session_date' => $s->session_date,
            'department_ids' => DB::table('attendance_session_departments')
                ->where('attendance_session_id', $s->id)
                ->pluck('department_id')
                ->toArray(),
        ]);
    return response()->json(['status' => 'success', 'sessions' => $sessions]);
});

Route::post('attendance-sessions/create', function (Request $request) {
    return app(DoctorController::class)->createAttendanceSession($request);
});

Route::post('attendance-sessions/create-for-subject', function (Request $request) {
    return app(DoctorController::class)->createAttendanceSessionForSubject($request);
});

Route::post('attendance-sessions/close', function (Request $request) {
    $session = App\Models\AttendanceSession::find($request->session_id);
    if (!$session) return response()->json(['success' => false, 'message' => 'الجلسة غير موجودة'], 404);
    $session->status = 'Closed';
    $session->end_time = now();
    $session->save();
    return response()->json(['success' => true, 'message' => 'تم إغلاق الجلسة']);
});

Route::post('attendance-sessions/delete', function (Request $request) {
    return app(DoctorController::class)->deleteAttendanceSession($request->session_id);
});

Route::post('attendance-sessions/attendees', function (Request $request) {
    $response = app(DoctorController::class)->getSessionAttendees($request->session_id);
    $original = $response->getData(true);
    if (isset($original['success']) && $original['success'] && isset($original['data'])) {
        return response()->json([
            'success' => true,
            'data' => $original['data']
        ]);
    }
    return $response;
});

Route::post('attendance-sessions/regenerate-qr', function (Request $request) {
    $session = App\Models\AttendanceSession::find($request->session_id);
    if (!$session) return response()->json(['error' => 'Session not found'], 404);
    $newToken = 'SES_' . $session->course_offering_id . '_' . sha1(uniqid() . time() . rand(1000, 9999));
    $session->session_token = $newToken;
    $session->qr_code_value = 'RABET_SESSION:' . $newToken;
    $session->save();
    return response()->json(['success' => true, 'data' => ['session_token' => $session->session_token, 'qr_code_value' => $session->qr_code_value]]);
});

Route::post('attendance/record', function (Request $request) {
    return app(DoctorController::class)->recordAttendance($request);
});

// Offering-specific routes
Route::get('course-offerings/{id}/sessions', function ($id) {
    $sessions = App\Models\AttendanceSession::with('offering.subject')
        ->where('course_offering_id', $id)
        ->orderBy('start_time', 'desc')
        ->get()
        ->map(fn($s) => [
            'id' => $s->id,
            'title' => $s->offering->subject->name ?? 'جلسة',
            'session_date' => $s->session_date ?? $s->created_at->format('Y-m-d'),
            'department_name' => $s->offering->department->name ?? '',
            'department_ids' => DB::table('attendance_session_departments')
                ->where('attendance_session_id', $s->id)
                ->pluck('department_id')
                ->toArray(),
            'total_students' => App\Models\StudentEnrollment::where('offering_id', $s->course_offering_id)->count(),
            'present_count' => App\Models\Attendance::where('attendance_session_id', $s->id)->count(),
            'created_at' => $s->created_at,
        ]);
    return response()->json(['success' => true, 'data' => $sessions]);
});

Route::get('course-offerings/{id}/assignments', function (Request $request, $id) {
    $type = $request->type ?? 'assignment';
    $offering = App\Models\CourseOffering::find($id);
    $subjectId = $offering ? $offering->subject_id : null;
    $query = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)
          ->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)
                   ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    });
    if ($type === 'quiz') {
        $query->where('type', 'quiz');
    } else {
        $query->where('type', 'assignment');
    }
    $assignments = $query->with(['offerings', 'creator'])->orderBy('created_at', 'desc')->get()
        ->map(fn($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description,
            'due_date' => $a->due_date,
            'max_grade' => $a->max_grade,
            'assignment_number' => $a->assignment_number,
            'offering_id' => $a->offering_id ?? ($a->offerings->first()->id ?? null),
            'subject_id' => $subjectId,
            'department_name' => $a->offerings->first()->department->name ?? '',
            'creator_type' => $a->creator_type,
            'creator_name' => $a->creator->name ?? '',
            'target_all' => $a->target_all,
            'submitted_count' => App\Models\Submission::where('assignment_id', $a->id)->count(),
            'not_submitted_count' => (function() use ($a) {
                $pivotIds = $a->offerings()->pluck('course_offerings.id')->toArray();
                $allOfferingIds = !empty($pivotIds) ? $pivotIds : [$a->offering_id];
                $enrolledCount = App\Models\StudentEnrollment::whereIn('offering_id', $allOfferingIds)->count();
                $submittedCount = App\Models\Submission::where('assignment_id', $a->id)->count();
                return $enrolledCount - $submittedCount;
            })(),
            'created_at' => $a->created_at,
        ]);
    return response()->json(['success' => true, 'data' => $assignments]);
});

Route::get('course-offerings/{id}/grades', function ($id) {
    $offering = App\Models\CourseOffering::find($id);
    $subjectId = $offering ? $offering->subject_id : null;
    $sessionIds = App\Models\AttendanceSession::where('course_offering_id', $id)->pluck('id');
    $totalSessions = $sessionIds->count();
    $assignmentIds = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'assignment')->pluck('id');
    $quizIds = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'quiz')->pluck('id');
    $weights = DB::table('grade_weights')->where('offering_id', $id)->first();

    $students = App\Models\StudentEnrollment::with('student.department')
        ->where('offering_id', $id)
        ->get()
        ->map(function ($enrollment) use ($id, $sessionIds, $totalSessions, $assignmentIds, $quizIds, $weights) {
            $s = $enrollment->student;

            $attended = $totalSessions > 0
                ? App\Models\Attendance::where('student_id', $s->id)
                    ->whereIn('attendance_session_id', $sessionIds)
                    ->where('attendance_status', 'Present')->count()
                : 0;

            $assignmentSubs = App\Models\Submission::where('student_id', $s->id)
                ->whereIn('assignment_id', $assignmentIds)->whereNotNull('grade')->get();
            $assignmentEarned = $assignmentSubs->sum('grade');
            $assignmentPossible = App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');

            $quizSubs = App\Models\Submission::where('student_id', $s->id)
                ->whereIn('assignment_id', $quizIds)->whereNotNull('grade')->get();
            $quizEarned = $quizSubs->sum('grade');
            $quizPossible = App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');

            $grade = App\Models\Grade::where('student_id', $s->id)->where('offering_id', $id)->first();

            return [
                'student_id' => $s->id,
                'name' => $s->name ?? '',
                'department' => $s->department->name ?? '',
                'attended_sessions' => $attended,
                'total_sessions' => $totalSessions,
                'assignment_earned' => $assignmentEarned,
                'assignment_possible' => $assignmentPossible,
                'quiz_earned' => $quizEarned,
                'quiz_possible' => $quizPossible,
                'midterm_raw' => $grade->midterm_grade ?? 0,
                'final_raw' => $grade->final_exam_grade ?? 0,
                'attendance_score' => $totalSessions > 0 ? round(($attended / $totalSessions) * 100, 2) : 0,
                'assignments_score' => $assignmentPossible > 0 ? round(($assignmentEarned / $assignmentPossible) * 100, 2) : 0,
                'quizzes_score' => $quizPossible > 0 ? round(($quizEarned / $quizPossible) * 100, 2) : 0,
                'midterm_score' => $grade->midterm_grade ?? 0,
                'final_score' => $grade->final_exam_grade ?? 0,
                'total_grade' => $grade->total_grade ?? 0,
                'attendance_weight' => (float)($weights->attendance_weight ?? 0),
                'assignments_weight' => (float)($weights->assignments_weight ?? 0),
                'quizzes_weight' => (float)($weights->quizzes_weight ?? 0),
                'midterm_weight' => (float)($weights->midterm_weight ?? 0),
                'final_weight' => (float)($weights->final_weight ?? 0),
            ];
        });
    return response()->json(['success' => true, 'data' => $students]);
});

Route::get('course-offerings/{id}/student-details', function (Request $request, $id) {
    $userId = $request->user_id;
    $offering = App\Models\CourseOffering::with(['subject', 'doctor', 'department'])->find($id);
    if (!$offering) return response()->json(['success' => false, 'message' => 'المقرر غير موجود'], 404);

    $student = App\Models\User::find($userId);
    if (!$student) return response()->json(['success' => false, 'message' => 'الطالب غير موجود'], 404);

    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

    $enrollment = App\Models\StudentEnrollment::where('student_id', $userId)
        ->where('offering_id', $id)->first();

    // If not enrolled, verify offering matches student's department, level, and active term
    if (!$enrollment) {
        if ($offering->department_id != $student->department_id) {
            return response()->json(['success' => false, 'message' => 'هذا المقرر غير مخصص لتخصصك'], 403);
        }
        if ($offering->level != $student->level) {
            return response()->json(['success' => false, 'message' => 'هذا المقرر غير مخصص لمستواك الدراسي'], 403);
        }
        if ($activeTermId && $offering->term_id != $activeTermId) {
            return response()->json(['success' => false, 'message' => 'هذا المقرر غير متاح في الترم الحالي'], 403);
        }
    }

    $grade = App\Models\Grade::where('student_id', $userId)->where('offering_id', $id)->first();

    return response()->json([
        'success' => true,
        'data' => [
            'offering' => [
                'id' => $offering->id,
                'subject_name' => $offering->subject->name ?? '',
                'doctor_name' => $offering->doctor->name ?? '',
                'department_name' => $offering->department->name ?? '',
            ],
            'is_enrolled' => $enrollment !== null,
            'grade' => $grade ? [
                'total_grade' => $grade->total_grade ?? 0,
                'attendance_grade' => $grade->attendance_grade ?? 0,
                'assignments_grade' => $grade->assignments_grade ?? 0,
                'quizzes_grade' => $grade->quizzes_grade ?? 0,
            ] : null,
        ]
    ]);
});

// Student submissions
Route::post('assignments/{id}/submit', function (Request $request, $id) {
    $assignment = App\Models\Assignment::find($id);
    if (!$assignment) return response()->json(['success' => false, 'message' => 'التكليف غير موجود'], 404);

    $request->validate([
        'student_id' => 'required|integer|exists:users,id',
        'file' => 'nullable|file|max:102400',
        'text' => 'nullable|string',
    ]);

    $submission = new App\Models\Submission();
    $submission->assignment_id = $id;
    $submission->student_id = $request->student_id;
    $submission->notes = $request->text;

    if ($request->hasFile('file')) {
        $submission->file_path = $request->file('file')->store('submissions/' . $id, 'public');
    }

    $submission->submitted_at = now();
    $submission->save();

    // Log submission
    try {
        $studentName = \App\Models\User::where('id', $request->student_id)->value('name');
    } catch (\Throwable $e) {}

    // Notify the assignment creator
    try {
        $student = App\Models\User::find($request->student_id);
        $notifOfferingId = $assignment->offering_id ?? $assignment->offerings()->first()->id ?? null;
        $creatorIds = [$assignment->creator_id];
        if ($assignment->offerings()->exists()) {
            $creatorIds = $assignment->offerings()->pluck('doctor_id')->merge($creatorIds)->unique()->toArray();
        }
        foreach ($creatorIds as $cid) {
            if ($cid) {
                App\Models\Notification::create([
                    'user_id' => $cid,
                    'title' => 'تسليم تكليف',
                    'body' => "تم تسليم التكليف: \"{$assignment->title}\" من قبل {$student->name}",
                    'message' => "تم تسليم التكليف: \"{$assignment->title}\" من قبل {$student->name}",
                    'type' => 'new_submission',
                    'notification_type' => 'new_submission',
                    'reference_type' => 'assignment',
                    'reference_id' => $assignment->id,
                    'offering_id' => $notifOfferingId,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }
        }
    } catch (\Exception $e) {
        Log::error('Failed to create submission notification: ' . $e->getMessage());
    }

    return response()->json(['success' => true, 'message' => 'تم رفع التسليم', 'data' => $submission]);
});

Route::post('quizzes/{id}/submit', function (Request $request, $id) {
    $quiz = App\Models\Assignment::where('id', $id)->where('type', 'quiz')->first();
    if (!$quiz) return response()->json(['success' => false, 'message' => 'الاختبار غير موجود'], 404);
    $notifQuizOfferingId = $quiz->offering_id ?? $quiz->offerings()->first()->id ?? null;

    $request->validate([
        'student_id' => 'required|integer|exists:users,id',
        'answers' => 'required|json',
    ]);

    $submission = new App\Models\Submission();
    $submission->assignment_id = $id;
    $submission->student_id = $request->student_id;
    $submission->notes = $request->answers;
    $submission->submitted_at = now();
    $submission->save();

    // Log quiz submission

    // Notify doctor
    try {
        $student = App\Models\User::find($request->student_id);
        $creatorIds = [$quiz->creator_id];
        if ($quiz->offerings()->exists()) {
            $creatorIds = $quiz->offerings()->pluck('doctor_id')->merge($creatorIds)->unique()->toArray();
        }
        foreach ($creatorIds as $cid) {
            if ($cid) {
                App\Models\Notification::create([
                    'user_id' => $cid,
                    'title' => 'تسليم كويز',
                    'body' => "تم تسليم الكويز: \"{$quiz->title}\" من قبل {$student->name}",
                    'message' => "تم تسليم الكويز: \"{$quiz->title}\" من قبل {$student->name}",
                    'type' => 'new_submission',
                    'notification_type' => 'new_submission',
                    'reference_type' => 'quiz',
                    'reference_id' => $quiz->id,
                    'offering_id' => $notifQuizOfferingId,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }
        }
    } catch (\Exception $e) {
        Log::error('Failed to create quiz submission notification: ' . $e->getMessage());
    }

    return response()->json(['success' => true, 'message' => 'تم تسليم الاختبار', 'data' => $submission]);
});

// Grade weights update
Route::get('grades/weights/{offering_id}', function ($offeringId) {
    $weights = DB::table('grade_weights')->where('offering_id', $offeringId)->first();
    return response()->json([
        'status' => 'success',
        'weights' => $weights ? [
            'attendance_weight' => (float)$weights->attendance_weight,
            'assignments_weight' => (float)$weights->assignments_weight,
            'quizzes_weight' => (float)$weights->quizzes_weight,
            'midterm_weight' => (float)$weights->midterm_weight,
            'final_weight' => (float)$weights->final_weight,
        ] : ['attendance_weight' => 0, 'assignments_weight' => 0, 'quizzes_weight' => 0, 'midterm_weight' => 0, 'final_weight' => 0]
    ]);
});

Route::post('grades/weights', function (Request $request) {
    $doctorId = $request->doctor_id ?: $request->input('doctor_id');
    if ($doctorId && isPracticalDoctorFor($doctorId, $request->offering_id)) {
        return response()->json(['status' => 'error', 'message' => 'الدكتور العملي لا يستطيع تعديل موازنة الدرجات'], 403);
    }
    $targetAll = $request->boolean('target_all', false);
    $offering = App\Models\CourseOffering::find($request->offering_id);
    $allOfferingIds = $targetAll && $offering
        ? App\Models\CourseOffering::where('subject_id', $offering->subject_id)->pluck('id')->toArray()
        : [$request->offering_id];

    $weightData = [
        'attendance_weight' => $request->attendance_weight,
        'assignments_weight' => $request->assignments_weight,
        'quizzes_weight' => $request->quizzes_weight,
        'midterm_weight' => $request->midterm_weight,
        'final_weight' => $request->final_weight,
    ];
    foreach ($allOfferingIds as $oid) {
        DB::table('grade_weights')->updateOrInsert(['offering_id' => $oid], $weightData);
    }

    try {
        $offering = App\Models\CourseOffering::with('subject')->find($request->offering_id);
        if ($offering && $offering->doctor_id) {
            App\Models\Notification::create([
                'user_id' => $offering->doctor_id,
                'title' => 'تحديث موازنة الدرجات',
                'type' => 'weight_update',
                'message' => "تم تحديث أوزان الدرجات في {$offering->subject->name}" . ($targetAll ? ' (جميع الشعب)' : ''),
                'notification_type' => 'weight_update',
                'reference_type' => 'grade_weights',
                'reference_id' => $request->offering_id,
                'offering_id' => $request->offering_id,
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
        if ($offering) {
            notifyCollegeManager($request->offering_id, 'تحديث موازنة الدرجات', "تم تحديث أوزان الدرجات في {$offering->subject->name}" . ($targetAll ? ' (جميع الشعب)' : ''), 'weight_update', 'grade_weights', $request->offering_id);
        }
    } catch (\Exception $e) { Log::error('notif weight: ' . $e->getMessage()); }

    try {
        $offering = App\Models\CourseOffering::with('subject')->find($request->offering_id);
        $userId = $request->doctor_id ?: auth()->id();
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => 'تعديل موازنة الدرجات',
            'details' => 'تعديل موازنة الدرجات للمقرر: ' . ($offering->subject->name ?? '') . ($targetAll ? ' (الكل)' : ''),
            'target_id' => $request->offering_id,
            'target_type' => 'grade_weights',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'created_at' => now(),
        ]);
    } catch (\Throwable $e) {}
    $fresh = DB::table('grade_weights')->where('offering_id', $request->offering_id)->first();
    return response()->json([
        'status' => 'success',
        'message' => 'تم تحديث الأوزان',
        'grade_weights' => [
            'attendance_weight' => (float)($fresh->attendance_weight ?? 10),
            'assignments_weight' => (float)($fresh->assignments_weight ?? 10),
            'quizzes_weight' => (float)($fresh->quizzes_weight ?? 20),
            'midterm_weight' => (float)($fresh->midterm_weight ?? 20),
            'final_weight' => (float)($fresh->final_weight ?? 40),
        ],
    ]);
});

// Update student grade (by student_id + offering_id)
Route::post('grades/update-student', function (Request $request) {
    // منع الدكتور العملي من تعديل midterm/final
    $doctorId = $request->doctor_id;
    if ($doctorId && isPracticalDoctorFor($doctorId, $request->offering_id)) {
        return response()->json(['status' => 'error', 'message' => 'الدكتور العملي لا يستطيع تعديل درجات الامتحانات'], 403);
    }

    $grade = App\Models\Grade::where('student_id', $request->student_id)
        ->where('offering_id', $request->offering_id)->first();
    if (!$grade) {
        $grade = App\Models\Grade::create([
            'student_id' => $request->student_id,
            'offering_id' => $request->offering_id,
        ]);
    }
    if ($request->has('midterm_grade')) $grade->midterm_grade = $request->midterm_grade;
    if ($request->has('final_exam_grade')) $grade->final_exam_grade = $request->final_exam_grade;
    $grade->save();
    return response()->json(['status' => 'success', 'message' => 'تم تحديث الدرجات']);
});

// Quick grade by QR
Route::post('grade/qr', function (Request $request) {
    // التحقق من صلاحية الدكتور العملي: يسمح فقط بتصحيح تكاليف/كويزات عملية
    $doctorId = $request->doctor_id;
    $assignment = App\Models\Assignment::find($request->assignment_id);
    if ($doctorId && $assignment) {
        if (isPracticalDoctorFor($doctorId, $assignment->offering_id)) {
            $category = $assignment->category ?? 'theoretical';
            if ($category !== 'practical') {
                return response()->json(['status' => 'error', 'message' => 'الدكتور العملي لا يستطيع تصحيح التكاليف النظرية'], 403);
            }
        }
        // التحقق من ملكية النشاط: فقط منشئ التكليف/الكويز يمكنه التصحيح
        if ((int)$assignment->creator_id !== (int)$doctorId) {
            return response()->json(['status' => 'error', 'message' => 'ليس لديك صلاحية تصحيح هذا النشاط لأنه تم إنشاؤه بواسطة دكتور آخر'], 403);
        }
    }

    $qrCode = $request->qr_code;
    $parts = explode(':', $qrCode);
    $studentId = (int) end($parts);
    if (!$studentId) return response()->json(['status' => 'error', 'message' => 'رمز QR غير صالح']);

    // Find or create submission (for paper assignments without file upload)
    $submission = App\Models\Submission::where('assignment_id', $request->assignment_id)
        ->where('student_id', $studentId)->first();
    if (!$submission) {
        $submission = App\Models\Submission::create([
            'assignment_id' => $request->assignment_id,
            'student_id' => $studentId,
            'grade' => $request->grade,
            'notes' => $request->notes ?? '',
            'submitted_at' => now(),
            'status' => 'graded',
            'is_late' => false,
        ]);
    } else {
        $submission->grade = $request->grade;
        $submission->notes = $request->notes ?? $submission->notes;
        $submission->status = 'graded';
        $submission->save();
    }

    // Update Grade record with full weighted recalculation
    $assignment = App\Models\Assignment::with('offerings')->find($request->assignment_id);
    if ($assignment) {
        $pivotIds = $assignment->offerings()->pluck('course_offerings.id')->toArray();
        $offeringId = !empty($pivotIds) ? $pivotIds[0] : $assignment->offering_id;
        if (!$offeringId) $offeringId = $assignment->offering_id;
        if (!$offeringId) { $offeringId = $assignment->offering_id; }

        $gradeRec = App\Models\Grade::firstOrNew(['student_id' => $studentId, 'offering_id' => $offeringId]);
        $weights = DB::table('grade_weights')->where('offering_id', $offeringId)->first();
        $wAss = (float)($weights->assignments_weight ?? 10);
        $wQuiz = (float)($weights->quizzes_weight ?? 20);

        $subjectId = $assignment->offering ? $assignment->offering->subject_id : null;
        if ($assignment->type === 'assignment') {
            $assignmentIds = App\Models\Assignment::where(function ($q) use ($offeringId, $subjectId) {
                $q->where('offering_id', $offeringId)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $offeringId));
                if ($subjectId) {
                    $q->orWhere(function ($oq) use ($subjectId) {
                        $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                    });
                }
            })->where('type', 'assignment')->pluck('id');
            $aSubs = App\Models\Submission::where('student_id', $studentId)
                ->whereIn('assignment_id', $assignmentIds)->whereNotNull('grade')->get();
            $aEarned = $aSubs->sum('grade');
            $aMax = App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');
            $assPct = $aMax > 0 ? ($aEarned / $aMax) : 0;
            $gradeRec->assignments_grade = round($assPct * $wAss, 2);
        } else {
            $quizIds = App\Models\Assignment::where(function ($q) use ($offeringId, $subjectId) {
                $q->where('offering_id', $offeringId)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $offeringId));
                if ($subjectId) {
                    $q->orWhere(function ($oq) use ($subjectId) {
                        $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                    });
                }
            })->where('type', 'quiz')->pluck('id');
            $qSubs = App\Models\Submission::where('student_id', $studentId)
                ->whereIn('assignment_id', $quizIds)->whereNotNull('grade')->get();
            $qEarned = $qSubs->sum('grade');
            $qMax = App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');
            $quizPct = $qMax > 0 ? ($qEarned / $qMax) : 0;
            $gradeRec->quizzes_grade = round($quizPct * $wQuiz, 2);
        }

        $mid = $gradeRec->midterm_grade ?? 0;
        $fin = $gradeRec->final_exam_grade ?? 0;
        $gradeRec->total_grade = round(($gradeRec->attendance_grade ?? 0) + ($gradeRec->assignments_grade ?? 0) + ($gradeRec->quizzes_grade ?? 0) + $mid + $fin, 2);
        $gradeRec->save();
    }

    // Send notification
    try {
        $student = App\Models\User::find($studentId);
        $typeLabel = $assignment && $assignment->type === 'quiz' ? 'الكويز' : 'التكليف';
        $title = $assignment->title ?? '';
        App\Models\Notification::create([
            'user_id' => $studentId,
            'title' => "تصحيح {$typeLabel} \"{$title}\"",
            'type' => $assignment && $assignment->type === 'quiz' ? 'quiz_graded' : 'assignment_graded',
            'message' => "تم تصحيح {$typeLabel} \"{$title}\" وتم منحك {$request->grade} درجة" . ($assignment ? " من {$assignment->max_grade}" : ''),
            'body' => $request->notes ?? '',
            'notification_type' => $assignment && $assignment->type === 'quiz' ? 'quiz_graded' : 'assignment_graded',
            'reference_type' => 'submission',
            'reference_id' => $submission->id,
            'offering_id' => $assignment ? $assignment->offering_id : null,
            'is_read' => false,
            'created_at' => now(),
        ]);
    } catch (\Exception $e) { Log::error('notif qr: ' . $e->getMessage()); }

    return response()->json(['status' => 'success', 'student_name' => ($student ?? App\Models\User::find($studentId))->name ?? '']);
});

// Get attendance list for a session (with optional status filter)
Route::post('attendance/list', function (Request $request) {
    $session = App\Models\AttendanceSession::with('offering')->find($request->session_id);
    if (!$session) return response()->json(['status' => 'error', 'message' => 'الجلسة غير موجودة'], 404);

    // Get present students
    $presentIds = App\Models\Attendance::where('attendance_session_id', $request->session_id)
        ->where('attendance_status', 'Present')
        ->pluck('student_id')->toArray();

    if ($request->status === 'present') {
        $records = App\Models\Attendance::where('attendance_session_id', $request->session_id)
            ->where('attendance_status', 'Present')
            ->with('student')->get()
            ->map(fn($a) => [
                'student_id' => $a->student_id,
                'student_name' => $a->student->name ?? '',
                'academic_number' => $a->student->academic_number ?? '',
                'status' => 'present',
                'scanned_at' => $a->attended_at,
            ]);
        return response()->json(['status' => 'success', 'attendance' => $records]);
    }

    // For absent: get all enrolled students matching the session's departments minus present ones
    $sessionDeptIds = DB::table('attendance_session_departments')
        ->where('attendance_session_id', $session->id)
        ->pluck('department_id')
        ->toArray();
    $offeringIds = [];
    if (!empty($sessionDeptIds)) {
        $offeringIds = App\Models\CourseOffering::where('subject_id', $session->offering->subject_id)
            ->whereIn('department_id', $sessionDeptIds)
            ->pluck('id')
            ->toArray();
    }
    if (empty($offeringIds)) {
        $offeringIds = [$session->course_offering_id];
    }
    $enrollments = App\Models\StudentEnrollment::with('student')
        ->whereIn('offering_id', $offeringIds)
        ->get();
    $absent = [];
    foreach ($enrollments as $e) {
        if (!in_array($e->student_id, $presentIds)) {
            $s = $e->student;
            if (!$s) continue;
            $absent[] = [
                'student_id' => $s->id,
                'student_name' => $s->name ?? '',
                'academic_number' => $s->academic_number ?? '',
                'status' => 'absent',
                'scanned_at' => null,
            ];
        }
    }
    return response()->json(['status' => 'success', 'attendance' => $absent]);
});

// ========== Auth and onboarding ==========

// Get user QR code
Route::get('users/{id}/qr', function ($id) {
    $user = App\Models\User::find($id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);
    $qrValue = 'RABET_STUDENT:' . $id;
    return response()->json(['status' => 'success', 'qr_code' => $qrValue, 'user_id' => $id]);
});

// Student stats
Route::get('students/{id}/stats', function ($id) {
    $student = App\Models\User::find($id);
    if (!$student || $student->role !== 'student') return response()->json(['status' => 'error', 'message' => 'الطالب غير موجود'], 404);

    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

    $enrollments = App\Models\StudentEnrollment::where('student_id', $id)
        ->whereHas('offering', function ($q) use ($activeTermId) {
            $q->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId));
        })
        ->with('offering.subject')->get();
    return response()->json([
        'status' => 'success',
        'data' => [
            'total_courses' => $enrollments->count(),
            'courses' => $enrollments->map(fn($e) => [
                'id' => $e->offering->id,
                'name' => $e->offering->subject->name ?? '',
                'department_name' => $e->offering->department->name ?? '',
            ]),
        ]
    ]);
});

// Get courses for a user (doctor or student)
Route::get('users/{id}/courses', function ($id) {
    $user = App\Models\User::find($id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);

    if ($user->role === 'doctor') {
        $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

        $courses = App\Models\CourseOffering::with(['subject', 'department', 'term'])
            ->where(function ($q) use ($id) {
                $q->where('doctor_id', $id)
                  ->orWhere('ta_id', $id);
            })
            ->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId))
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'subject_name' => $c->subject->name ?? '',
                'department_name' => $c->department->name ?? '',
                'term_name' => $c->term->name ?? '',
                'study_type' => $c->study_type ?? '',
            ]);
    } else {
        $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

        $enrollments = App\Models\StudentEnrollment::where('student_id', $id)
            ->whereHas('offering', function ($q) use ($activeTermId) {
                $q->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId));
            })
            ->with('offering.subject', 'offering.department')
            ->get();
        $courses = $enrollments->map(fn($e) => [
            'offering_id' => $e->offering->id,
            'subject_name' => $e->offering->subject->name ?? '',
            'department_name' => $e->offering->department->name ?? '',
            'academic_number' => $user->academic_number ?? '',
        ]);
    }

    return response()->json(['status' => 'success', 'courses' => $courses]);
});

// Doctor stats
Route::get('doctor-stats/{id}', function ($id) {
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
    $offerings = App\Models\CourseOffering::where(function ($q) use ($id) {
        $q->where('doctor_id', $id)
          ->orWhere('ta_id', $id);
    })->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId))->get();
    $offeringIds = $offerings->pluck('id');
    $totalStudents = App\Models\StudentEnrollment::whereIn('offering_id', $offeringIds)->count();
    $totalSessions = App\Models\AttendanceSession::whereIn('course_offering_id', $offeringIds)->count();
    return response()->json([
        'status' => 'success',
        'data' => [
            'total_courses' => $offerings->count(),
            'total_students' => $totalStudents,
            'total_sessions' => $totalSessions,
        ]
    ]);
});

// Get student by QR code
Route::get('students/qr/{code}', function ($code) {
    $parts = explode(':', $code);
    $studentId = (int) end($parts);
    $student = App\Models\User::where('id', $studentId)->where('role', 'student')->first();
    if (!$student) return response()->json(['status' => 'error', 'message' => 'الطالب غير موجود'], 404);
    return response()->json(['status' => 'success', 'student' => [
        'id' => $student->id,
        'name' => $student->name,
        'academic_number' => $student->academic_number ?? '',
        'email' => $student->email,
    ]]);
});

// Student scan QR (record attendance from student side)
Route::post('attendance/scan-qr', function (Request $request) {
    // QR contains session token directly or RABET_SESSION:token
    $qrValue = $request->qr_hash ?? $request->qr_value;
    $sessionToken = str_starts_with($qrValue, 'RABET_SESSION:') ? substr($qrValue, 14) : $qrValue;

    $session = App\Models\AttendanceSession::where('session_token', $sessionToken)->first();
    if (!$session) return response()->json(['status' => 'error', 'message' => 'الجلسة غير صالحة']);
    if ($session->status !== 'Open') return response()->json(['status' => 'error', 'message' => 'الجلسة مغلقة']);

    // Find ALL offerings of the same subject the student is enrolled in
    $sessionOffering = App\Models\CourseOffering::with('subject')->find($session->course_offering_id);
    if (!$sessionOffering || !$sessionOffering->subject) {
        return response()->json(['status' => 'error', 'message' => 'المادة غير موجودة']);
    }

    $subjectId = $sessionOffering->subject_id;
    $allSubjectOfferingIds = App\Models\CourseOffering::where('subject_id', $subjectId)
        ->pluck('id')->toArray();

    $enrollment = App\Models\StudentEnrollment::where('student_id', $request->student_id)
        ->whereIn('offering_id', $allSubjectOfferingIds)
        ->first();

    if (!$enrollment) {
        // Fallback: check approved join request in case enrollment was not mirrored
        $hasApprovedRequest = App\Models\JoinRequest::where('student_id', $request->student_id)
            ->whereIn('offering_id', $allSubjectOfferingIds)
            ->where('status', 'approved')
            ->exists();
        if (!$hasApprovedRequest) {
            return response()->json(['status' => 'error', 'message' => 'غير مسجل في هذه المادة']);
        }
    }

    // Check if student's department matches the session's allowed departments
    $sessionDeptIds = DB::table('attendance_session_departments')
        ->where('attendance_session_id', $session->id)
        ->pluck('department_id')
        ->toArray();
    if (!empty($sessionDeptIds)) {
        $studentDept = App\Models\User::where('id', $request->student_id)->value('department_id');
        if (!in_array($studentDept, $sessionDeptIds)) {
            return response()->json(['status' => 'error', 'message' => 'هذه الجلسة ليست مخصصة لتخصصك الدراسي']);
        }
    }

    // Use the session's own offering for validation (not the student's enrolled offering)
    $offering = $sessionOffering;
    $student = App\Models\User::find($request->student_id);
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

    if ($offering && $student) {
        if ($offering->level != $student->level) {
            return response()->json(['status' => 'error', 'message' => 'هذه الجلسة ليست مخصصة لمستواك الدراسي']);
        }
        if ($offering->study_type !== 'both' && $student->study_type && $offering->study_type !== $student->study_type) {
            return response()->json(['status' => 'error', 'message' => 'هذه الجلسة ليست مخصصة لنوع دراستك']);
        }
        if ($activeTermId && $offering->term_id != $activeTermId) {
            return response()->json(['status' => 'error', 'message' => 'هذه الجلسة غير متاحة في الترم الحالي']);
        }
    }

    $existing = App\Models\Attendance::where('attendance_session_id', $session->id)
        ->where('student_id', $request->student_id)->first();
    if ($existing) return response()->json(['status' => 'success', 'message' => 'مسجل مسبقاً']);

    App\Models\Attendance::create([
        'attendance_session_id' => $session->id,
        'student_id' => $request->student_id,
        'attendance_status' => 'Present',
        'attended_at' => now(),
    ]);

    // Update attendances count in course_settings
    try {
        $settings = App\Models\CourseSetting::firstOrCreate(
            ['offering_id' => $session->course_offering_id],
            ['lecture_count' => 0, 'attendance_session_count' => 0, 'assignment_count' => 0, 'quiz_count' => 0]
        );
        $totalAttendances = App\Models\Attendance::whereIn('attendance_session_id',
            App\Models\AttendanceSession::where('course_offering_id', $session->course_offering_id)->pluck('id')
        )->where('student_id', $request->student_id)->count();
        $settings->attendance_session_count = max($settings->attendance_session_count, $totalAttendances);
        $settings->save();
    } catch (\Exception $e) { Log::error('update course_settings scan-qr: ' . $e->getMessage()); }

    // Update or create grade record
    try {
        $enrolledOffering = App\Models\CourseOffering::find($session->course_offering_id);
        if ($enrolledOffering) {
            $allSessions = App\Models\AttendanceSession::where('course_offering_id', $session->course_offering_id)->count();
            $attendedCount = App\Models\Attendance::whereIn('attendance_session_id',
                App\Models\AttendanceSession::where('course_offering_id', $session->course_offering_id)->pluck('id')
            )->where('student_id', $request->student_id)->count();
            $weights = DB::table('grade_weights')->where('offering_id', $session->course_offering_id)->first();
            $attW = (float)($weights->attendance_weight ?? 10);
            $attGrade = $allSessions > 0 ? round(($attendedCount / $allSessions) * $attW, 2) : 0;
            DB::table('grades')->updateOrInsert(
                ['student_id' => $request->student_id, 'offering_id' => $session->course_offering_id],
                ['attendance_grade' => $attGrade, 'updated_at' => now()]
            );
        }
    } catch (\Exception $e) { Log::error('grade update scan-qr: ' . $e->getMessage()); }

    // Log to audit log
    try {
        DB::table('audit_logs')->insert([
            'user_id' => $request->student_id,
            'action' => 'تسجيل حضور',
            'details' => 'تم تسجيل حضور الطالب عبر QR',
            'target_id' => $session->id,
            'target_type' => 'attendance_session',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'created_at' => now(),
        ]);
    } catch (\Throwable $e) {}

    // Notify student
    try {
        $offeringName = App\Models\CourseOffering::with('subject')->find($enrollment->offering_id)->subject->name ?? 'المادة';
        App\Models\Notification::create([
            'user_id' => $request->student_id,
            'title' => 'تسجيل حضور',
            'type' => 'attendance',
            'message' => "تم تسجيل حضورك في {$offeringName}",
            'notification_type' => 'attendance',
            'reference_type' => 'attendance_session',
            'reference_id' => $session->id,
            'offering_id' => $session->course_offering_id,
            'is_read' => false,
            'created_at' => now(),
        ]);
    } catch (\Exception $e) { Log::error('notif scan-qr: ' . $e->getMessage()); }

    return response()->json(['status' => 'success', 'message' => 'تم تسجيل الحضور']);
});

// Fix missing enrollments for old approved join requests
Route::post('fix-missing-enrollments', function () {
    $fixed = 0;
    $skipped = 0;
    $approvedRequests = App\Models\JoinRequest::where('status', 'approved')->get();
    foreach ($approvedRequests as $req) {
        $exists = App\Models\StudentEnrollment::where('student_id', $req->student_id)
            ->where('offering_id', $req->offering_id)->exists();
        if (!$exists) {
            App\Models\StudentEnrollment::create([
                'student_id' => $req->student_id,
                'offering_id' => $req->offering_id,
                'enrolled_at' => $req->updated_at ?? $req->created_at ?? now(),
            ]);
            $fixed++;
        } else {
            $skipped++;
        }
    }
    return response()->json([
        'success' => true,
        'message' => "تم إنشاء {$fixed} سجل ارتباط جديد، تخطي {$skipped} موجود مسبقاً",
    ]);
});

// Available offerings for student join request
Route::get('course-offerings/available', function (Request $request) {
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
    $query = App\Models\CourseOffering::with(['subject', 'doctor', 'department'])
        ->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId));

    // Auto-filter by student's department, level, and study_type when student_id provided
    if ($request->student_id) {
        $student = App\Models\User::find($request->student_id);
        if ($student) {
            $query->where('department_id', $student->department_id)
                  ->where('level', $student->level);
            if ($student->study_type && $student->study_type !== 'both') {
                $query->where(function ($q) use ($student) {
                    $q->where('study_type', $student->study_type)
                      ->orWhere('study_type', 'both');
                });
            }
        }
    } elseif ($request->department_id) {
        $query->where('department_id', $request->department_id);
    }

    $offerings = $query->get()->map(fn($o) => [
        'id' => $o->id,
        'subject_name' => $o->subject->name ?? '',
        'doctor_name' => $o->doctor->name ?? '',
        'department_name' => $o->department->name ?? '',
        'study_type' => $o->study_type ?? '',
    ]);
    return response()->json(['status' => 'success', 'offerings' => $offerings]);
});

// POST wrapper for Flutter compatibility
Route::post('course-offerings/available', function (Request $request) {
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
    $query = App\Models\CourseOffering::with(['subject', 'doctor', 'department'])
        ->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId));

    // Auto-filter by student's department, level, and study_type when student_id provided
    if ($request->student_id) {
        $student = App\Models\User::find($request->student_id);
        if ($student) {
            $query->where('department_id', $student->department_id)
                  ->where('level', $student->level);
            if ($student->study_type && $student->study_type !== 'both') {
                $query->where(function ($q) use ($student) {
                    $q->where('study_type', $student->study_type)
                      ->orWhere('study_type', 'both');
                });
            }
        }
    } elseif ($request->department_id) {
        $query->where('department_id', $request->department_id);
    }

    $courses = $query->get()->map(fn($o) => [
        'id' => $o->id,
        'subject_name' => $o->subject->name ?? '',
        'doctor_name' => $o->doctor->name ?? '',
        'department_name' => $o->department->name ?? '',
        'study_type' => $o->study_type ?? '',
    ]);
    return response()->json(['status' => 'success', 'courses' => $courses]);
});

// Submit join request
Route::post('join-requests', function (Request $request) {
    $student = App\Models\User::find($request->student_id);
    if (!$student || $student->role !== 'student') {
        return response()->json(['status' => 'error', 'message' => 'الطالب غير موجود'], 404);
    }

    $offering = App\Models\CourseOffering::find($request->offering_id);
    if (!$offering) {
        return response()->json(['status' => 'error', 'message' => 'المقرر غير موجود'], 404);
    }

    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

    // Validate offering matches student's profile
    if ($offering->department_id != $student->department_id) {
        return response()->json(['status' => 'error', 'message' => 'هذا المقرر غير مخصص لتخصصك'], 403);
    }
    if ($offering->level != $student->level) {
        return response()->json(['status' => 'error', 'message' => 'هذا المقرر غير مخصص لمستواك الدراسي'], 403);
    }
    if ($activeTermId && $offering->term_id != $activeTermId) {
        return response()->json(['status' => 'error', 'message' => 'هذا المقرر غير متاح في الترم الحالي'], 403);
    }
    if ($student->study_type && $student->study_type !== 'both' && $offering->study_type !== 'both' && $offering->study_type !== $student->study_type) {
        return response()->json(['status' => 'error', 'message' => 'نوع الدراسة غير متطابق'], 403);
    }

    $existing = App\Models\JoinRequest::where('student_id', $request->student_id)
        ->where('offering_id', $request->offering_id)
        ->whereIn('status', ['pending', 'approved'])->first();
    if ($existing) return response()->json(['status' => 'error', 'message' => 'لديك طلب مسبق']);

    $joinRequest = App\Models\JoinRequest::create([
        'student_id' => $request->student_id,
        'offering_id' => $request->offering_id,
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    // Notify doctor
    try {
        $doctorId = $offering->doctor_id ?? $offering->ta_id;
        if ($doctorId) {
            $studentName = $student->name ?? '';
            App\Models\Notification::create([
                'user_id' => $doctorId,
                'title' => 'طلب انضمام جديد',
                'type' => 'join_request',
                'message' => "الطالب {$studentName} يطلب الانضمام إلى {$offering->subject->name}",
                'notification_type' => 'join_request',
                'reference_type' => 'join_request',
                'reference_id' => $joinRequest->id,
                'offering_id' => $offering->id,
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
        // Notify college manager
        notifyCollegeManager($offering->id, 'طلب انضمام جديد', "الطالب {$studentName} يطلب الانضمام إلى {$offering->subject->name}", 'join_request', 'join_request', $joinRequest->id);
    } catch (\Exception $e) { Log::error('notif join-request: ' . $e->getMessage()); }

    return response()->json(['status' => 'success', 'message' => 'تم تقديم الطلب', 'id' => $joinRequest->id]);
});

// Get join requests for a user
Route::get('users/{id}/join-requests', function ($id, Request $request) {
    $user = App\Models\User::find($id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);

    if ($user->role === 'doctor') {
        $offerings = App\Models\CourseOffering::where(function ($q) use ($id) {
            $q->where('doctor_id', $id)
              ->orWhere('ta_id', $id);
        })->pluck('id');
        $requests = App\Models\JoinRequest::with(['student', 'offering.subject', 'offering.department'])
            ->whereIn('offering_id', $offerings)
            ->orderBy('created_at', 'desc')->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'student_id' => $r->student_id,
                'student_name' => $r->student->name ?? '',
                'student_email' => $r->student->email ?? '',
                'academic_number' => $r->student->academic_number ?? '',
                'offering_id' => $r->offering_id,
                'subject_name' => $r->offering->subject->name ?? '',
                'department_name' => $r->offering->department->name ?? '',
                'status' => $r->status,
                'requested_at' => $r->created_at,
            ]);
    } else {
        $requests = App\Models\JoinRequest::with(['offering.subject', 'offering.department'])
            ->where('student_id', $id)
            ->orderBy('created_at', 'desc')->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'offering_id' => $r->offering_id,
                'subject_name' => $r->offering->subject->name ?? '',
                'department_name' => $r->offering->department->name ?? '',
                'status' => $r->status,
                'requested_at' => $r->created_at,
            ]);
    }

    return response()->json(['status' => 'success', 'data' => $requests]);
});

// ========== Auth helper routes ==========

// Get colleges
Route::get('colleges', function () {
    $colleges = \App\Models\College::select('id', 'name')->get();
    return response()->json(['status' => 'success', 'colleges' => $colleges]);
});

// Get departments by college
Route::post('departments/by-college', function (Request $request) {
    $departments = App\Models\Department::where('college_id', $request->college_id)
        ->select('id', 'name', 'levels_count')->get();
    return response()->json(['status' => 'success', 'departments' => $departments]);
});

// Complete registration
Route::post('auth/register', function (Request $request) {
    // Retrieve registration metadata stored during send-otp
    $meta = null;
    $stored = DB::table('verification_codes')
        ->where('email', $request->email)
        ->where('type', 'registration')
        ->whereNotNull('verified_at')
        ->where('expires_at', '>', Carbon::now())
        ->first();
    if ($stored && $stored->meta) {
        $meta = json_decode($stored->meta, true);
    }

    $user = User::where('email', $request->email)->first();
    if (!$user) {
        $user = User::create([
            'email' => $request->email,
            'name' => ($meta['name'] ?? $request->name) ?: '',
            'phone' => ($meta['phone'] ?? $request->phone) ?: '',
            'academic_number' => ($meta['academic_number'] ?? $request->academic_number) ?: '',
            'password' => bcrypt($meta['password'] ?? 'password'),
            'role' => 'student',
            'department_id' => $request->department_id,
            'level' => $request->level ?? 1,
            'study_type' => $request->study_type ?? 'general',
        ]);
    } else {
        $user->department_id = $request->department_id ?? $user->department_id;
        $user->level = $request->level ?? $user->level;
        $user->study_type = $request->study_type ?? $user->study_type;
        $user->name = ($meta['name'] ?? $request->name) ?: $user->name;
        $user->phone = ($meta['phone'] ?? $request->phone) ?: $user->phone;
        $user->academic_number = ($meta['academic_number'] ?? $request->academic_number) ?: $user->academic_number;
        $user->save();
    }

    // Clean up used verification codes
    DB::table('verification_codes')
        ->where('email', $request->email)
        ->where('type', 'registration')
        ->delete();

    try { activity($user->id, 'إنشاء حساب', 'إنشاء حساب: ' . $user->name, $user->id, 'user'); } catch (\Throwable $e) {}
    return response()->json(['status' => 'success', 'message' => 'تم إنشاء الحساب بنجاح', 'user_id' => $user->id, 'name' => $user->name]);
});

// Check if student exists in official records
Route::post('official-students/check', function (Request $request) {
    $collegeId = $request->college_id;
    $academicNumber = $request->academic_number;
    if (!$collegeId || !$academicNumber) {
        return response()->json(['exists' => false, 'message' => 'البيانات غير مكتملة']);
    }
    $exists = DB::table('official_students')
        ->where('college_id', $collegeId)
        ->where('academic_number', $academicNumber)
        ->exists();
    return response()->json(['exists' => $exists]);
});

// Send OTP
Route::post('auth/send-otp', function (Request $request) {
    $email = $request->email;
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Delete any existing unverified code for this email
    DB::table('verification_codes')
        ->where('email', $email)
        ->where('type', 'registration')
        ->whereNull('verified_at')
        ->delete();

    // Store the new code with registration metadata
    DB::table('verification_codes')->insert([
        'email'      => $email,
        'code'       => $code,
        'type'       => 'registration',
        'meta'       => json_encode([
            'name'             => $request->name ?? '',
            'academic_number'  => $request->academic_number ?? '',
            'password'         => $request->password ?? '',
            'phone'            => $request->phone ?? '',
        ]),
        'expires_at' => Carbon::now()->addMinutes(10),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    // Send email
    try {
        Mail::send('emails.verification', ['code' => $code], function ($message) use ($email) {
            $message->to($email)
                    ->subject('=?UTF-8?B?'.base64_encode('رمز التحقق من البريد الإلكتروني').'?=');
        });
        Log::info("Verification email sent successfully to {$email}");
    } catch (\Exception $e) {
        Log::error("Failed to send verification email to {$email}: " . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'فشل إرسال رمز التحقق، يرجى المحاولة مرة أخرى'], 500);
    }
    return response()->json(['status' => 'success', 'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني']);
});

// Verify OTP
Route::post('auth/verify-otp', function (Request $request) {
    $record = DB::table('verification_codes')
        ->where('email', $request->email)
        ->where('type', 'registration')
        ->whereNull('verified_at')
        ->where('expires_at', '>', Carbon::now())
        ->first();

    if (!$record || $record->code !== $request->otp) {
        return response()->json(['status' => 'error', 'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية'], 422);
    }

    // Mark as verified and extend expiry so the registration step has enough time
    DB::table('verification_codes')
        ->where('id', $record->id)
        ->update([
            'verified_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours(2),
        ]);

    return response()->json(['status' => 'success', 'message' => 'تم التحقق من الرمز بنجاح']);
});

// Send password reset OTP
Route::post('auth/send-password-reset-otp', function (Request $request) {
    $user = User::where('email', $request->email)->first();
    if (!$user) return response()->json(['status' => 'error', 'message' => 'البريد الإلكتروني غير مسجل'], 404);

    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Delete any existing unverified code for this email
    DB::table('verification_codes')
        ->where('email', $request->email)
        ->where('type', 'password_reset')
        ->whereNull('verified_at')
        ->delete();

    // Store the new code
    DB::table('verification_codes')->insert([
        'email'      => $request->email,
        'code'       => $code,
        'type'       => 'password_reset',
        'expires_at' => Carbon::now()->addMinutes(10),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    // Send email
    try {
        Mail::send('emails.password-reset', ['code' => $code, 'name' => $user->name], function ($message) use ($request) {
            $message->to($request->email)
                    ->subject('=?UTF-8?B?'.base64_encode('استعادة كلمة المرور').'?=');
        });
        Log::info("Password reset email sent successfully to {$request->email}");
    } catch (\Exception $e) {
        Log::error("Failed to send password reset email to {$request->email}: " . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'فشل إرسال رمز التحقق، يرجى المحاولة مرة أخرى'], 500);
    }
    return response()->json(['status' => 'success', 'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني']);
});

// Verify password reset OTP
Route::post('auth/reset-password-verify-otp', function (Request $request) {
    $record = DB::table('verification_codes')
        ->where('email', $request->email)
        ->where('type', 'password_reset')
        ->whereNull('verified_at')
        ->where('expires_at', '>', Carbon::now())
        ->first();

    if (!$record || $record->code !== $request->otp) {
        return response()->json(['status' => 'error', 'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية'], 422);
    }

    // Mark as verified
    DB::table('verification_codes')
        ->where('id', $record->id)
        ->update(['verified_at' => Carbon::now()]);

    return response()->json(['status' => 'success', 'message' => 'تم التحقق من الرمز بنجاح']);
});

// Update forgotten password
Route::post('auth/update-forgotten-password', function (Request $request) {
    $user = User::where('email', $request->email)->first();
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);

    // Verify that a password_reset code was verified for this email
    $verified = DB::table('verification_codes')
        ->where('email', $request->email)
        ->where('type', 'password_reset')
        ->whereNotNull('verified_at')
        ->where('expires_at', '>', Carbon::now())
        ->exists();

    if (!$verified) {
        return response()->json(['status' => 'error', 'message' => 'يرجى التحقق من البريد الإلكتروني أولاً'], 403);
    }

    $user->password = bcrypt($request->password);
    $user->save();

    // Delete used codes
    DB::table('verification_codes')
        ->where('email', $request->email)
        ->where('type', 'password_reset')
        ->delete();

    return response()->json(['status' => 'success', 'message' => 'تم تغيير كلمة المرور بنجاح']);
});

// ========== Attendance redesign routes ==========

// Get departments for a subject with their offering IDs
Route::get('subjects/{id}/departments', function ($id) {
    $offerings = App\Models\CourseOffering::where('subject_id', $id)
        ->with('department')
        ->get();
    $departments = $offerings->groupBy('department_id')->map(fn($group) => [
        'id' => $group->first()->department_id,
        'name' => $group->first()->department->name ?? '',
        'offering_id' => $group->first()->id,
    ])->values();
    return response()->json(['success' => true, 'data' => $departments]);
});

// Check active session by department IDs (translate to offering IDs or use pivot table)
Route::post('attendance-sessions/active', function (Request $request) {
    $inputIds = $request->offering_ids;
    $deptIds = $request->department_ids;
    $subjectId = $request->subject_id;

    // Build base query
    $query = App\Models\AttendanceSession::with('offering.subject', 'offering.department')
        ->where('status', 'Open');

    if ($subjectId && !empty($deptIds)) {
        // Check via pivot table first, then fallback to offering lookup
        $sessionViaPivot = (clone $query)->whereIn('id', function ($q) use ($deptIds) {
            $q->select('attendance_session_id')
              ->from('attendance_session_departments')
              ->whereIn('department_id', $deptIds);
        })->first();

        if ($sessionViaPivot) {
            $session = $sessionViaPivot;
        } else {
            $offerings = App\Models\CourseOffering::where('subject_id', $subjectId)
                ->whereIn('department_id', $deptIds)
                ->pluck('id')->toArray();
            if (!empty($offerings)) {
                $query->whereIn('course_offering_id', $offerings);
            }
            $session = $query->first();
        }
    } else {
        if (!is_array($inputIds)) $inputIds = [$inputIds];
        $session = $query->whereIn('course_offering_id', $inputIds)->first();
    }
    if ($session) {
        $presentCount = App\Models\Attendance::where('attendance_session_id', $session->id)
            ->where('attendance_status', 'Present')->count();
        $totalEnrolled = App\Models\StudentEnrollment::where('offering_id', $session->course_offering_id)->count();
        return response()->json([
            'success' => true,
            'has_active' => true,
            'data' => [
                'id' => $session->id,
                'course_offering_id' => $session->course_offering_id,
                'offering_name' => $session->offering->subject->name ?? '',
                'department_name' => $session->offering->department->name ?? '',
                'session_token' => $session->session_token,
                'start_time' => $session->start_time,
                'session_date' => $session->session_date,
                'status' => $session->status,
                'present_count' => $presentCount,
                'absent_count' => $totalEnrolled - $presentCount,
            ]
        ]);
    }
    return response()->json(['success' => true, 'has_active' => false]);
});



// Get sessions with full details (departments, counts) for a subject
Route::get('subjects/{id}/sessions', function (Request $request, $id) {
    $offerings = App\Models\CourseOffering::where('subject_id', $id)->pluck('id');
    $query = App\Models\AttendanceSession::with('offering.subject', 'offering.department')
        ->whereIn('course_offering_id', $offerings);

    // Filter by department_id if provided
    if ($request->has('department_id')) {
        $deptId = (int) $request->department_id;
        $query->whereIn('id', function ($q) use ($deptId) {
            $q->select('attendance_session_id')
              ->from('attendance_session_departments')
              ->where('department_id', $deptId);
        });
    }

    $sessions = $query->orderBy('start_time', 'desc')
        ->get()
        ->map(fn($s) => [
            'id' => $s->id,
            'course_offering_id' => $s->course_offering_id,
            'offering_id' => $s->course_offering_id,
            'subject_name' => $s->offering->subject->name ?? '',
            'department_name' => $s->offering->department->name ?? '',
            'department_id' => $s->offering->department_id,
            'department_ids' => DB::table('attendance_session_departments')
                ->where('attendance_session_id', $s->id)
                ->pluck('department_id')
                ->toArray(),
            'session_token' => $s->session_token,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'session_date' => $s->session_date,
            'status' => $s->status,
            'is_active' => $s->status === 'Open',
            'present_count' => DB::table('attendances')->where('attendance_session_id', $s->id)
                ->where('attendance_status', 'Present')->count(),
            'absent_count' => DB::table('attendances')->where('attendance_session_id', $s->id)
                ->where('attendance_status', 'Absent')->count(),
        ]);
    return response()->json(['success' => true, 'data' => $sessions]);
});

// Get session with full attendee details
Route::get('attendance-sessions/{id}/details', function ($id) {
    $session = App\Models\AttendanceSession::with('offering.subject', 'offering.department')->find($id);
    if (!$session) return response()->json(['success' => false, 'message' => 'الجلسة غير موجودة'], 404);

    $offeringIds = [$session->course_offering_id];

    $attendances = DB::table('attendances')
        ->join('users', 'attendances.student_id', '=', 'users.id')
        ->where('attendances.attendance_session_id', $id)
        ->select('users.id as student_id', 'users.name as student_name',
            'users.academic_number', 'users.level',
            'attendances.attended_at', 'attendances.attendance_status as status')
        ->orderBy('attendances.attended_at', 'asc')
        ->get();

    $attendedIds = $attendances->pluck('student_id')->toArray();

    $enrolledStudents = App\Models\StudentEnrollment::with('student.department')
        ->whereIn('offering_id', $offeringIds)
        ->get();

    $present = $attendances->map(fn($a) => [
        'student_id' => $a->student_id,
        'student_name' => $a->student_name,
        'academic_number' => $a->academic_number ?? '',
        'department_name' => '',
        'level' => $a->level ?? '',
        'status' => 'present',
        'attended_at' => $a->attended_at,
    ]);

    $absent = [];
    foreach ($enrolledStudents as $enrollment) {
        $s = $enrollment->student;
        if (!$s || in_array($s->id, $attendedIds)) continue;
        $absent[] = [
            'student_id' => $s->id,
            'student_name' => $s->name ?? '',
            'academic_number' => $s->academic_number ?? '',
            'department_name' => $enrollment->department->name ?? $enrollment->offering->department->name ?? '',
            'level' => $s->level ?? '',
            'status' => 'absent',
            'attended_at' => null,
        ];
    }

    return response()->json([
        'success' => true,
        'data' => [
            'session' => [
                'id' => $session->id,
                'subject_name' => $session->offering->subject->name ?? '',
                'department_name' => $session->offering->department->name ?? '',
                'session_token' => $session->session_token,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'session_date' => $session->session_date,
                'status' => $session->status,
                'is_active' => $session->status === 'Open',
            ],
            'present' => $present,
            'absent' => $absent,
            'present_count' => count($present),
            'absent_count' => count($absent),
        ]
    ]);
});

// ========== POST-based wrappers for Flutter compatibility ==========

Route::post('users/courses', function (Request $request) {
    $id = $request->user_id;
    $user = App\Models\User::find($id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);
    if ($user->role === 'doctor') {
        $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

        $offerings = App\Models\CourseOffering::with(['subject', 'department', 'term'])
            ->where(function ($q) use ($id) {
                $q->where('doctor_id', $id)
                  ->orWhere('ta_id', $id);
            })
            ->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId))
            ->get();
        $activeTerm = App\Models\Term::where('status', 'active')->first();
            $grouped = $offerings->groupBy(fn($c) => $c->subject_id);
            $courses = $grouped->map(fn($group, $subjectId) => [
                'subject_name' => $group->first()->subject->name ?? '',
                'subject_code' => $group->first()->subject->code ?? '',
                'subject_level' => $group->first()->subject->level ?? '',
                'offerings' => $group->map(fn($c) => [
                    'offering_id' => $c->id,
                    'department_id' => $c->department_id,
                    'department_name' => $c->department->name ?? '',
                    'term_id' => $c->term_id,
                    'is_active_term' => $activeTerm && $c->term_id === $activeTerm->id,
                    'student_count' => App\Models\StudentEnrollment::where('offering_id', $c->id)->count(),
                    'study_type' => $c->study_type ?? '',
                ])->values()->toArray(),
            ])->values()->toArray();
        $activeTermId = $activeTerm ? $activeTerm->id : 0;
        return response()->json(['status' => 'success', 'courses' => $courses, 'active_term_id' => $activeTermId]);
    } else {
        $activeTermId = App\Models\Term::where('status', 'active')->value('id');

        $enrollments = App\Models\StudentEnrollment::where('student_id', $id)
            ->whereHas('offering', function ($q) use ($activeTermId) {
                $q->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId));
            })
            ->with('offering.subject', 'offering.doctor', 'offering.department', 'offering.term')
            ->get();
        $courses = $enrollments->map(fn($e) => [
            'offering_id' => $e->offering->id,
            'subject_name' => $e->offering->subject->name ?? '',
            'doctor_name' => $e->offering->doctor->name ?? '',
            'department_name' => $e->offering->department->name ?? '',
            'term_id' => $e->offering->term_id,
            'term_name' => $e->offering->term->name ?? '',
            'academic_number' => $user->academic_number ?? '',
        ]);
    }
    return response()->json(['status' => 'success', 'courses' => $courses]);
});

Route::post('users/student-grades-summary', function (Request $request) {
    $userId = $request->user_id;
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
    $enrollments = App\Models\StudentEnrollment::where('student_id', $userId)
        ->whereHas('offering', fn($q) => $q->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId)))
        ->with('offering.subject', 'offering.doctor')
        ->get();
    $data = $enrollments->map(function ($e) use ($userId) {
        $oid = $e->offering->id;
        $subjectId = $e->offering->subject_id;
        $weights = DB::table('grade_weights')->where('offering_id', $oid)->first();
        $wAtt = (float)($weights->attendance_weight ?? 0);
        $wAss = (float)($weights->assignments_weight ?? 0);
        $wQuiz = (float)($weights->quizzes_weight ?? 0);

        // Attendance grade
        $sessionIds = App\Models\AttendanceSession::where('course_offering_id', $oid)->pluck('id');
        $totalSessions = $sessionIds->count();
        $presentSessions = $totalSessions > 0
            ? App\Models\Attendance::where('student_id', $userId)->whereIn('attendance_session_id', $sessionIds)->where('attendance_status', 'Present')->count()
            : 0;
        $attPct = $totalSessions > 0 ? ($presentSessions / $totalSessions) : 0;
        $attendanceGrade = round($attPct * $wAtt, 2);

        // Assignments grade
        $assignmentIds = App\Models\Assignment::where(function ($q) use ($oid, $subjectId) {
            $q->where('offering_id', $oid)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $oid));
            if ($subjectId) {
                $q->orWhere(function ($oq) use ($subjectId) {
                    $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                });
            }
        })->where('type', 'assignment')->pluck('id');
        $aSubs = App\Models\Submission::where('student_id', $userId)->whereIn('assignment_id', $assignmentIds)->whereNotNull('grade')->get();
        $aEarned = $aSubs->sum('grade');
        $aMax = App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');
        $assPct = $aMax > 0 ? ($aEarned / $aMax) : 0;
        $assignmentsGrade = round($assPct * $wAss, 2);

        // Quizzes grade
        $quizIds = App\Models\Assignment::where(function ($q) use ($oid, $subjectId) {
            $q->where('offering_id', $oid)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $oid));
            if ($subjectId) {
                $q->orWhere(function ($oq) use ($subjectId) {
                    $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                });
            }
        })->where('type', 'quiz')->pluck('id');
        $qSubs = App\Models\Submission::where('student_id', $userId)->whereIn('assignment_id', $quizIds)->whereNotNull('grade')->get();
        $qEarned = $qSubs->sum('grade');
        $qMax = App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');
        $quizPct = $qMax > 0 ? ($qEarned / $qMax) : 0;
        $quizzesGrade = round($quizPct * $wQuiz, 2);

        // Try to get existing midterm/final from stored grade
        $stored = App\Models\Grade::where('student_id', $userId)->where('offering_id', $oid)->first();
        $mid = (float)($stored->midterm_grade ?? 0);
        $fin = (float)($stored->final_exam_grade ?? 0);
        $total = round($attendanceGrade + $assignmentsGrade + $quizzesGrade + $mid + $fin, 2);

        return [
            'offering_id' => $oid,
            'subject_name' => $e->offering->subject->name ?? '',
            'doctor_name' => $e->offering->doctor->name ?? '',
            'total_grade' => $total,
            'attendance_grade' => $attendanceGrade,
            'assignments_grade' => $assignmentsGrade,
            'quizzes_grade' => $quizzesGrade,
        ];
    });
    return response()->json(['status' => 'success', 'grades' => $data]);
});

Route::post('users/qr', function (Request $request) {
    $user = App\Models\User::find($request->user_id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);
    $qrValue = 'RABET_STUDENT:' . $request->user_id;
    return response()->json(['status' => 'success', 'qr_data' => $qrValue, 'qr_code' => $qrValue, 'user_id' => $request->user_id, 'academic_number' => $user->academic_number ?? '']);
});

Route::post('students/stats', function (Request $request) {
    $id = $request->student_id ?? $request->user_id;
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
    $subjectId = $request->subject_id;

    $enrollments = App\Models\StudentEnrollment::where('student_id', $id)
        ->whereHas('offering', function ($q) use ($activeTermId, $subjectId) {
            $q->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId))
              ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId));
        })->pluck('offering_id');
    $coursesCount = $enrollments->count();

    // All assignment IDs for this student's offerings (including pivot-linked)
    $allAssignmentIds = App\Models\Assignment::where(function ($q) use ($enrollments) {
        $q->whereIn('offering_id', $enrollments)
          ->orWhereHas('offerings', fn($oq) => $oq->whereIn('offering_id', $enrollments->toArray()));
    })->pluck('id');

    $assignmentsCount = App\Models\Assignment::whereIn('id', $allAssignmentIds)
        ->where('type', 'assignment')->count();

    $quizzesCount = App\Models\Assignment::whereIn('id', $allAssignmentIds)
        ->where('type', 'quiz')->count();

    $sessionIds = App\Models\AttendanceSession::whereIn('course_offering_id', $enrollments)->pluck('id');
    $attendanceCount = App\Models\Attendance::where('student_id', $id)
        ->whereIn('attendance_session_id', $sessionIds)
        ->where('attendance_status', 'Present')->count();

    $submissionsCount = App\Models\Submission::where('student_id', $id)
        ->whereIn('assignment_id', $allAssignmentIds)->count();

    return response()->json([
        'status' => 'success',
        'stats' => [
            'courses' => $coursesCount,
            'quizzes' => $quizzesCount,
            'attendance' => $attendanceCount,
            'assignments' => $assignmentsCount,
            'submissions' => $submissionsCount,
        ]
    ]);
});

Route::post('doctor-stats', function (Request $request) {
    $response = app()->call('App\Http\Controllers\DoctorController@getStats', [
        'doctorId' => $request->doctor_id,
    ]);
    $body = $response->getData(true);
    if (!($body['success'] ?? false)) {
        return response()->json(['status' => 'error', 'message' => $body['message'] ?? 'حدث خطأ'], 500);
    }
    $d = $body['data'];
    return response()->json([
        'status' => 'success',
        'stats' => [
            'departments_count' => $d['departments'],
            'levels_count' => $d['levels'],
            'subjects_count' => $d['subjects'],
            'total_students' => $d['students'],
            'quizzes_count' => $d['quizzes'],
            'assignments_count' => $d['assignments'],
            'materials_count' => $d['materials'],
            'sessions_count' => $d['sessions'],
            'pending_requests_count' => $d['pending_requests'],
            'submissions_count' => $d['submissions'],
        ]
    ]);
});

// معرفة نوع الدكتور لهذا المقرر (نظري/عملي)
Route::get('course-offerings/{id}/my-role', function ($id, Request $request) {
    $userId = $request->doctor_id;
    if (!$userId) return response()->json(['status' => 'error', 'message' => 'doctor_id مطلوب']);
    $role = getDoctorRole($userId, $id);
    return response()->json(['status' => 'success', 'role' => $role]);
});

Route::post('course-offerings/info', function (Request $request) {
    $offering = App\Models\CourseOffering::with(['subject', 'doctor', 'department'])->find($request->offering_id);
    if (!$offering) return response()->json(['status' => 'error', 'message' => 'المقرر غير موجود'], 404);
    return response()->json(['status' => 'success', 'data' => $offering, 'ta_id' => $offering->ta_id, 'subject_name' => $offering->subject->name ?? '', 'doctor_name' => $offering->doctor->name ?? '']);
});

Route::post('course-offerings/materials', function (Request $request) {
    $offering = App\Models\CourseOffering::find($request->offering_id);
    $subjectId = $offering ? $offering->subject_id : null;
    $materials = App\Models\CourseMaterial::where(function ($q) use ($request, $subjectId) {
        $q->where('offering_id', $request->offering_id);
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)
                   ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->orderBy('created_at', 'desc')->get();
    return response()->json(['status' => 'success', 'materials' => $materials]);
});

Route::post('course-offerings/student-details', function (Request $request) {
    $id = $request->offering_id;
    $userId = $request->user_id;
    $offering = App\Models\CourseOffering::with(['subject', 'doctor', 'department'])->find($id);
    if (!$offering) return response()->json(['success' => false, 'message' => 'المقرر غير موجود'], 404);

    $student = App\Models\User::find($userId);
    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

    // Check enrollment in this specific offering OR any offering of the same subject
    $subjectId = $offering->subject_id;
    $allSubjectOfferingIds = App\Models\CourseOffering::where('subject_id', $subjectId)->pluck('id')->toArray();
    $crossEnrolledIds = $student ? App\Models\StudentEnrollment::where('student_id', $userId)
        ->whereIn('offering_id', $allSubjectOfferingIds)
        ->pluck('offering_id')->toArray() : [];
    $enrollment = !empty($crossEnrolledIds)
        ? App\Models\StudentEnrollment::where('student_id', $userId)
            ->whereIn('offering_id', $allSubjectOfferingIds)->first()
        : ($student ? App\Models\StudentEnrollment::where('student_id', $userId)
            ->where('offering_id', $id)->first() : null);

    // Only verify dept/level/term for truly non-enrolled students (not enrolled in ANY offering of this subject)
    if ($student && !$enrollment && empty($crossEnrolledIds)) {
        if ($offering->department_id != $student->department_id) {
            return response()->json(['success' => false, 'message' => 'هذا المقرر غير مخصص لتخصصك'], 403);
        }
        if ($offering->level != $student->level) {
            return response()->json(['success' => false, 'message' => 'هذا المقرر غير مخصص لمستواك الدراسي'], 403);
        }
        if ($activeTermId && $offering->term_id != $activeTermId) {
            return response()->json(['success' => false, 'message' => 'هذا المقرر غير متاح في الترم الحالي'], 403);
        }
    }

    // Use all enrolled offerings of this subject (cross-offering enrollment)
    $allEnrolledOfferingIds = !empty($crossEnrolledIds) ? $crossEnrolledIds : [$id];

    $sessions = App\Models\AttendanceSession::whereIn('course_offering_id', $allEnrolledOfferingIds)->get();
    $attendance = App\Models\Attendance::with('session')
        ->where('student_id', $userId)
        ->whereIn('attendance_session_id', $sessions->pluck('id'))
        ->get()
        ->map(fn($a) => [
            'id' => $a->attendance_session_id,
            'title' => $a->session->offering->subject->name ?? 'جلسة',
            'date' => $a->session->session_date ?? $a->session->created_at->format('Y-m-d'),
            'status' => $a->attendance_status === 'Present' ? 'حاضر' : 'غائب',
        ]);
    $attendedIds = $attendance->pluck('id')->toArray();
    $allAttendance = $sessions->map(fn($s) => [
        'id' => $s->id,
        'title' => $offering->subject->name ?? 'جلسة',
        'date' => $s->session_date ?? $s->created_at->format('Y-m-d'),
        'status' => in_array($s->id, $attendedIds) ? ($attendance->firstWhere('id', $s->id)['status'] ?? 'غائب') : 'غائب',
    ]);
    $assignments = App\Models\Assignment::where(function($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)
          ->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)
                   ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'assignment')
    ->orderBy('created_at', 'desc')->get()->map(function($a) use ($userId) {
        $submission = App\Models\Submission::where('assignment_id', $a->id)->where('student_id', $userId)->first();
        return [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description,
            'max_grade' => $a->max_grade,
            'file_path' => $a->file_path,
            'category' => $a->category ?? 'theoretical',
            'due_date' => $a->due_date,
            'target_all' => $a->target_all,
            'isSubmitted' => $submission !== null,
            'submission' => $submission ? [
                'submitted_at' => $submission->submitted_at,
                'file_path' => $submission->file_path,
                'notes' => $submission->notes,
                'grade' => $submission->grade,
                'doctor_notes' => $submission->doctor_notes,
            ] : null,
        ];
    });
    $quizzes = App\Models\Assignment::where(function($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)
          ->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)
                   ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'quiz')->orderBy('created_at', 'desc')->get()->map(function($a) use ($userId) {
        $submission = App\Models\Submission::where('assignment_id', $a->id)->where('student_id', $userId)->first();
        return [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description,
            'max_grade' => $a->max_grade,
            'file_path' => $a->file_path,
            'category' => $a->category ?? 'theoretical',
            'due_date' => $a->due_date,
            'target_all' => $a->target_all,
            'isSubmitted' => $submission !== null,
            'submission' => $submission ? [
                'submitted_at' => $submission->submitted_at,
                'file_path' => $submission->file_path,
                'notes' => $submission->notes,
                'grade' => $submission->grade,
                'doctor_notes' => $submission->doctor_notes,
            ] : null,
        ];
    });
    // Look up grade across all offerings of the same subject (cross-offering enrollment)
    $grade = null;
    foreach ($allEnrolledOfferingIds as $oid) {
        $g = App\Models\Grade::where('student_id', $userId)->where('offering_id', $oid)->first();
        if ($g) { $grade = $g; break; }
    }
    if (!$grade) $grade = App\Models\Grade::where('student_id', $userId)->where('offering_id', $id)->first();

    // Use grade_weights from the first enrolled offering
    $weightsOfferingId = !empty($allEnrolledOfferingIds) ? $allEnrolledOfferingIds[0] : $id;
    $weights = DB::table('grade_weights')->where('offering_id', $weightsOfferingId)->first();
    $attW = (float)($weights->attendance_weight ?? 10);
    $asgW = (float)($weights->assignments_weight ?? 10);
    $qzW = (float)($weights->quizzes_weight ?? 20);
    $midW = (float)($weights->midterm_weight ?? 20);
    $finW = (float)($weights->final_weight ?? 40);
    $totalSessions = $sessions->count();
    $attendedCount = $attendance->where('status', 'حاضر')->count();
    $midtermRaw = $grade->midterm_grade ?? 0;
    $finalRaw = $grade->final_exam_grade ?? 0;
    $attGrade = $totalSessions > 0 ? round(($attendedCount / $totalSessions) * $attW, 2) : 0;
    $assignmentsEarned = $assignments->sum(fn($a) => $a['submission']['grade'] ?? 0);
    $assignmentsMax = $assignments->sum('max_grade');
    $asgGrade = $assignmentsMax > 0 ? round(($assignmentsEarned / $assignmentsMax) * $asgW, 2) : 0;
    $submittedAssignments = $assignments->filter(fn($a) => $a['isSubmitted'])->count();
    $totalAssignments = $assignments->count();
    $quizzesEarned = $quizzes->sum(fn($q) => $q['submission']['grade'] ?? 0);
    $quizzesMax = $quizzes->sum('max_grade');
    $qzGrade = $quizzesMax > 0 ? round(($quizzesEarned / $quizzesMax) * $qzW, 2) : 0;
    $submittedQuizzes = $quizzes->filter(fn($q) => $q['isSubmitted'])->count();
    $totalQuizzes = $quizzes->count();
    $midGrade = round(($midtermRaw / 100) * $midW, 2);
    $finGrade = round(($finalRaw / 100) * $finW, 2);
    $total = round($attGrade + $asgGrade + $qzGrade + $midGrade + $finGrade, 2);
    return response()->json([
        'status' => 'success',
        'attendance' => $allAttendance,
        'assignments' => $assignments,
        'quizzes' => $quizzes,
        'grades' => [
            'total_grade' => $total,
            'attendance_grade' => $attGrade,
            'assignments_grade' => $asgGrade,
            'quizzes_grade' => $qzGrade,
            'midterm_grade' => $midGrade,
            'final_exam_grade' => $finGrade,
        ],
        'grade_card' => [
            'attended_sessions' => $attendedCount,
            'total_sessions' => $totalSessions,
            'attendance_grade' => $attGrade,
            'submitted_assignments' => $submittedAssignments,
            'total_assignments' => $totalAssignments,
            'assignments_grade' => $asgGrade,
            'submitted_quizzes' => $submittedQuizzes,
            'total_quizzes' => $totalQuizzes,
            'quizzes_grade' => $qzGrade,
            'midterm_grade' => $midGrade,
            'final_exam_grade' => $finGrade,
        ],
        'grade_weights' => [
            'attendance_weight' => $attW,
            'assignments_weight' => $asgW,
            'quizzes_weight' => $qzW,
            'midterm_weight' => $midW,
            'final_weight' => $finW,
        ],
    ]);
});

Route::post('course-offerings/sessions', function (Request $request) {
    $sessions = App\Models\AttendanceSession::with('offering.subject')
        ->where('course_offering_id', $request->offering_id)
        ->orderBy('start_time', 'desc')
        ->get()->map(fn($s) => [
            'id' => $s->id,
            'course_offering_id' => $s->course_offering_id,
            'offering_name' => $s->offering->subject->name ?? '',
            'session_token' => $s->session_token,
            'qr_code_value' => $s->qr_code_value,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'is_active' => $s->status === 'Open',
            'status' => $s->status,
            'session_date' => $s->session_date,
        ]);
    return response()->json(['status' => 'success', 'data' => $sessions]);
});

Route::post('course-offerings/sessions-list', function (Request $request) {
    $sessions = App\Models\AttendanceSession::with('offering.subject')
        ->where('course_offering_id', $request->offering_id)
        ->orderBy('start_time', 'desc')
        ->get()->map(fn($s) => [
            'id' => $s->id,
            'title' => $s->offering->subject->name ?? 'جلسة',
            'session_date' => $s->session_date ?? $s->created_at->format('Y-m-d'),
            'department_name' => $s->offering->department->name ?? '',
            'total_students' => App\Models\StudentEnrollment::where('offering_id', $s->course_offering_id)->count(),
            'present_count' => App\Models\Attendance::where('attendance_session_id', $s->id)->count(),
            'created_at' => $s->created_at,
        ]);
    return response()->json(['status' => 'success', 'data' => $sessions]);
});

Route::post('course-offerings/assignments', function (Request $request) {
    $id = $request->offering_id;
    $type = $request->type ?? 'assignment';
    $offering = App\Models\CourseOffering::find($id);
    $subjectId = $offering ? $offering->subject_id : null;
    $query = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)
          ->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)
                   ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    });
    if ($type === 'quiz') {
        $query->where('type', 'quiz');
    } else {
        $query->where('type', 'assignment');
    }
    $assignments = $query->with(['offerings', 'creator'])->orderBy('created_at', 'desc')->get()
        ->map(fn($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description,
            'due_date' => $a->due_date,
            'max_grade' => $a->max_grade,
            'assignment_number' => $a->assignment_number,
            'offering_id' => $a->offering_id ?? ($a->offerings->first()->id ?? null),
            'subject_id' => $subjectId,
            'department_name' => $a->offerings->first()->department->name ?? '',
            'creator_type' => $a->creator_type,
            'creator_name' => $a->creator->name ?? '',
            'target_all' => $a->target_all,
            'submitted_count' => App\Models\Submission::where('assignment_id', $a->id)->count(),
            'not_submitted_count' => (function() use ($a) {
                $pivotIds = $a->offerings()->pluck('course_offerings.id')->toArray();
                $allOfferingIds = !empty($pivotIds) ? $pivotIds : [$a->offering_id];
                $enrolledCount = App\Models\StudentEnrollment::whereIn('offering_id', $allOfferingIds)->count();
                $submittedCount = App\Models\Submission::where('assignment_id', $a->id)->count();
                return $enrolledCount - $submittedCount;
            })(),
            'created_at' => $a->created_at,
        ]);
    return response()->json(['status' => 'success', 'data' => $assignments]);
});

Route::post('course-offerings/grades', function (Request $request) {
    $id = $request->offering_id;
    $offering = App\Models\CourseOffering::find($id);
    $subjectId = $offering ? $offering->subject_id : null;
    $sessionIds = App\Models\AttendanceSession::where('course_offering_id', $id)->pluck('id');
    $totalSessions = $sessionIds->count();
    $assignmentIds = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'assignment')->pluck('id');
    $quizIds = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'quiz')->pluck('id');
    $weights = DB::table('grade_weights')->where('offering_id', $id)->first();

    $students = App\Models\StudentEnrollment::with('student.department')
        ->where('offering_id', $id)
        ->get()
        ->map(function ($enrollment) use ($id, $sessionIds, $totalSessions, $assignmentIds, $quizIds, $weights) {
            $s = $enrollment->student;

            $attended = $totalSessions > 0
                ? App\Models\Attendance::where('student_id', $s->id)
                    ->whereIn('attendance_session_id', $sessionIds)
                    ->where('attendance_status', 'Present')->count()
                : 0;

            $assignmentSubs = App\Models\Submission::where('student_id', $s->id)
                ->whereIn('assignment_id', $assignmentIds)->whereNotNull('grade')->get();
            $assignmentEarned = $assignmentSubs->sum('grade');
            $assignmentPossible = App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');

            $quizSubs = App\Models\Submission::where('student_id', $s->id)
                ->whereIn('assignment_id', $quizIds)->whereNotNull('grade')->get();
            $quizEarned = $quizSubs->sum('grade');
            $quizPossible = App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');

            $grade = App\Models\Grade::where('student_id', $s->id)->where('offering_id', $id)->first();

            return [
                'student_id' => $s->id,
                'name' => $s->name ?? '',
                'department' => $s->department->name ?? '',
                'attended_sessions' => $attended,
                'total_sessions' => $totalSessions,
                'assignment_earned' => $assignmentEarned,
                'assignment_possible' => $assignmentPossible,
                'quiz_earned' => $quizEarned,
                'quiz_possible' => $quizPossible,
                'midterm_raw' => $grade->midterm_grade ?? 0,
                'final_raw' => $grade->final_exam_grade ?? 0,
                'attendance_score' => $totalSessions > 0 ? round(($attended / $totalSessions) * 100, 2) : 0,
                'assignments_score' => $assignmentPossible > 0 ? round(($assignmentEarned / $assignmentPossible) * 100, 2) : 0,
                'quizzes_score' => $quizPossible > 0 ? round(($quizEarned / $quizPossible) * 100, 2) : 0,
                'midterm_score' => $grade->midterm_grade ?? 0,
                'final_score' => $grade->final_exam_grade ?? 0,
                'total_grade' => $grade->total_grade ?? 0,
                'attendance_weight' => (float)($weights->attendance_weight ?? 0),
                'assignments_weight' => (float)($weights->assignments_weight ?? 0),
                'quizzes_weight' => (float)($weights->quizzes_weight ?? 0),
                'midterm_weight' => (float)($weights->midterm_weight ?? 0),
                'final_weight' => (float)($weights->final_weight ?? 0),
            ];
        });
    return response()->json(['status' => 'success', 'data' => $students]);
});

Route::post('users/notifications', function (Request $request) {
    $subjectId = $request->subject_id;
    $offeringIds = null;
    if ($subjectId) {
        $offeringIds = App\Models\CourseOffering::where('subject_id', $subjectId)->pluck('id');
    }
    $query = App\Models\Notification::where('user_id', $request->user_id);
    if ($offeringIds) {
        $query->whereIn('offering_id', $offeringIds);
    }
    $rawNotifications = $query->orderBy('created_at', 'desc')
        ->limit($request->limit ?? 50)
        ->get();
    $unreadQuery = App\Models\Notification::where('user_id', $request->user_id)->where('is_read', false);
    if ($offeringIds) {
        $unreadQuery->whereIn('offering_id', $offeringIds);
    }
    $unreadCount = $unreadQuery->count();
    $notifications = $rawNotifications->map(function ($n) {
        $offeringName = null;
        if ($n->offering_id) {
            $offering = App\Models\CourseOffering::with('subject')->find($n->offering_id);
            $offeringName = $offering && $offering->subject ? $offering->subject->name : null;
        }
        return array_merge($n->toArray(), ['offering_name' => $offeringName]);
    });
    return response()->json(['status' => 'success', 'notifications' => $notifications, 'unread_count' => $unreadCount]);
});

Route::post('users/notifications/unread-count', function (Request $request) {
    $count = App\Models\Notification::where('user_id', $request->user_id)->where('is_read', false)->count();
    return response()->json(['status' => 'success', 'unread_count' => $count]);
});

Route::post('notifications/mark-read', function (Request $request) {
    if ($request->notification_id) {
        App\Models\Notification::where('id', $request->notification_id)->update(['is_read' => true]);
    } elseif ($request->user_id) {
        App\Models\Notification::where('user_id', $request->user_id)->where('is_read', false)->update(['is_read' => true]);
    }
    return response()->json(['status' => 'success']);
});

Route::post('users/join-requests', function (Request $request) {
    $id = $request->user_id ?? $request->student_id ?? $request->doctor_id;
    $user = App\Models\User::find($id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);
    if ($user->role === 'doctor') {
        $offeringIds = App\Models\CourseOffering::where(function ($q) use ($id) {
            $q->where('doctor_id', $id)
              ->orWhere('ta_id', $id);
        })->pluck('id');
        $data = App\Models\JoinRequest::with(['student.department.college', 'offering.subject'])
            ->whereIn('offering_id', $offeringIds)->orderBy('created_at', 'desc')->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'student_id' => $r->student_id,
                'student_name' => $r->student->name ?? '',
                'email' => $r->student->email ?? '',
                'academic_number' => $r->student->academic_number ?? '',
                'college_name' => $r->student->department->college->name ?? '',
                'department_name' => $r->student->department->name ?? '',
                'level' => $r->student->level ?? '',
                'study_type' => $r->student->study_type ?? 'general',
                'offering_id' => $r->offering_id,
                'subject_name' => $r->offering->subject->name ?? '',
                'status' => $r->status,
                'created_at' => $r->created_at,
                'requested_at' => $r->created_at,
            ]);
    } else {
        $data = App\Models\JoinRequest::with(['offering.subject', 'offering.department'])
            ->where('student_id', $id)->orderBy('created_at', 'desc')->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'offering_id' => $r->offering_id,
                'subject_name' => $r->offering->subject->name ?? '',
                'department_name' => $r->offering->department->name ?? '',
                'status' => $r->status,
                'requested_at' => $r->created_at,
            ]);
    }
    return response()->json(['status' => 'success', 'requests' => $data]);
});

Route::post('students/by-qr', function (Request $request) {
    $parts = explode(':', $request->qr_value);
    $studentId = (int) end($parts);
    $student = App\Models\User::where('id', $studentId)->where('role', 'student')->first();
    if (!$student) return response()->json(['status' => 'error', 'message' => 'الطالب غير موجود'], 404);
    return response()->json(['status' => 'success', 'student' => [
        'id' => $student->id,
        'name' => $student->name,
        'academic_number' => $student->academic_number ?? '',
        'email' => $student->email,
    ]]);
});

Route::post('students/enrolled', function (Request $request) {
    $students = App\Models\StudentEnrollment::with('student.department')
        ->where('offering_id', $request->offering_id)
        ->when($request->department_id, fn($q) => $q->whereHas('student', fn($sq) => $sq->where('department_id', $request->department_id)))
        ->get()->map(fn($e) => [
            'id' => $e->student_id,
            'name' => $e->student->name ?? '',
            'academic_number' => $e->student->academic_number ?? '',
            'department_name' => $e->student->department->name ?? '',
        ]);
    return response()->json(['status' => 'success', 'data' => $students]);
});

Route::post('students/remove', function (Request $request) {
    App\Models\StudentEnrollment::where('student_id', $request->student_id)
        ->where('offering_id', $request->offering_id)->delete();
    return response()->json(['status' => 'success', 'message' => 'تم حذف الطالب']);
});

Route::post('profile/update', function (Request $request) {
    $user = App\Models\User::find($request->user_id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);
    if ($request->has('name')) $user->name = $request->name;
    if ($request->has('email')) $user->email = $request->email;
    if ($request->has('phone')) $user->phone = $request->phone;
    if ($request->has('avatar_type')) $user->avatar_type = $request->avatar_type;
    $user->save();
    return response()->json(['status' => 'success', 'message' => 'تم تحديث البيانات']);
});

Route::post('profile/update-password', function (Request $request) {
    $user = App\Models\User::find($request->user_id);
    if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
    if ($request->current_password && !Hash::check($request->current_password, $user->password)) {
        return response()->json(['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة']);
    }
    $user->password = Hash::make($request->new_password ?? $request->password);
    $user->save();
    // Log password change
    try {
    } catch (\Throwable $e) {}
    return response()->json(['success' => true, 'message' => 'تم تغيير كلمة المرور']);
});

Route::post('assignments/list-submissions', function (Request $request) {
    $assignment = App\Models\Assignment::with('offerings')->find($request->assignment_id);
    if (!$assignment) return response()->json(['success' => false, 'message' => 'التكليف غير موجود'], 404);
    $offeringIds = $assignment->offerings->pluck('id')->toArray();
    if (empty($offeringIds)) $offeringIds = [$assignment->offering_id];
    $enrollments = App\Models\StudentEnrollment::with(['student', 'offering.department'])
        ->whereIn('offering_id', $offeringIds)
        ->when($request->department_id, fn($q) => $q->whereHas('student', fn($sq) => $sq->where('department_id', $request->department_id)))
        ->get();
    $submissions = App\Models\Submission::with('student')
        ->where('assignment_id', $request->assignment_id)->get()->keyBy('student_id');
    $submitted = [];
    $notSubmitted = [];
    foreach ($enrollments as $enrollment) {
        $student = $enrollment->student;
        if (!$student) continue;
        $deptName = $enrollment->offering->department->name ?? '';
        $sub = $submissions->get($student->id);
        if ($sub) {
            $submitted[] = [
                'id' => $sub->id,
                'student_id' => $student->id,
                'student_name' => $student->name ?? '',
                'academic_number' => $student->academic_number ?? '',
                'department' => $deptName,
                'submitted_at' => $sub->submitted_at,
                'file_path' => $sub->file_path,
                'submission_text' => $sub->notes,
                'grade' => $sub->grade,
                'status' => $sub->grade !== null ? 'graded' : 'pending',
            ];
        } else {
            $notSubmitted[] = [
                'student_id' => $student->id,
                'student_name' => $student->name ?? '',
                'academic_number' => $student->academic_number ?? '',
                'department' => $deptName,
                'status' => 'not_submitted',
            ];
        }
    }
    return response()->json([
        'success' => true,
        'data' => ['submitted' => $submitted, 'not_submitted' => $notSubmitted, 'max_grade' => $assignment->max_grade, 'title' => $assignment->title, 'creator_id' => $assignment->creator_id]
    ]);
});

Route::post('attendance/list-by-session', function (Request $request) {
    $attendance = App\Models\Attendance::with('student')
        ->where('attendance_session_id', $request->session_id)
        ->get()->map(fn($a) => [
            'student_id' => $a->student_id,
            'student_name' => $a->student->name ?? '',
            'academic_number' => $a->student->academic_number ?? '',
            'status' => $a->attendance_status === 'Present' ? 'present' : 'absent',
        'scanned_at' => $a->attended_at,
        ]);
    return response()->json(['status' => 'success', 'attendance' => $attendance]);
});

Route::post('enroll', function (Request $request) {
    $student = App\Models\User::find($request->student_id);
    if (!$student || $student->role !== 'student') {
        return response()->json(['status' => 'error', 'message' => 'الطالب غير موجود'], 404);
    }

    $offering = App\Models\CourseOffering::find($request->offering_id);
    if (!$offering) {
        return response()->json(['status' => 'error', 'message' => 'المقرر غير موجود'], 404);
    }

    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

    // Validate offering matches student's profile
    if ($offering->department_id != $student->department_id) {
        return response()->json(['status' => 'error', 'message' => 'هذا المقرر غير مخصص لتخصصك'], 403);
    }
    if ($offering->level != $student->level) {
        return response()->json(['status' => 'error', 'message' => 'هذا المقرر غير مخصص لمستواك الدراسي'], 403);
    }
    if ($activeTermId && $offering->term_id != $activeTermId) {
        return response()->json(['status' => 'error', 'message' => 'هذا المقرر غير متاح في الترم الحالي'], 403);
    }
    if ($student->study_type && $student->study_type !== 'both' && $offering->study_type !== 'both' && $offering->study_type !== $student->study_type) {
        return response()->json(['status' => 'error', 'message' => 'نوع الدراسة غير متطابق'], 403);
    }

    $existing = App\Models\StudentEnrollment::where('student_id', $request->student_id)
        ->where('offering_id', $request->offering_id)->first();
    if ($existing) return response()->json(['status' => 'error', 'message' => 'مسجل مسبقاً']);
    App\Models\StudentEnrollment::create([
        'student_id' => $request->student_id,
        'offering_id' => $request->offering_id,
    ]);

    // Notify doctor
    try {
        $doctorId = $offering->doctor_id ?? $offering->ta_id;
        if ($doctorId) {
            $studentName = $student->name ?? '';
            App\Models\Notification::create([
                'user_id' => $doctorId,
                'title' => 'تسجيل طالب جديد',
                'type' => 'enrollment',
                'message' => "الطالب {$studentName} سجل في {$offering->subject->name}",
                'notification_type' => 'enrollment',
                'reference_type' => 'enrollment',
                'reference_id' => null,
                'offering_id' => $offering->id,
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
    } catch (\Exception $e) { Log::error('notif enroll: ' . $e->getMessage()); }

    return response()->json(['status' => 'success', 'message' => 'تم التسجيل']);
});

Route::post('profile', function (Request $request) {
    $user = App\Models\User::with('department')->find($request->user_id);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);
    $level = $user->level ?? 1;
    $year = (intdiv($level - 1, 2)) + 1;
    return response()->json(['status' => 'success', 'user' => [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone ?? '',
        'academic_number' => $user->academic_number ?? '',
        'level' => $level,
        'year' => $year,
        'department_name' => $user->department->name ?? '',
        'avatar_type' => $user->avatar_type ?? 1,
        'role' => $user->role,
    ]]);
});

// Get system announcements for a user based on their role/college
Route::post('system-announcements/list', function (Request $request) {
    $userId = $request->user_id;
    $user = App\Models\User::find($userId);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);

    $collegeId = null;
    if ($user->role === 'college_manager') {
        $collegeId = App\Models\College::where('manager_id', $user->id)->value('id');
    } elseif ($user->department_id) {
        $collegeId = App\Models\Department::where('id', $user->department_id)->value('college_id');
    }

    $announcements = App\Models\Announcement::with('sender')
        ->whereNotNull('target_role')
        ->where('status', 'published')
        ->where(function ($q) use ($user, $collegeId) {
            // Target matches user's role OR targets 'all'
            $q->where(function ($rq) use ($user) {
                $rq->where('target_role', $user->role)
                   ->orWhere('target_role', 'all');
            });
            // If target_college_id is set, user must match that college
            if ($collegeId) {
                $q->where(function ($cq) use ($collegeId) {
                    $cq->whereNull('target_college_id')
                       ->orWhere('target_college_id', $collegeId);
                });
            } else {
                $q->whereNull('target_college_id');
            }
        })
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(fn($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'sender_name' => $a->sender->name ?? 'مدير النظام',
            'attachment' => $a->attachment,
            'created_at' => $a->created_at,
        ]);

    return response()->json(['status' => 'success', 'announcements' => $announcements]);
});

Route::post('users/announcements', function (Request $request) {
    $userId = $request->user_id;
    $user = App\Models\User::find($userId);
    if (!$user) return response()->json(['status' => 'error', 'message' => 'المستخدم غير موجود'], 404);

    if ($user->role === 'doctor') {
        $offeringIds = App\Models\CourseOffering::where(function ($q) use ($userId) {
            $q->where('doctor_id', $userId)->orWhere('ta_id', $userId);
        })->pluck('id');
    } elseif ($user->role === 'student') {
        $offeringIds = App\Models\StudentEnrollment::where('student_id', $userId)->pluck('offering_id');
    } else {
        $offeringIds = [];
    }

    $subjectIds = !empty($offeringIds->toArray())
        ? App\Models\CourseOffering::whereIn('id', $offeringIds)->pluck('subject_id')
        : collect();

    $announcements = App\Models\Announcement::with('doctor')
        ->where(function ($q) use ($offeringIds, $subjectIds) {
            $q->whereIn('offering_id', $offeringIds);
            if ($subjectIds->isNotEmpty()) {
                $q->orWhere(function ($oq) use ($subjectIds) {
                    $oq->where('target_all', true)
                       ->whereHas('offering', fn($o) => $o->whereIn('subject_id', $subjectIds));
                });
            }
        })
        ->where('status', 'published')
        ->orderBy('created_at', 'desc')
        ->get()->map(fn($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'doctor_name' => $a->doctor->name ?? '',
            'offering_id' => $a->offering_id,
            'target_department' => $a->target_department,
            'target_all' => $a->target_all,
            'created_at' => $a->created_at,
        ]);
    return response()->json(['status' => 'success', 'announcements' => $announcements]);
});

Route::post('get_student_by_qr', function (Request $request) {
    $qrValue = $request->qr_code ?? $request->qr_value;
    $parts = explode(':', $qrValue);
    $studentId = (int) end($parts);
    $student = App\Models\User::where('id', $studentId)->where('role', 'student')->first();
    if (!$student) return response()->json(['status' => 'error', 'message' => 'الطالب غير موجود'], 404);

    $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

    $enrollments = App\Models\StudentEnrollment::with('offering.subject')
        ->where('student_id', $studentId)
        ->whereHas('offering', function ($q) use ($activeTermId) {
            $q->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId));
        })
        ->get();
    return response()->json(['status' => 'success', 'student' => [
        'id' => $student->id,
        'name' => $student->name,
        'academic_number' => $student->academic_number ?? '',
        'email' => $student->email,
    ], 'subjects' => $enrollments->map(fn($e) => [
        'offering_id' => $e->offering->id,
        'name' => $e->offering->subject->name ?? '',
    ])]);
});

Route::post('get_materials', function (Request $request) {
    $offering = App\Models\CourseOffering::find($request->offering_id);
    $subjectId = $offering ? $offering->subject_id : null;
    $materials = App\Models\CourseMaterial::where(function ($q) use ($request, $subjectId) {
        $q->where('offering_id', $request->offering_id);
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)
                   ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->orderBy('created_at', 'desc')->get();
    return response()->json(['status' => 'success', 'materials' => $materials]);
});

Route::post('get_offering_students', function (Request $request) {
    $students = App\Models\StudentEnrollment::with('student.department')
        ->where('offering_id', $request->offering_id)
        ->when($request->department_id, fn($q) => $q->whereHas('student', fn($sq) => $sq->where('department_id', $request->department_id)))
        ->get()->map(fn($e) => [
            'id' => $e->student_id,
            'name' => $e->student->name ?? '',
            'email' => $e->student->email ?? '',
            'academic_number' => $e->student->academic_number ?? '',
            'department_name' => $e->student->department->name ?? '',
            'level' => $e->student->level ?? '',
        ]);
    return response()->json(['status' => 'success', 'students' => $students]);
});

Route::post('get_sessions', function (Request $request) {
    $query = App\Models\AttendanceSession::with('offering.subject')
        ->orderBy('start_time', 'desc');
    if ($request->has('department_id')) {
        $deptId = (int) $request->department_id;
        $query->whereIn('id', function ($q) use ($deptId) {
            $q->select('attendance_session_id')
              ->from('attendance_session_departments')
              ->where('department_id', $deptId);
        });
    } else {
        $query->where('course_offering_id', $request->offering_id);
    }
    $sessions = $query->get()->map(fn($s) => [
        'id' => $s->id,
        'offering_id' => $s->course_offering_id,
        'title' => $s->offering->subject->name ?? 'جلسة',
        'session_date' => $s->session_date ?? $s->created_at->format('Y-m-d'),
        'department_name' => $s->offering->department->name ?? '',
        'department_ids' => DB::table('attendance_session_departments')
            ->where('attendance_session_id', $s->id)
            ->pluck('department_id')
            ->toArray(),
        'total_students' => App\Models\StudentEnrollment::where('offering_id', $s->course_offering_id)->count(),
        'present_count' => App\Models\Attendance::where('attendance_session_id', $s->id)->where('attendance_status', 'Present')->count(),
        'created_at' => $s->created_at,
    ]);
    return response()->json(['status' => 'success', 'sessions' => $sessions]);
});

Route::post('get_doctor_assignments', function (Request $request) {
    $id = $request->offering_id;
    $type = $request->type ?? 'assignment';
    $offering = App\Models\CourseOffering::find($id);
    $subjectId = $offering ? $offering->subject_id : null;
    $query = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)
          ->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)
                   ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    });
    if ($type === 'quiz') {
        $query->where('type', 'quiz');
    } else {
        $query->where('type', 'assignment');
    }
    // الدكتور العملي يشوف فقط التكاليف/الكويزات العملية
    if ($request->role === 'ta') {
        $query->where('category', 'practical');
    }
    $assignments = $query->with(['offerings', 'creator'])->orderBy('created_at', 'desc')->get()
        ->map(fn($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description,
            'due_date' => $a->due_date,
            'max_grade' => $a->max_grade,
            'assignment_number' => $a->assignment_number,
            'offering_id' => $a->offering_id ?? ($a->offerings->first()->id ?? null),
            'subject_id' => $subjectId,
            'department_name' => $a->offerings->first()->department->name ?? '',
            'creator_type' => $a->creator_type,
            'creator_name' => $a->creator->name ?? '',
            'creator_id' => $a->creator_id,
            'category' => $a->category ?? 'theoretical',
            'submitted_count' => App\Models\Submission::where('assignment_id', $a->id)->count(),
            'not_submitted_count' => (function() use ($a) {
                $pivotIds = $a->offerings()->pluck('course_offerings.id')->toArray();
                $allOfferingIds = !empty($pivotIds) ? $pivotIds : [$a->offering_id];
                $enrolledCount = App\Models\StudentEnrollment::whereIn('offering_id', $allOfferingIds)->count();
                return $enrolledCount - App\Models\Submission::where('assignment_id', $a->id)->count();
            })(),
            'created_at' => $a->created_at,
        ]);
    return response()->json(['status' => 'success', 'assignments' => $assignments]);
});

Route::post('get_doctor_grades', function (Request $request) {
    $id = $request->offering_id;
    $isTa = $request->role === 'ta';
    $offering = App\Models\CourseOffering::find($id);
    $subjectId = $offering ? $offering->subject_id : null;
    $sessionIds = App\Models\AttendanceSession::where('course_offering_id', $id)->pluck('id');
    $totalSessions = $sessionIds->count();
    $assignmentIds = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'assignment')->when($isTa, fn($q) => $q->where('category', 'practical'))->pluck('id');
    $quizIds = App\Models\Assignment::where(function ($q) use ($id, $subjectId) {
        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
        if ($subjectId) {
            $q->orWhere(function ($oq) use ($subjectId) {
                $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
            });
        }
    })->where('type', 'quiz')->when($isTa, fn($q) => $q->where('category', 'practical'))->pluck('id');
    $weights = DB::table('grade_weights')->where('offering_id', $id)->first();

    $students = App\Models\StudentEnrollment::with('student.department')
        ->where('offering_id', $id)
        ->get()
        ->map(function ($enrollment) use ($id, $sessionIds, $totalSessions, $assignmentIds, $quizIds, $weights) {
            $s = $enrollment->student;

            $attended = $totalSessions > 0
                ? App\Models\Attendance::where('student_id', $s->id)
                    ->whereIn('attendance_session_id', $sessionIds)
                    ->where('attendance_status', 'Present')->count()
                : 0;

            $assignmentSubs = App\Models\Submission::where('student_id', $s->id)
                ->whereIn('assignment_id', $assignmentIds)->whereNotNull('grade')->get();
            $assignmentEarned = $assignmentSubs->sum('grade');
            $assignmentPossible = App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');

            $quizSubs = App\Models\Submission::where('student_id', $s->id)
                ->whereIn('assignment_id', $quizIds)->whereNotNull('grade')->get();
            $quizEarned = $quizSubs->sum('grade');
            $quizPossible = App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');

            $grade = App\Models\Grade::where('student_id', $s->id)->where('offering_id', $id)->first();
            $midtermRaw = $grade->midterm_grade ?? 0;
            $finalRaw = $grade->final_exam_grade ?? 0;

            $attW = (float)($weights->attendance_weight ?? 10);
            $asgW = (float)($weights->assignments_weight ?? 10);
            $qzW = (float)($weights->quizzes_weight ?? 20);
            $midW = (float)($weights->midterm_weight ?? 20);
            $finW = (float)($weights->final_weight ?? 40);

            $attScore = $totalSessions > 0 ? round(($attended / $totalSessions) * $attW, 2) : 0;
            $asgScore = $assignmentPossible > 0 ? round(($assignmentEarned / $assignmentPossible) * $asgW, 2) : 0;
            $qzScore = $quizPossible > 0 ? round(($quizEarned / $quizPossible) * $qzW, 2) : 0;
            $midScore = round(($midtermRaw / 100) * $midW, 2);
            $finScore = round(($finalRaw / 100) * $finW, 2);
            $total = round($attScore + $asgScore + $qzScore + $midScore + $finScore, 2);

            return [
                'student_id' => $s->id,
                'name' => $s->name ?? '',
                'department' => $s->department->name ?? '',
                'attended_sessions' => $attended,
                'total_sessions' => $totalSessions,
                'assignment_earned' => $assignmentEarned,
                'assignment_possible' => $assignmentPossible,
                'quiz_earned' => $quizEarned,
                'quiz_possible' => $quizPossible,
                'midterm_raw' => $midtermRaw,
                'final_raw' => $finalRaw,
                'attendance_score' => $attScore,
                'assignments_score' => $asgScore,
                'quizzes_score' => $qzScore,
                'midterm_score' => $midScore,
                'final_score' => $finScore,
                'total_grade' => $total,
                'attendance_weight' => $attW,
                'assignments_weight' => $asgW,
                'quizzes_weight' => $qzW,
                'midterm_weight' => $midW,
                'final_weight' => $finW,
            ];
        });
    return response()->json(['status' => 'success', 'grades' => $students]);
});

Route::post('upload_materials', function (Request $request) {
    $request->validate([
        'offering_id' => 'required|integer',
        'doctor_id' => 'required|integer',
        'file' => 'required|file|max:102400',
    ]);
    $targetAll = $request->boolean('target_all', false);
    $material = App\Models\CourseMaterial::create([
        'offering_id' => $request->offering_id,
        'doctor_id' => $request->doctor_id,
        'file_path' => $request->file('file')->store('materials', 'public'),
        'file_name' => $request->file('file')->getClientOriginalName(),
        'target_all' => $targetAll,
        'file_size' => $request->file('file')->getSize(),
        'created_at' => now(),
    ]);
    return response()->json(['status' => 'success', 'message' => 'تم رفع الملف', 'data' => $material]);
});

Route::post('materials/delete', function (Request $request) {
    $material = App\Models\Material::find($request->material_id);
    if ($material) $material->delete();
    return response()->json(['status' => 'success', 'message' => 'تم حذف الملف']);
});

Route::post('assignments/create', function (Request $request) {
    // منع إنشاء تكليف/كويز مكرر لنفس المادة
    $existing = App\Models\Assignment::where('offering_id', $request->offering_id)
        ->where('title', $request->title)
        ->where('type', $request->type ?? (($request->is_quiz ?? false) ? 'quiz' : 'assignment'))
        ->first();
    if ($existing) {
        $typeLabel = ($request->type === 'quiz' || $request->is_quiz) ? 'الكويز' : 'التكليف';
        return response()->json(['status' => 'error', 'message' => "هذا $typeLabel موجود بالفعل"], 409);
    }
    $assignment = new App\Models\Assignment();
    $assignment->title = $request->title;
    $assignment->description = $request->description;
    $assignment->offering_id = $request->offering_id;
    $assignment->max_grade = $request->max_grade;
    $assignment->due_date = $request->due_date;
    $assignment->category = $request->category ?? 'theoretical';
    $assignment->type = $request->type ?? (($request->is_quiz ?? false) ? 'quiz' : 'assignment');
    $assignment->creator_id = $request->doctor_id ?? $request->creator_id ?? $request->user_id;
    $assignment->target_all = $request->boolean('target_all', false);
    if ($request->hasFile('attachment') || $request->hasFile('file')) {
        $assignment->file_path = $request->hasFile('file')
            ? $request->file('file')->store('assignments', 'public')
            : $request->file('attachment')->store('assignments', 'public');
    }
    $assignment->save();
    if ($request->offering_ids) {
        $offeringIds = is_array($request->offering_ids) ? $request->offering_ids : json_decode($request->offering_ids, true);
        $assignment->offerings()->sync($offeringIds);
    }
    $typeLabel = $assignment->type === 'quiz' ? 'الكويز' : 'التكليف';
    return response()->json(['status' => 'success', 'message' => "تم إنشاء $typeLabel"]);
});

Route::post('assignments/update', function (Request $request) {
    $assignment = App\Models\Assignment::find($request->assignment_id);
    if (!$assignment) return response()->json(['status' => 'error', 'message' => 'غير موجود'], 404);
    if ($request->has('title')) $assignment->title = $request->title;
    if ($request->has('description')) $assignment->description = $request->description;
    if ($request->has('max_grade')) $assignment->max_grade = $request->max_grade;
    if ($request->has('category')) $assignment->category = $request->category;
    if ($request->has('due_date')) $assignment->due_date = $request->due_date;
    if ($request->has('is_quiz')) $assignment->type = $request->is_quiz ? 'quiz' : 'assignment';
    if ($request->has('target_all')) $assignment->target_all = $request->boolean('target_all', false);
    if ($request->hasFile('attachment')) {
        $assignment->file_path = $request->file('attachment')->store('assignments', 'public');
    }
    $assignment->save();
    return response()->json(['status' => 'success', 'message' => 'تم التحديث']);
});

Route::post('submissions/grade', function (Request $request) {
    $submission = App\Models\Submission::find($request->submission_id);
    if (!$submission) return response()->json(['success' => false, 'message' => 'التسليم غير موجود'], 404);

    // التحقق من صلاحية الدكتور العملي: يسمح فقط بتصحيح تكاليف/كويزات عملية
    $doctorId = $request->doctor_id ?: $request->input('doctor_id');
    if ($doctorId) {
        $assignment = App\Models\Assignment::find($submission->assignment_id);
        if ($assignment) {
            if (isPracticalDoctorFor($doctorId, $assignment->offering_id)) {
                $category = $assignment->category ?? 'theoretical';
                if ($category !== 'practical') {
                    return response()->json(['success' => false, 'message' => 'الدكتور العملي لا يستطيع تصحيح التكاليف النظرية'], 403);
                }
            }
            // التحقق من ملكية النشاط: فقط منشئ التكليف/الكويز يمكنه التصحيح
            if ((int)$assignment->creator_id !== (int)$doctorId) {
                return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية تصحيح هذا النشاط لأنه تم إنشاؤه بواسطة دكتور آخر'], 403);
            }
        }
    }

    $submission->grade = $request->grade;
    $submission->notes = $request->notes ?? $request->doctor_notes ?? $submission->notes;
    $submission->save();

    // Notify student
    try {
        $assignment = App\Models\Assignment::with('offerings')->find($submission->assignment_id);
        $title = $assignment->title ?? 'التسليم';
        $gradeOfferingId = $assignment->offering_id ?? $assignment->offerings->first()->id ?? null;
        App\Models\Notification::create([
            'user_id' => $submission->student_id,
            'title' => 'تصحيح التسليم',
            'type' => 'assignment_graded',
            'message' => "تم تصحيح {$title}: {$request->grade} درجة",
            'notification_type' => 'assignment_graded',
            'reference_type' => 'submission',
            'reference_id' => $submission->id,
            'offering_id' => $gradeOfferingId,
            'is_read' => false,
            'created_at' => now(),
        ]);
    } catch (\Exception $e) { Log::error('notif grade: ' . $e->getMessage()); }

    // Also update grade record for the student in this offering and recalculate total
    try {
        $submissionAssignment = $submission->assignment()->with('offerings')->first();
        if ($submissionAssignment) {
            // Determine offering_id: try pivot offerings first, then direct offering_id
            $pivotOfferingIds = $submissionAssignment->offerings()->pluck('course_offerings.id')->toArray();
            $offeringId = !empty($pivotOfferingIds) ? $pivotOfferingIds[0] : $submissionAssignment->offering_id;
            if (!$offeringId) { $offeringId = $submissionAssignment->offerings()->first()->id ?? null; }
            if (!$offeringId) throw new \Exception('No offering found for assignment');
            $studentId = $submission->student_id;
            $gradeRec = App\Models\Grade::firstOrNew(['student_id' => $studentId, 'offering_id' => $offeringId]);

            // Recalculate all weighted components from scratch
            $weights = DB::table('grade_weights')->where('offering_id', $offeringId)->first();
            $wAtt = (float)($weights->attendance_weight ?? 0);
            $wAss = (float)($weights->assignments_weight ?? 0);
            $wQuiz = (float)($weights->quizzes_weight ?? 0);

            // Attendance
            $sessionIds = App\Models\AttendanceSession::where('course_offering_id', $offeringId)->pluck('id');
            $totalSessions = $sessionIds->count();
            $presentSessions = App\Models\Attendance::where('student_id', $studentId)
                ->whereIn('attendance_session_id', $sessionIds)
                ->where('attendance_status', 'Present')->count();
            $attPct = $totalSessions > 0 ? ($presentSessions / $totalSessions) : 0;
            $gradeRec->attendance_grade = round($attPct * $wAtt, 2);

            // Assignments (include pivot-linked)
            $assignmentIds = App\Models\Assignment::where(function ($q) use ($offeringId) {
                $q->where('offering_id', $offeringId)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $offeringId));
            })->where('type', 'assignment')->pluck('id');
            $aSubs = App\Models\Submission::where('student_id', $studentId)
                ->whereIn('assignment_id', $assignmentIds)->whereNotNull('grade')->get();
            $aEarned = $aSubs->sum('grade');
            $aMax = App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');
            $assPct = $aMax > 0 ? ($aEarned / $aMax) : 0;
            $gradeRec->assignments_grade = round($assPct * $wAss, 2);

            // Quizzes (include pivot-linked)
            $quizIds = App\Models\Assignment::where(function ($q) use ($offeringId) {
                $q->where('offering_id', $offeringId)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $offeringId));
            })->where('type', 'quiz')->pluck('id');
            $qSubs = App\Models\Submission::where('student_id', $studentId)
                ->whereIn('assignment_id', $quizIds)->whereNotNull('grade')->get();
            $qEarned = $qSubs->sum('grade');
            $qMax = App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');
            $quizPct = $qMax > 0 ? ($qEarned / $qMax) : 0;
            $gradeRec->quizzes_grade = round($quizPct * $wQuiz, 2);

            // Total (keep existing midterm/final)
            $mid = $gradeRec->midterm_grade ?? 0;
            $fin = $gradeRec->final_exam_grade ?? 0;
            $gradeRec->total_grade = round($gradeRec->attendance_grade + $gradeRec->assignments_grade + $gradeRec->quizzes_grade + $mid + $fin, 2);
            $gradeRec->save();
        }
    } catch (\Exception $e) { Log::error('grade rec update: ' . $e->getMessage()); }

    return response()->json(['success' => true, 'message' => 'تم تقييم التسليم']);
});

// ===================== Reports API =====================
Route::post('reports/grades', function (Request $request) {
    try {
        $subjectId = $request->subject_id;
        $departmentId = $request->department_id;
        $level = $request->level;
        $studyType = $request->study_type;
        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;

        if (!$subjectId) return response()->json(['success' => false, 'message' => 'الرجاء اختيار المادة']);

        $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
        if (!$activeTermId) return response()->json(['success' => false, 'message' => 'لا يوجد ترم نشط']);

        $offeringIds = App\Models\CourseOffering::where('subject_id', $subjectId)
            ->where('term_id', $activeTermId)->pluck('id');

        if ($offeringIds->isEmpty()) return response()->json(['success' => false, 'message' => 'لا توجد شعب لهذه المادة في الترم النشط']);

        $query = App\Models\StudentEnrollment::whereIn('offering_id', $offeringIds)
            ->with('student.department');

        if ($departmentId) $query->whereHas('student', fn($q) => $q->where('department_id', $departmentId));
        if ($level) $query->whereHas('student', fn($q) => $q->where('level', $level));
        if ($studyType) $query->whereHas('student', fn($q) => $q->where('study_type', $studyType));

        $enrollments = $query->get();

        $data = $enrollments->map(function ($enrollment) {
            $s = $enrollment->student;
            $offeringId = $enrollment->offering_id;

            $weights = DB::table('grade_weights')->where('offering_id', $offeringId)->first();
            $wAtt = (float)($weights->attendance_weight ?? 10);
            $wAss = (float)($weights->assignments_weight ?? 10);
            $wQuiz = (float)($weights->quizzes_weight ?? 20);
            $wMid = (float)($weights->midterm_weight ?? 20);
            $wFinal = (float)($weights->final_weight ?? 40);

            $grade = \App\Models\Grade::where('student_id', $s->id)->where('offering_id', $offeringId)->first();

            // Attendance
            $sessionIds = \App\Models\AttendanceSession::where('course_offering_id', $offeringId)->pluck('id');
            $totalSessions = $sessionIds->count();
            $presentSessions = \App\Models\Attendance::where('student_id', $s->id)
                ->whereIn('attendance_session_id', $sessionIds)
                ->where('attendance_status', 'Present')->count();
            $attPct = $totalSessions > 0 ? ($presentSessions / $totalSessions) : 0;
            $attendanceGrade = round($attPct * $wAtt, 2);

            // Assignments (include pivot-linked + target_all)
            $assignmentIds = \App\Models\Assignment::where(function ($q) use ($offeringId, $subjectId) {
                $q->where('offering_id', $offeringId)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $offeringId));
                if ($subjectId) {
                    $q->orWhere(function ($oq) use ($subjectId) {
                        $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                    });
                }
            })->where('type', 'assignment')->pluck('id');
            $allAssSubs = \App\Models\Submission::where('student_id', $s->id)->whereIn('assignment_id', $assignmentIds)->get();
            $totalAssignments = $assignmentIds->count();
            $submittedAssignments = $allAssSubs->count();
            $assEarned = $allAssSubs->whereNotNull('grade')->sum('grade');
            $assMax = \App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');
            $assPct = $assMax > 0 ? ($assEarned / $assMax) : 0;
            $assignmentsGrade = round($assPct * $wAss, 2);

            // Quizzes (include pivot-linked + target_all)
            $quizIds = \App\Models\Assignment::where(function ($q) use ($offeringId, $subjectId) {
                $q->where('offering_id', $offeringId)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $offeringId));
                if ($subjectId) {
                    $q->orWhere(function ($oq) use ($subjectId) {
                        $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                    });
                }
            })->where('type', 'quiz')->pluck('id');
            $allQuizSubs = \App\Models\Submission::where('student_id', $s->id)->whereIn('assignment_id', $quizIds)->get();
            $totalQuizzes = $quizIds->count();
            $submittedQuizzes = $allQuizSubs->count();
            $quizEarned = $allQuizSubs->whereNotNull('grade')->sum('grade');
            $quizMax = \App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');
            $quizPct = $quizMax > 0 ? ($quizEarned / $quizMax) : 0;
            $quizzesGrade = round($quizPct * $wQuiz, 2);

            $midtermGrade = $grade->midterm_grade ?? 0;
            $finalGrade = $grade->final_exam_grade ?? 0;
            $totalGrade = round($attendanceGrade + $assignmentsGrade + $quizzesGrade + $midtermGrade + $finalGrade, 2);
            $passed = $totalGrade >= 60;

            return [
                'name' => $s->name,
                'academic_number' => $s->academic_number ?? '-',
                'department_name' => $s->department->name ?? '',
                'level' => $s->level ?? '',
                'study_type' => $s->study_type ?? '',
                // Attendance
                'attended_sessions' => $presentSessions,
                'total_sessions' => $totalSessions,
                'attendance_grade' => $attendanceGrade,
                'attendance_weight' => $wAtt,
                // Assignments
                'submitted_assignments' => $submittedAssignments,
                'total_assignments' => $totalAssignments,
                'assignments_grade' => $assignmentsGrade,
                'assignments_weight' => $wAss,
                // Quizzes
                'submitted_quizzes' => $submittedQuizzes,
                'total_quizzes' => $totalQuizzes,
                'quizzes_grade' => $quizzesGrade,
                'quizzes_weight' => $wQuiz,
                // Midterm / Final
                'midterm_grade' => $midtermGrade,
                'midterm_weight' => $wMid,
                'final_exam_grade' => $finalGrade,
                'final_weight' => $wFinal,
                // Total
                'total_grade' => $totalGrade,
                'passed' => $passed,
            ];
        })->values();

        $passedCount = $data->filter(fn($s) => $s['passed'])->count();
        $failedCount = $data->count() - $passedCount;
        $grades = $data->pluck('total_grade');
        $avgGrade = $grades->count() > 0 ? round($grades->avg(), 2) : 0;
        $maxGrade = $grades->count() > 0 ? $grades->max() : 0;
        $minGrade = $grades->count() > 0 ? $grades->min() : 0;

        return response()->json(['success' => true, 'data' => $data, 'stats' => [
            'total' => $data->count(), 'passed' => $passedCount, 'failed' => $failedCount,
            'avg' => $avgGrade, 'max' => $maxGrade, 'min' => $minGrade,
        ]]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
});

Route::post('reports/attendance', function (Request $request) {
    try {
        $offeringId = $request->offering_id;
        $departmentId = $request->department_id;
        $level = $request->level;

        $sessions = DB::table('attendance_sessions')
            ->where('course_offering_id', $offeringId)->get();
        $sessionIds = $sessions->pluck('id');
        $totalSessions = $sessionIds->count();

        $query = DB::table('student_enrollments as se')
            ->join('users as s', 's.id', '=', 'se.student_id')
            ->join('departments as d', 'd.id', '=', 's.department_id')
            ->where('se.offering_id', $offeringId);

        if ($departmentId) $query->where('s.department_id', $departmentId);
        if ($level) $query->where('s.level', $level);

        $students = $query->select('s.id', 's.name', 's.academic_number', 'd.name as department_name', 's.level')->get();

        $data = $students->map(function($s) use ($sessionIds, $totalSessions) {
            $attended = DB::table('attendances')
                ->where('student_id', $s->id)
                ->whereIn('attendance_session_id', $sessionIds)
                ->where('attendance_status', 'Present')
                ->count();
            return [
                'name' => $s->name,
                'academic_number' => $s->academic_number,
                'department' => $s->department_name,
                'level' => $s->level,
                'attended' => $attended,
                'total' => $totalSessions,
                'percentage' => $totalSessions > 0 ? round(($attended / $totalSessions) * 100, 1) : 0,
            ];
        });

        return response()->json(['success' => true, 'data' => $data, 'sessions' => $sessions]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
});

Route::post('reports/submissions', function (Request $request) {
    try {
        $offeringId = $request->offering_id;
        $type = $request->type; // 'assignment' or 'quiz'
        $departmentId = $request->department_id;
        $level = $request->level;
        $offering = App\Models\CourseOffering::find($offeringId);
        $subjectId = $offering ? $offering->subject_id : null;

        $assignments = App\Models\Assignment::where(function ($q) use ($offeringId, $subjectId) {
            $q->where('offering_id', $offeringId)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $offeringId));
            if ($subjectId) {
                $q->orWhere(function ($oq) use ($subjectId) {
                    $oq->where('target_all', true)->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                });
            }
        });
        if ($type) $assignments->where('type', $type);
        $assignments = $assignments->get();

        $query = DB::table('student_enrollments as se')
            ->join('users as s', 's.id', '=', 'se.student_id')
            ->join('departments as d', 'd.id', '=', 's.department_id')
            ->where('se.offering_id', $offeringId);

        if ($departmentId) $query->where('s.department_id', $departmentId);
        if ($level) $query->where('s.level', $level);

        $students = $query->select('s.id', 's.name', 's.academic_number', 'd.name as department_name', 's.level')->get();

        $data = [];
        foreach ($assignments as $assignment) {
            foreach ($students as $student) {
                $submission = App\Models\Submission::where('assignment_id', $assignment->id)
                    ->where('student_id', $student->id)->first();
                $data[] = [
                    'assignment_title' => $assignment->title,
                    'student_name' => $student->name,
                    'academic_number' => $student->academic_number,
                    'department' => $student->department_name,
                    'level' => $student->level,
                    'submitted' => $submission ? 'نعم' : 'لا',
                    'submitted_at' => $submission ? $submission->created_at : null,
                    'grade' => $submission->grade ?? null,
                    'max_grade' => $assignment->max_grade,
                    'status' => $submission && $submission->grade !== null ? 'مصحح' : ($submission ? 'بانتظار التصحيح' : 'لم يسلم'),
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $data]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
});