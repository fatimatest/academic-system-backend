<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\CourseOffering;
use App\Models\Subject;
use App\Models\Assignment;
use App\Models\JoinRequest;
use App\Models\Grade;
use App\Models\AttendanceSession;
use App\Models\StudentEnrollment;
use App\Models\CourseMaterial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use App\Models\Notification;
use App\Models\CourseSetting;
use App\Models\Attendance;

class DoctorController extends Controller
{
    /**
     * جلب مواد الدكتور
     */
    public function getCourses($doctorId)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

            $offerings = CourseOffering::with(['subject', 'doctor', 'department'])
                ->where(function ($q) use ($doctorId) {
                    $q->where('doctor_id', $doctorId)
                      ->orWhere('ta_id', $doctorId);
                })
                ->where('term_id', $activeTermId)
                ->get()
                ->map(function ($offering) {
                    return [
                        'id' => $offering->id,
                        'subject_id' => $offering->subject_id,
                        'subject_name' => $offering->subject->name ?? 'غير معروف',
                        'subject_code' => $offering->subject->code ?? '',
                        'level' => $offering->level,
                        'department_name' => $offering->department->name ?? '',
                        'doctor_name' => $offering->doctor->name ?? '',
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $offerings
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getCourses: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب المواد',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب المواد الفريدة للدكتور (كل مادة مرة واحدة مع قائمة العروض المرتبطة)
     */
    public function getUniqueSubjects($doctorId)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

            $offerings = CourseOffering::with(['subject', 'doctor', 'department'])
                ->where(function ($q) use ($doctorId) {
                    $q->where('doctor_id', $doctorId)
                      ->orWhere('ta_id', $doctorId);
                })
                ->where('term_id', $activeTermId)
                ->get();

            $grouped = $offerings->groupBy('subject_id')->map(function ($items, $subjectId) {
                $first = $items->first();
                return [
                    'subject_id' => (int) $subjectId,
                    'subject_name' => $first->subject->name ?? 'غير معروف',
                    'subject_code' => $first->subject->code ?? '',
                    'doctor_name' => $first->doctor->name ?? '',
                    'offerings' => $items->map(function ($o) {
                        return [
                            'id' => $o->id,
                            'department_id' => $o->department_id,
                            'department_name' => $o->department->name ?? '',
                            'level' => $o->level,
                        ];
                    })->values(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $grouped
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getUniqueSubjects: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب المواد',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب إحصائيات الدكتور (الترم النشط)
     */
    public function getStats(Request $request, $doctorId)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
            $subjectId = $request->query('subject_id');

            $offeringsQuery = CourseOffering::where(function ($q) use ($doctorId) {
                $q->where('doctor_id', $doctorId)
                  ->orWhere('ta_id', $doctorId);
            });
            if ($activeTermId) {
                $offeringsQuery->where('term_id', $activeTermId);
            }
            if ($subjectId) {
                $offeringsQuery->where('subject_id', $subjectId);
            }

            $offeringIds = (clone $offeringsQuery)->pluck('id');

            $subjects = (clone $offeringsQuery)
                ->distinct('subject_id')
                ->count('subject_id');

            $levels = (clone $offeringsQuery)
                ->distinct('level')
                ->count('level');

            $departments = (clone $offeringsQuery)
                ->distinct('department_id')
                ->count('department_id');

            $students = DB::table('student_enrollments')
                ->join('course_offerings', 'student_enrollments.offering_id', '=', 'course_offerings.id')
                ->where(function ($q) use ($doctorId) {
                    $q->where('course_offerings.doctor_id', $doctorId)
                      ->orWhere('course_offerings.ta_id', $doctorId);
                })
                ->whereIn('course_offerings.id', $offeringIds)
                ->distinct('student_enrollments.student_id')
                ->count('student_enrollments.student_id');

            $quizzes = Assignment::where('type', 'quiz')
                ->where(function ($query) use ($doctorId, $offeringIds) {
                    $query->where('creator_id', $doctorId)
                        ->orWhereIn('offering_id', $offeringIds)
                        ->orWhereHas('offerings', fn($oq) => $oq->whereIn('offering_id', $offeringIds));
                })
                ->count();

            $assignments = Assignment::where('type', 'assignment')
                ->where(function ($query) use ($doctorId, $offeringIds) {
                    $query->where('creator_id', $doctorId)
                        ->orWhereIn('offering_id', $offeringIds)
                        ->orWhereHas('offerings', fn($oq) => $oq->whereIn('offering_id', $offeringIds));
                })
                ->count();

            $materials = CourseMaterial::whereIn('offering_id', $offeringIds)->count();

            $sessions = AttendanceSession::whereIn('course_offering_id', $offeringIds)->count();

            $pendingRequests = DB::table('join_requests')
                ->whereIn('offering_id', $offeringIds)
                ->where('status', 'pending')
                ->count();

            $submissions = DB::table('submissions')
                ->join('assignments', 'submissions.assignment_id', '=', 'assignments.id')
                ->where(function ($query) use ($doctorId, $offeringIds) {
                    $query->where('assignments.creator_id', $doctorId)
                        ->orWhereIn('assignments.offering_id', $offeringIds);
                })
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'subjects' => $subjects,
                    'levels' => $levels,
                    'departments' => $departments,
                    'students' => $students,
                    'quizzes' => $quizzes,
                    'assignments' => $assignments,
                    'materials' => $materials,
                    'sessions' => $sessions,
                    'pending_requests' => $pendingRequests,
                    'submissions' => $submissions,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getStats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب الإحصائيات',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب المواد الفريدة للدكتور في الترم النشط (بدون تكرار)
     */
    public function getActiveSubjects($doctorId)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

            $offerings = CourseOffering::with(['subject', 'department'])
                ->where(function ($q) use ($doctorId) {
                    $q->where('doctor_id', $doctorId)
                      ->orWhere('ta_id', $doctorId);
                })
                ->where('term_id', $activeTermId)
                ->get();

            $grouped = $offerings->groupBy('subject_id')->map(function ($items, $subjectId) {
                $first = $items->first();
                $offeringIds = $items->pluck('id')->toArray();

                $enrollmentCount = DB::table('student_enrollments')
                    ->whereIn('offering_id', $offeringIds)->distinct('student_id')->count('student_id');

                $joinRequestCount = DB::table('join_requests')
                    ->whereIn('offering_id', $offeringIds)->count();

                $assignmentCount = DB::table('assignments')
                    ->whereIn('offering_id', $offeringIds)->count();

                $quizCount = DB::table('assignments')
                    ->whereIn('offering_id', $offeringIds)->where('type', 'quiz')->count();

                $attendanceCount = DB::table('attendance_sessions')
                    ->whereIn('course_offering_id', $offeringIds)->count();

                $gradeCount = DB::table('grades')
                    ->whereIn('offering_id', $offeringIds)->count();

                return [
                    'subject_id' => (int) $subjectId,
                    'subject_name' => $first->subject->name ?? 'غير معروف',
                    'offering_ids' => $offeringIds,
                    'departments' => $items->pluck('department.name')->unique()->values()->toArray(),
                    'levels' => $items->pluck('level')->unique()->values()->toArray(),
                    'has_students' => $enrollmentCount > 0,
                    'has_join_requests' => $joinRequestCount > 0,
                    'has_assignments' => $assignmentCount > 0,
                    'has_quizzes' => $quizCount > 0,
                    'has_attendance' => $attendanceCount > 0,
                    'has_grades' => $gradeCount > 0,
                    'is_active' => $enrollmentCount > 0 || $joinRequestCount > 0 ||
                                   $assignmentCount > 0 || $quizCount > 0 ||
                                   $attendanceCount > 0 || $gradeCount > 0,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $grouped
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getActiveSubjects: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب المواد',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب طلبات الانضمام (مع الترشيح حسب الحالة والمادة)
     */
    public function getJoinRequests($doctorId, Request $request)
    {
        try {
            $status = $request->query('status', 'pending');
            $offeringId = $request->query('offering_id');

            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

            $query = JoinRequest::with(['student.department.college', 'offering.subject'])
                ->whereHas('offering', function ($q) use ($doctorId, $activeTermId) {
                    $q->where(function ($sq) use ($doctorId) {
                        $sq->where('doctor_id', $doctorId)
                           ->orWhere('ta_id', $doctorId);
                    });
                    if ($activeTermId) {
                        $q->where('term_id', $activeTermId);
                    }
                })
                ->where('status', $status);

            if ($offeringId) {
                $query->where('offering_id', $offeringId);
            }

            $requests = $query->get()
                ->map(function ($req) {
                    return [
                        'id' => $req->id,
                        'student_id' => $req->student_id,
                        'student_name' => $req->student->name ?? '',
                        'email' => $req->student->email ?? '',
                        'academic_number' => $req->student->academic_number ?? '',
                        'department_name' => $req->student->department->name ?? '',
                        'college_name' => $req->student->department->college->name ?? '',
                        'level' => $req->student->level ?? '',
                        'study_type' => $req->student->study_type ?? 'general',
                        'subject_name' => $req->offering->subject->name ?? '',
                        'offering_id' => $req->offering_id,
                        'status' => $req->status,
                        'created_at' => $req->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $requests
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getJoinRequests: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب طلبات الانضمام',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب الكويزات
     */
    public function getQuizzes(Request $request, $doctorId)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
            $subjectId = $request->input('subject_id');

            $quizzes = Assignment::with(['offering.subject', 'offerings.department'])
                ->where('type', 'quiz')
                ->where(function ($query) use ($doctorId) {
                    $query->where('creator_id', $doctorId)
                        ->orWhereHas('offering', function ($q) use ($doctorId) {
                            $q->where(function ($sq) use ($doctorId) {
                                $sq->where('doctor_id', $doctorId)
                                   ->orWhere('ta_id', $doctorId);
                            });
                        })
                        ->orWhereHas('offerings', function ($q) use ($doctorId) {
                            $q->where(function ($sq) use ($doctorId) {
                                $sq->where('doctor_id', $doctorId)
                                   ->orWhere('ta_id', $doctorId);
                            });
                        });
                })
                ->whereHas('offering', function ($q) use ($activeTermId) {
                    if ($activeTermId) {
                        $q->where('term_id', $activeTermId);
                    }
                })
                ->when($subjectId, function ($q) use ($subjectId) {
                    $ids = CourseOffering::where('subject_id', $subjectId)->pluck('id')->toArray();
                    $q->where(function ($sub) use ($ids) {
                        $sub->whereIn('offering_id', $ids)
                            ->orWhereHas('offerings', fn($oq) => $oq->whereIn('offering_id', $ids));
                    });
                })
                ->get()
                ->unique('id')
                ->map(function ($quiz) {
                    $deptNames = $quiz->offerings->pluck('department.name')->filter()->unique()->values();
                    return [
                        'id' => $quiz->id,
                        'title' => $quiz->title,
                        'offering_id' => $quiz->offering_id,
                        'subject_id' => $quiz->offering->subject_id ?? ($quiz->offerings->first()->subject_id ?? null),
                        'offering_name' => $quiz->offering->subject->name ?? '',
                        'creator_id' => $quiz->creator_id,
                        'offering_ids' => $quiz->offerings->pluck('id')->toArray(),
                        'department_names' => $deptNames->isEmpty() ? [] : $deptNames->toArray(),
                        'max_grade' => $quiz->max_grade,
                        'due_date' => $quiz->due_date,
                        'type' => $quiz->type,
                        'category' => $quiz->category,
                        'target_all' => $quiz->target_all,
                        'submission_count' => $quiz->submissions()->count(),
                        'created_at' => $quiz->created_at,
                    ];
                })->values();

            return response()->json([
                'success' => true,
                'data' => $quizzes
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getQuizzes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب الكويزات',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب التكاليف
     */
    public function getAssignments(Request $request, $doctorId)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
            $subjectId = $request->query('subject_id');

            $assignments = Assignment::with(['offering.subject', 'offerings.department'])
                ->where('type', 'assignment')
                ->where(function ($query) use ($doctorId) {
                    $query->where('creator_id', $doctorId)
                        ->orWhereHas('offering', function ($q) use ($doctorId) {
                            $q->where(function ($sq) use ($doctorId) {
                                $sq->where('doctor_id', $doctorId)
                                   ->orWhere('ta_id', $doctorId);
                            });
                        })
                        ->orWhereHas('offerings', function ($q) use ($doctorId) {
                            $q->where(function ($sq) use ($doctorId) {
                                $sq->where('doctor_id', $doctorId)
                                   ->orWhere('ta_id', $doctorId);
                            });
                        });
                })
                ->whereHas('offering', function ($q) use ($activeTermId) {
                    if ($activeTermId) {
                        $q->where('term_id', $activeTermId);
                    }
                })
                ->when($subjectId, function ($q) use ($subjectId) {
                    $ids = CourseOffering::where('subject_id', $subjectId)->pluck('id')->toArray();
                    $q->where(function ($sub) use ($ids) {
                        $sub->whereIn('offering_id', $ids)
                            ->orWhereHas('offerings', fn($oq) => $oq->whereIn('offering_id', $ids));
                    });
                })
                ->get()
                ->unique('id')
                ->map(function ($assignment) {
                    $deptNames = $assignment->offerings->pluck('department.name')->filter()->unique()->values();
                    return [
                        'id' => $assignment->id,
                        'title' => $assignment->title,
                        'offering_id' => $assignment->offering_id,
                        'subject_id' => $assignment->offering->subject_id ?? ($assignment->offerings->first()->subject_id ?? null),
                        'offering_name' => $assignment->offering->subject->name ?? '',
                        'creator_id' => $assignment->creator_id,
                        'offering_ids' => $assignment->offerings->pluck('id')->toArray(),
                        'department_names' => $deptNames->isEmpty() ? [] : $deptNames->toArray(),
                        'max_grade' => $assignment->max_grade,
                        'due_date' => $assignment->due_date,
                        'type' => $assignment->type,
                        'category' => $assignment->category,
                        'target_all' => $assignment->target_all,
                        'submission_count' => $assignment->submissions()->count(),
                        'created_at' => $assignment->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $assignments
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getAssignments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب التكاليف',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب الدرجات
     */
    public function getGrades($doctorId, Request $request)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
            $subjectId = $request->query('subject_id');

            $query = Grade::with(['student.department', 'offering.subject'])
                ->whereHas('offering', function ($q) use ($doctorId, $activeTermId, $subjectId) {
                    $q->where(function ($sq) use ($doctorId) {
                        $sq->where('doctor_id', $doctorId)
                           ->orWhere('ta_id', $doctorId);
                    });
                    if ($activeTermId) {
                        $q->where('term_id', $activeTermId);
                    }
                    if ($subjectId) {
                        $q->where('subject_id', $subjectId);
                    }
                });

            if ($request->has('department_id')) {
                $query->whereHas('student', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            $grades = $query->get()
                ->map(function ($grade) {
                    return [
                        'id' => $grade->id,
                        'student_id' => $grade->student_id,
                        'student_name' => $grade->student->name ?? '',
                        'major_name' => $grade->student->department->name ?? '',
                        'subject_name' => $grade->offering->subject->name ?? '',
                        'attendance_grade' => $grade->attendance_grade,
                        'assignments_grade' => $grade->assignments_grade,
                        'quizzes_grade' => $grade->quizzes_grade,
                        'midterm_grade' => $grade->midterm_grade,
                        'final_exam_grade' => $grade->final_exam_grade,
                        'total_grade' => $grade->total_grade,
                        'updated_at' => $grade->updated_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $grades
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getGrades: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب الدرجات',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * جلب جلسات الحضور
     */
    public function getAttendanceSessions(Request $request, $doctorId)
    {
        try {
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
            $subjectId = $request->query('subject_id');

            $offeringIds = CourseOffering::where(function ($q) use ($doctorId) {
                $q->where('doctor_id', $doctorId)
                  ->orWhere('ta_id', $doctorId);
            })
            ->when($activeTermId, fn($q) => $q->where('term_id', $activeTermId))
            ->when($subjectId, fn($q) => $q->where('subject_id', $subjectId))
            ->pluck('id');

            $sessions = AttendanceSession::with(['offering.subject'])
                ->whereIn('course_offering_id', $offeringIds)
                ->orderBy('start_time', 'desc')
                ->get()
                ->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'course_offering_id' => $session->course_offering_id,
                        'offering_name' => $session->offering->subject->name ?? '',
                        'session_token' => $session->session_token,
                        'qr_code_value' => $session->qr_code_value,
                        'start_time' => $session->start_time,
                        'end_time' => $session->end_time,
                        'is_active' => $session->status === 'Open',
                        'status' => $session->status,
                        'session_date' => $session->session_date,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $sessions
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getAttendanceSessions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في جلب جلسات الحضور',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * إنشاء جلسة حضور جديدة
     */
    public function createAttendanceSession(Request $request)
    {
        $request->validate([
            'offering_id' => 'required|integer|exists:course_offerings,id',
            'doctor_id' => 'required|integer|exists:users,id',
        ]);

        try {
            $offering = CourseOffering::with('subject')->find($request->offering_id);
            if (!$offering) {
                return response()->json(['success' => false, 'message' => 'Course Offering not found'], 404);
            }

            $lectureChoice = $request->lecture_choice ?? 1;
            $targetAll = $request->boolean('target_all', false);

            // If continuing an existing session (lecture_choice == 2)
            if ($lectureChoice == 2) {
                $todaySessions = AttendanceSession::where('course_offering_id', $request->offering_id)
                    ->where('session_date', now()->toDateString())
                    ->where('status', 'Open')
                    ->get();

                if ($todaySessions->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'لا توجد جلسات مفتوحة اليوم لاستكمالها',
                    ]);
                }

                $sessionsData = $todaySessions->map(fn($s) => [
                    'id' => $s->id,
                    'course_offering_id' => $s->course_offering_id,
                    'offering_name' => $offering->subject->name ?? '',
                    'session_token' => $s->session_token,
                    'qr_code_value' => $s->qr_code_value,
                    'session_date' => $s->session_date,
                    'start_time' => $s->start_time,
                    'status' => $s->status,
                    'attendee_count' => Attendance::where('attendance_session_id', $s->id)->count(),
                    'is_active' => true,
                ]);

                return response()->json([
                    'success' => true,
                    'is_continue' => true,
                    'sessions' => $sessionsData,
                    'message' => 'اختر جلسة لاستكمالها',
                ]);
            }

            // New lecture (lecture_choice == 1): prevent duplicate active session
            $activeSession = AttendanceSession::where('course_offering_id', $request->offering_id)
                ->where('status', 'Open')
                ->first();
            if ($activeSession) {
                return response()->json([
                    'success' => true,
                    'has_active' => true,
                    'message' => 'توجد جلسة نشطة بالفعل',
                    'data' => [
                        'id' => $activeSession->id,
                        'course_offering_id' => $activeSession->course_offering_id,
                        'offering_name' => $offering->subject->name ?? '',
                        'session_token' => $activeSession->session_token,
                        'qr_code_value' => $activeSession->qr_code_value,
                        'session_date' => $activeSession->session_date,
                        'start_time' => $activeSession->start_time,
                        'status' => $activeSession->status,
                        'is_active' => true,
                    ]
                ]);
            }

            $token = 'SES_' . $request->offering_id . '_' . sha1(uniqid() . time() . rand(1000, 9999));

            $session = AttendanceSession::create([
                'course_offering_id' => $request->offering_id,
                'doctor_id' => $request->doctor_id,
                'session_token' => $token,
                'qr_code_value' => 'RABET_SESSION:' . $token,
                'session_date' => now()->toDateString(),
                'start_time' => now(),
                'status' => 'Open',
            ]);

            // Link to departments
            if ($targetAll) {
                $allDeptIds = CourseOffering::where('subject_id', $offering->subject_id)
                    ->whereNotNull('department_id')
                    ->distinct('department_id')
                    ->pluck('department_id');
                foreach ($allDeptIds as $deptId) {
                    DB::table('attendance_session_departments')->insertOrIgnore([
                        'attendance_session_id' => $session->id,
                        'department_id' => $deptId,
                    ]);
                }
            } elseif ($request->has('department_ids') && is_array($request->department_ids)) {
                $deptIds = array_map('intval', $request->department_ids);
                if (!in_array($offering->department_id, $deptIds)) {
                    $deptIds[] = (int) $offering->department_id;
                }
                foreach ($deptIds as $deptId) {
                    DB::table('attendance_session_departments')->insertOrIgnore([
                        'attendance_session_id' => $session->id,
                        'department_id' => $deptId,
                    ]);
                }
            } else {
                DB::table('attendance_session_departments')->insertOrIgnore([
                    'attendance_session_id' => $session->id,
                    'department_id' => $offering->department_id,
                ]);
            }

            // Increment attendance_session_count in course_settings for new lectures
            try {
                $settings = CourseSetting::firstOrCreate(
                    ['offering_id' => $request->offering_id],
                    ['lecture_count' => 0, 'attendance_session_count' => 0, 'assignment_count' => 0, 'quiz_count' => 0]
                );
                $settings->attendance_session_count = ($settings->attendance_session_count ?? 0) + 1;
                $settings->save();
            } catch (\Exception $e) { Log::error('increment session count: ' . $e->getMessage()); }

            // Notify students
            try {
                $offeringIdsForNotif = CourseOffering::where('subject_id', $offering->subject_id)
                    ->where(function ($q) use ($request) {
                        $q->where('doctor_id', $request->doctor_id)
                          ->orWhere('ta_id', $request->doctor_id);
                    })->pluck('id');
                foreach ($offeringIdsForNotif as $oid) {
                    $this->notifyStudents($oid, 'تم فتح جلسة حضور جديدة', 'attendance_session', $session->id);
                }
            } catch (\Exception $e) { Log::error('notif createSession: ' . $e->getMessage()); }

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء جلسة الحضور بنجاح',
                'data' => [
                    'id' => $session->id,
                    'course_offering_id' => $session->course_offering_id,
                    'offering_name' => $offering->subject->name ?? '',
                    'department_id' => (int) $offering->department_id,
                    'department_ids' => $request->has('department_ids') && is_array($request->department_ids)
                        ? array_map('intval', $request->department_ids)
                        : [(int) $offering->department_id],
                    'session_token' => $session->session_token,
                    'qr_code_value' => $session->qr_code_value,
                    'session_date' => $session->session_date,
                    'start_time' => $session->start_time,
                    'status' => $session->status,
                    'is_active' => true,
                    'attendee_count' => 0,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in createAttendanceSession: ' . $e->getMessage(), [
                'offering_id' => $request->offering_id,
                'doctor_id' => $request->doctor_id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Database insert failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * إنشاء جلسة حضور لجميع تخصصات المادة
     */
    public function createAttendanceSessionForSubject(Request $request)
    {
        try {
            $request->validate([
                'subject_id' => 'required|integer|exists:subjects,id',
                'doctor_id' => 'required|integer|exists:users,id',
            ]);

            $offering = CourseOffering::where('subject_id', $request->subject_id)
                ->where(function ($q) use ($request) {
                    $q->where('doctor_id', $request->doctor_id)
                      ->orWhere('ta_id', $request->doctor_id);
                })
                ->first();

            if (!$offering) {
                return response()->json(['success' => false, 'message' => 'لا يوجد عرض للمادة'], 404);
            }

            $token = 'SES_' . $offering->id . '_' . sha1(uniqid() . time() . rand(1000, 9999));

            $session = AttendanceSession::create([
                'course_offering_id' => $offering->id,
                'doctor_id' => $request->doctor_id,
                'session_token' => $token,
                'qr_code_value' => 'RABET_SESSION:' . $token,
                'session_date' => now()->toDateString(),
                'start_time' => now(),
                'status' => 'Open',
            ]);

            // Link session to ALL departments of this subject's offerings
            $allOfferings = CourseOffering::where('subject_id', $request->subject_id)
                ->where(function ($q) use ($request) {
                    $q->where('doctor_id', $request->doctor_id)
                      ->orWhere('ta_id', $request->doctor_id);
                })
                ->get();

            foreach ($allOfferings as $o) {
                DB::table('attendance_session_departments')->insertOrIgnore([
                    'attendance_session_id' => $session->id,
                    'department_id' => $o->department_id,
                ]);
            }

            // Notify all enrolled students across all offerings
            foreach ($allOfferings as $o) {
                $this->notifyStudents($o->id, 'تم فتح جلسة حضور جديدة للمادة', 'attendance_session', $session->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء جلسة لجميع التخصصات',
                'data' => [
                    'id' => $session->id,
                    'course_offering_id' => $session->course_offering_id,
                    'offering_name' => $offering->subject->name ?? '',
                    'session_token' => $session->session_token,
                    'qr_code_value' => $session->qr_code_value,
                    'session_date' => $session->session_date,
                    'start_time' => $session->start_time,
                    'status' => $session->status,
                    'is_active' => true,
                    'attendee_count' => 0,
                    'all_offering_ids' => $allOfferings->pluck('id'),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in createAttendanceSessionForSubject: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'فشل إنشاء الجلسة'], 500);
        }
    }

    /**
     * إغلاق جلسة حضور
     */
    public function closeAttendanceSession($id)
    {
        try {
            $session = AttendanceSession::find($id);
            if (!$session) {
                return response()->json(['success' => false, 'message' => 'الجلسة غير موجودة'], 404);
            }
            $session->status = 'Closed';
            $session->end_time = now();
            $session->save();

            // Notify students
            try {
                $this->notifyStudents($session->course_offering_id, 'تم إغلاق جلسة الحضور', 'attendance_session', $session->id);
            } catch (\Exception $e) { Log::error('notif closeSession: ' . $e->getMessage()); }

            return response()->json([
                'success' => true,
                'message' => 'تم إغلاق الجلسة',
            ]);
        } catch (\Exception $e) {
            Log::error('Error in closeAttendanceSession: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'فشل إغلاق الجلسة'], 500);
        }
    }

    /**
     * جلب الحاضرين والغائبين لجلسة حضور
     */
    public function getSessionAttendees($id)
    {
        try {
            $session = AttendanceSession::with('offering.subject')->find($id);
            if (!$session) {
                return response()->json(['success' => false, 'message' => 'الجلسة غير موجودة'], 404);
            }

            $subjectId = $session->offering->subject_id ?? null;

            // Get the session's linked department IDs
            $sessionDeptIds = DB::table('attendance_session_departments')
                ->where('attendance_session_id', $session->id)
                ->pluck('department_id')
                ->toArray();

            // Get offerings of the same subject that belong to the session's departments
            $offeringIds = [];
            if ($subjectId) {
                $offeringQuery = CourseOffering::where('subject_id', $subjectId);
                if (!empty($sessionDeptIds)) {
                    $offeringQuery->whereIn('department_id', $sessionDeptIds);
                }
                $offeringIds = $offeringQuery->pluck('id')->toArray();
            }
            if (empty($offeringIds)) {
                $offeringIds = [$session->course_offering_id];
            }

            $attendances = DB::table('attendances')
                ->join('users', 'attendances.student_id', '=', 'users.id')
                ->where('attendances.attendance_session_id', $id)
                ->select('users.id as student_id', 'users.name as student_name', 'users.academic_number',
                    'attendances.attended_at', 'attendances.attendance_status as status')
                ->orderBy('attendances.attended_at', 'asc')
                ->get();

            $enrolledStudents = StudentEnrollment::with('student')
                ->whereIn('offering_id', $offeringIds)
                ->get();

            $attendedIds = $attendances->pluck('student_id')->toArray();
            $present = $attendances->map(function ($a) use ($offeringIds) {
                $deptName = DB::table('student_enrollments')
                    ->join('course_offerings', 'student_enrollments.offering_id', '=', 'course_offerings.id')
                    ->join('departments', 'course_offerings.department_id', '=', 'departments.id')
                    ->where('student_enrollments.student_id', $a->student_id)
                    ->whereIn('student_enrollments.offering_id', $offeringIds)
                    ->value('departments.name') ?? '';
                return [
                    'student_id' => $a->student_id,
                    'student_name' => $a->student_name,
                    'academic_number' => $a->academic_number ?? '',
                    'department' => $deptName,
                    'status' => 'Present',
                    'attended_at' => $a->attended_at,
                ];
            });

            $absent = [];
            foreach ($enrolledStudents as $enrollment) {
                $s = $enrollment->student;
                if (!$s) continue;
                if (in_array($s->id, $attendedIds)) continue;
                $deptName = DB::table('course_offerings')
                    ->join('departments', 'course_offerings.department_id', '=', 'departments.id')
                    ->where('course_offerings.id', $enrollment->offering_id)
                    ->value('departments.name') ?? '';
                $absent[] = [
                    'student_id' => $s->id,
                    'student_name' => $s->name ?? '',
                    'academic_number' => $s->academic_number ?? '',
                    'department' => $deptName,
                    'status' => 'Absent',
                    'attended_at' => null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'present' => $present,
                    'absent' => $absent,
                    'present_count' => count($present),
                    'absent_count' => count($absent),
                    'total' => count($present) + count($absent),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getSessionAttendees: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ في جلب الحضور'], 500);
        }
    }

    /**
     * تسجيل حضور طالب (عبر مسح QR أو إدخال رمز)
     */
    public function recordAttendance(Request $request)
    {
        try {
            $request->validate([
                'session_token' => 'required|string',
                'student_id' => 'required|integer|exists:users,id',
            ]);

            $session = AttendanceSession::where('session_token', $request->session_token)->first();
            if (!$session) {
                return response()->json(['success' => false, 'message' => 'رمز الجلسة غير صحيح'], 404);
            }
            if ($session->status !== 'Open') {
                return response()->json(['success' => false, 'message' => 'الجلسة مغلقة'], 400);
            }

            // Find ALL offerings of the same subject the student is enrolled in
            $sessionOffering = CourseOffering::with('subject')->find($session->course_offering_id);
            if (!$sessionOffering || !$sessionOffering->subject) {
                return response()->json(['success' => false, 'message' => 'المادة غير موجودة'], 404);
            }

            $allSubjectOfferingIds = CourseOffering::where('subject_id', $sessionOffering->subject_id)
                ->pluck('id')->toArray();

            $enrollment = StudentEnrollment::where('student_id', $request->student_id)
                ->whereIn('offering_id', $allSubjectOfferingIds)
                ->first();

            if (!$enrollment) {
                // Fallback: check approved join request
                $hasApprovedRequest = JoinRequest::where('student_id', $request->student_id)
                    ->whereIn('offering_id', $allSubjectOfferingIds)
                    ->where('status', 'approved')
                    ->exists();
                if (!$hasApprovedRequest) {
                    return response()->json([
                        'success' => false,
                        'message' => 'غير مسجل في هذه المادة',
                    ], 403);
                }
            }

            // Check if student's department matches the session's allowed departments
            $sessionDeptIds = DB::table('attendance_session_departments')
                ->where('attendance_session_id', $session->id)
                ->pluck('department_id')
                ->toArray();
            if (!empty($sessionDeptIds)) {
                $studentDept = User::where('id', $request->student_id)->value('department_id');
                if (!in_array($studentDept, $sessionDeptIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'هذه الجلسة ليست مخصصة لتخصصك الدراسي',
                    ], 403);
                }
            }

            // Verify student's level matches the session's offering
            $offering = $sessionOffering;
            $student = User::find($request->student_id);
            $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

            if ($offering && $student) {
                if ($offering->level != $student->level) {
                    return response()->json(['success' => false, 'message' => 'هذه الجلسة ليست مخصصة لمستواك الدراسي'], 403);
                }
                if ($offering->study_type !== 'both' && $student->study_type && $offering->study_type !== $student->study_type) {
                    return response()->json(['success' => false, 'message' => 'هذه الجلسة ليست مخصصة لنوع دراستك'], 403);
                }
                if ($activeTermId && $offering->term_id != $activeTermId) {
                    return response()->json(['success' => false, 'message' => 'هذه الجلسة غير متاحة في الترم الحالي'], 403);
                }
            }

            // Check if already recorded
            $existing = DB::table('attendances')
                ->where('attendance_session_id', $session->id)
                ->where('student_id', $request->student_id)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم تسجيل الحضور مسبقاً',
                    'data' => ['status' => 'already_recorded']
                ]);
            }

            DB::table('attendances')->insert([
                'attendance_session_id' => $session->id,
                'student_id' => $request->student_id,
                'attendance_status' => 'Present',
                'attended_at' => now(),
                'created_at' => now(),
            ]);

            // Update attendances count in course_settings
            try {
                $settings = CourseSetting::firstOrCreate(
                    ['offering_id' => $session->course_offering_id],
                    ['lecture_count' => 0, 'attendance_session_count' => 0, 'assignment_count' => 0, 'quiz_count' => 0]
                );
                $totalAttendances = Attendance::whereIn('attendance_session_id',
                    AttendanceSession::where('course_offering_id', $session->course_offering_id)->pluck('id')
                )->where('student_id', $request->student_id)->count();
                $settings->attendance_session_count = max($settings->attendance_session_count, $totalAttendances);
                $settings->save();
            } catch (\Exception $e) { Log::error('update course_settings record: ' . $e->getMessage()); }

            // Update or create grade record
            try {
                $enrolledOffering = CourseOffering::find($session->course_offering_id);
                if ($enrolledOffering) {
                    $allSessions = AttendanceSession::where('course_offering_id', $session->course_offering_id)->count();
                    $attendedCount = Attendance::whereIn('attendance_session_id',
                        AttendanceSession::where('course_offering_id', $session->course_offering_id)->pluck('id')
                    )->where('student_id', $request->student_id)->count();
                    $weights = DB::table('grade_weights')->where('offering_id', $session->course_offering_id)->first();
                    $attW = (float)($weights->attendance_weight ?? 10);
                    $attGrade = $allSessions > 0 ? round(($attendedCount / $allSessions) * $attW, 2) : 0;
                    DB::table('grades')->updateOrInsert(
                        ['student_id' => $request->student_id, 'offering_id' => $session->course_offering_id],
                        ['attendance_grade' => $attGrade, 'updated_at' => now()]
                    );
                }
            } catch (\Exception $e) { Log::error('grade update record: ' . $e->getMessage()); }

            // Log to audit log
            try {
                DB::table('audit_logs')->insert([
                    'user_id' => $request->student_id,
                    'action' => 'تسجيل حضور',
                    'details' => 'تم تسجيل حضور الطالب',
                    'target_id' => $session->id,
                    'target_type' => 'attendance_session',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {}

            // Notify student
            try {
                $offeringName = CourseOffering::with('subject')->find($session->course_offering_id)->subject->name ?? '';
                Notification::create([
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
            } catch (\Exception $e) { Log::error('notif recordAttendance: ' . $e->getMessage()); }

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الحضور بنجاح',
            ]);
        } catch (\Exception $e) {
            Log::error('Error in recordAttendance: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'فشل تسجيل الحضور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * معالجة طلب الانضمام (قبول / رفض) مع دعم القبول الموثق
     */
    public function processJoinRequest(Request $request, $id)
    {
        try {
            $joinRequest = JoinRequest::with(['student', 'offering'])->find($id);

            if (!$joinRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'الطلب غير موجود'
                ], 404);
            }

            $action = $request->input('action', 'reject'); // approve | approved | reject | rejected
            $verifyFirst = $request->input('verify_first', false);
            $rejectionReason = $request->input('rejection_reason', '');

            // Normalize: Flutter sends 'approved', React sends 'approve'
            if (in_array($action, ['rejected', 'reject'])) {
                $action = 'reject';
            } elseif (in_array($action, ['approved', 'approve'])) {
                $action = 'approve';
            }

            if ($action === 'reject') {
                $joinRequest->status = 'rejected';
                $joinRequest->rejection_reason = $rejectionReason ?: 'تم رفض الطلب من قبل الدكتور';
                $joinRequest->save();

                // Notify student
                try {
                    $offeringName = $joinRequest->offering->subject->name ?? '';
                    Notification::create([
                        'user_id' => $joinRequest->student_id,
                        'title' => 'رفض طلب الانضمام',
                        'type' => 'enrollment_rejected',
                        'message' => "تم رفض طلبك لـ {$offeringName}" . ($rejectionReason ? ": {$rejectionReason}" : ''),
                        'notification_type' => 'enrollment_rejected',
                        'reference_type' => 'join_request',
                        'reference_id' => $joinRequest->id,
                        'offering_id' => $joinRequest->offering_id,
                        'is_read' => false,
                        'created_at' => now(),
                    ]);
                } catch (\Exception $e) { Log::error('notif reject: ' . $e->getMessage()); }

                $this->logAction(auth()->id(), 'رفض طلب ارتباط', 'تم رفض طلب الارتباط', $joinRequest->id, 'join_request');

                return response()->json([
                    'success' => true,
                    'message' => 'تم رفض الطلب'
                ]);
            }

            if ($action === 'approve') {
                $student = $joinRequest->student;
                $offering = $joinRequest->offering;

                // التحقق الموثق: التأكد من وجود الطالب في قاعدة البيانات الرسمية (official_students)
                if ($verifyFirst) {
                    $studentExists = false;
                    if ($student && $student->academic_number && $offering) {
                        $collegeId = DB::table('departments')->where('id', $offering->department_id)->value('college_id');
                        $studentExists = DB::table('official_students')
                            ->where('college_id', $collegeId)
                            ->where('academic_number', $student->academic_number)
                            ->exists();
                    }
                    if (!$student || !$studentExists) {
                        return response()->json([
                            'success' => false,
                            'message' => 'الطالب غير مسجل في الكلية'
                        ], 422);
                    }
                }

                // التحقق من تطابق بيانات الطالب مع المقرر قبل التسجيل
                $activeTermId = DB::table('terms')->where('status', 'active')->value('id');

                if ($offering && $student) {
                    if ($offering->department_id != $student->department_id) {
                        return response()->json(['success' => false, 'message' => 'المقرر غير مخصص لتخصص هذا الطالب'], 403);
                    }
                    if ($offering->level != $student->level) {
                        return response()->json(['success' => false, 'message' => 'المقرر غير مخصص لمستوى هذا الطالب'], 403);
                    }
                    if ($activeTermId && $offering->term_id != $activeTermId) {
                        return response()->json(['success' => false, 'message' => 'المقرر غير متاح في الترم الحالي'], 403);
                    }
                    if ($student->study_type && $student->study_type !== 'both' && $offering->study_type !== 'both' && $offering->study_type !== $student->study_type) {
                        return response()->json(['success' => false, 'message' => 'نوع الدراسة غير متطابق'], 403);
                    }
                }

                // ربط الطالب بالمادة (تسجيله في student_enrollments)
                $existing = StudentEnrollment::where('student_id', $joinRequest->student_id)
                    ->where('offering_id', $joinRequest->offering_id)
                    ->first();

                if (!$existing) {
                    StudentEnrollment::create([
                        'student_id' => $joinRequest->student_id,
                        'offering_id' => $joinRequest->offering_id,
                        'enrolled_at' => now(),
                    ]);
                }

                // قبول الطلب — يتم الآن فقط بعد نجاح جميع التحققات والتسجيل
                $joinRequest->status = 'approved';
                $joinRequest->save();

                // Notify student
                try {
                    $offeringName = $joinRequest->offering->subject->name ?? '';
                    Notification::create([
                        'user_id' => $joinRequest->student_id,
                        'title' => 'قبول طلب الانضمام',
                        'type' => 'enrollment_accepted',
                        'message' => "تم قبول طلبك لـ {$offeringName}",
                        'notification_type' => 'enrollment_accepted',
                        'reference_type' => 'join_request',
                        'reference_id' => $joinRequest->id,
                        'offering_id' => $joinRequest->offering_id,
                        'is_read' => false,
                        'created_at' => now(),
                    ]);
                } catch (\Exception $e) { Log::error('notif approve: ' . $e->getMessage()); }

                $this->logAction(auth()->id(), 'قبول طلب ارتباط', 'تم قبول طلب الارتباط', $joinRequest->id, 'join_request');

                return response()->json([
                    'success' => true,
                    'message' => 'تم قبول الطلب'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'إجراء غير معروف'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error in processJoinRequest: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء معالجة الطلب',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * معالجة مجموعة من طلبات الانضمام دفعة واحدة (لـ "قبول الكل" و"قبول الكل موثق")
     * POST /join-requests/batch-verify
     */
    public function batchVerifyJoinRequests(Request $request)
    {
        try {
            $ids = $request->input('request_ids', []);
            $verifyFirst = $request->input('verify_first', false);

            if (empty($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'يرجى تحديد الطلبات'
                ], 400);
            }

            $requests = JoinRequest::with(['student', 'offering'])
                ->whereIn('id', $ids)
                ->where('status', 'pending')
                ->get();

            $accepted = [];
            $rejected = [];
            $total = $requests->count();

            foreach ($requests as $joinRequest) {
                $student = $joinRequest->student;
                $offering = $joinRequest->offering;

                // القبول الموثق: التحقق من official_students
                if ($verifyFirst) {
                    $studentExists = false;
                    if ($student && $student->academic_number && $offering) {
                        $collegeId = DB::table('departments')->where('id', $offering->department_id)->value('college_id');
                        $studentExists = DB::table('official_students')
                            ->where('college_id', $collegeId)
                            ->where('academic_number', $student->academic_number)
                            ->exists();
                    }
                    if (!$student || !$studentExists) {
                        $rejected[] = [
                            'id' => $joinRequest->id,
                            'student_name' => $student->name ?? 'غير معروف',
                            'reason' => 'غير مسجل في قاعدة البيانات الرسمية'
                        ];
                        continue;
                    }
                }

                // تطابق بيانات الطالب مع المقرر
                $activeTermId = DB::table('terms')->where('status', 'active')->value('id');
                $canAccept = true;
                $rejectReason = '';

                if ($offering && $student) {
                    if ($offering->department_id != $student->department_id) {
                        $canAccept = false;
                        $rejectReason = 'المقرر غير مخصص لتخصص الطالب';
                    } elseif ($offering->level != $student->level) {
                        $canAccept = false;
                        $rejectReason = 'المقرر غير مخصص لمستوى الطالب';
                    } elseif ($activeTermId && $offering->term_id != $activeTermId) {
                        $canAccept = false;
                        $rejectReason = 'المقرر غير متاح في الترم الحالي';
                    } elseif ($student->study_type && $student->study_type !== 'both' && $offering->study_type !== 'both' && $offering->study_type !== $student->study_type) {
                        $canAccept = false;
                        $rejectReason = 'نوع الدراسة غير متطابق';
                    }
                }

                if (!$canAccept) {
                    $rejected[] = [
                        'id' => $joinRequest->id,
                        'student_name' => $student->name ?? 'غير معروف',
                        'reason' => $rejectReason
                    ];
                    continue;
                }

                // إنشاء التسجيل
                $existing = StudentEnrollment::where('student_id', $joinRequest->student_id)
                    ->where('offering_id', $joinRequest->offering_id)
                    ->first();

                if (!$existing) {
                    StudentEnrollment::create([
                        'student_id' => $joinRequest->student_id,
                        'offering_id' => $joinRequest->offering_id,
                        'enrolled_at' => now(),
                    ]);
                }

                $joinRequest->status = 'approved';
                $joinRequest->save();

                // إرسال إشعار للطالب
                try {
                    $offeringName = $joinRequest->offering->subject->name ?? '';
                    Notification::create([
                        'user_id' => $joinRequest->student_id,
                        'title' => 'قبول طلب الانضمام',
                        'type' => 'enrollment_accepted',
                        'message' => "تم قبول طلبك لـ {$offeringName}",
                        'notification_type' => 'enrollment_accepted',
                        'reference_type' => 'join_request',
                        'reference_id' => $joinRequest->id,
                        'offering_id' => $joinRequest->offering_id,
                        'is_read' => false,
                        'created_at' => now(),
                    ]);
                } catch (\Exception $e) {
                    Log::error('notif batch approve: ' . $e->getMessage());
                }

                $this->logAction(auth()->id(), 'قبول طلب ارتباط (دفعة)', "تم قبول طلب {$student->name}", $joinRequest->id, 'join_request');

                $accepted[] = [
                    'id' => $joinRequest->id,
                    'student_name' => $student->name ?? '',
                ];
            }

            return response()->json([
                'success' => true,
                'message' => "تم فحص {$total} طلباً. تم قبول " . count($accepted) . "، ورفض " . count($rejected) . ".",
                'data' => [
                    'total' => $total,
                    'accepted_count' => count($accepted),
                    'rejected_count' => count($rejected),
                    'accepted' => $accepted,
                    'rejected' => $rejected,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in batchVerifyJoinRequests: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء المعالجة'
            ], 500);
        }
    }

    /**
     * جلب تفاصيل المقرر الدراسي
     */
    public function getOffering($id)
    {
        try {
            $offering = CourseOffering::with(['subject', 'doctor', 'department', 'term', 'ta'])->find($id);
            if (!$offering) {
                return response()->json(['success' => false, 'message' => 'المقرر غير موجود'], 404);
            }
            $departments = DB::table('course_offerings as co')
                ->join('departments', 'co.department_id', '=', 'departments.id')
                ->where('co.subject_id', $offering->subject_id)
                ->where('co.term_id', $offering->term_id)
                ->where('co.doctor_id', $offering->doctor_id)
                ->select('departments.id', 'departments.name')
                ->distinct()->get();
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $offering->id,
                    'subject_id' => $offering->subject_id,
                    'subject_name' => $offering->subject->name ?? '',
                    'subject_code' => $offering->subject->code ?? '',
                    'doctor_id' => $offering->doctor_id,
                    'doctor_name' => $offering->doctor->name ?? '',
                    'ta_id' => $offering->ta_id,
                    'ta_name' => $offering->ta->name ?? '',
                    'department_id' => $offering->department_id,
                    'department_name' => $offering->department->name ?? '',
                    'departments' => $departments,
                    'level' => $offering->level,
                    'term_id' => $offering->term_id,
                    'term_name' => $offering->term->name ?? '',
                    'study_type' => $offering->study_type ?? '',
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getOffering: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * رفع ملف مقرر تعليمي
     */
    public function uploadMaterial(Request $request, $id)
    {
        try {
            $offering = CourseOffering::find($id);
            if (!$offering) {
                return response()->json(['success' => false, 'message' => 'المقرر غير موجود'], 404);
            }

            $maxSizeMB = (int) DB::table('system_settings')->where('key', 'max_file_size')->value('value') ?: 10;
            $allowedTypes = DB::table('system_settings')->where('key', 'allowed_file_types')->value('value') ?: 'pdf,doc,docx,jpg,png,zip';
            $maxSizeKB = $maxSizeMB * 1024;
            $extensions = str_replace(',', '|', $allowedTypes);

            $request->validate([
                'file' => 'required|file|max:' . $maxSizeKB . '|mimes:' . $allowedTypes,
            ]);

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $path = $file->store('materials/' . $id, 'public');

            $targetAll = $request->boolean('target_all', false);

            $material = CourseMaterial::create([
                'offering_id' => $id,
                'doctor_id' => $request->input('doctor_id', $offering->doctor_id),
                'file_name' => $originalName,
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'target_all' => $targetAll,
            ]);

            // Notify students of all offerings for this subject if target_all
            if ($targetAll) {
                $allOfferingIds = CourseOffering::where('subject_id', $offering->subject_id)
                    ->where(function ($q) {
                        $q->where('doctor_id', auth()->id())
                          ->orWhere('ta_id', auth()->id());
                    })
                    ->pluck('id');
                foreach ($allOfferingIds as $oid) {
                    $this->notifyStudents($oid, 'تم رفع مقرر دراسي جديد: ' . $originalName, 'material', $material->id);
                }
            } else {
                $this->notifyStudents($id, 'تم رفع مقرر دراسي جديد: ' . $originalName, 'material', $material->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم رفع الملف بنجاح',
                'data' => $material
            ]);
        } catch (\Exception $e) {
            Log::error('Error in uploadMaterial: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'فشل رفع الملف: ' . $e->getMessage()], 500);
        }
    }

    /**
     * جلب ملفات المقرر
     */
    public function getMaterials($id)
    {
        try {
            $offering = CourseOffering::find($id);
            $subjectId = $offering ? $offering->subject_id : null;
            $materials = CourseMaterial::with('doctor')
                ->where(function ($q) use ($id, $subjectId) {
                    $q->where('offering_id', $id);
                    if ($subjectId) {
                        $q->orWhere(function ($oq) use ($subjectId) {
                            $oq->where('target_all', true)
                               ->whereHas('offering', fn($o) => $o->where('subject_id', $subjectId));
                        });
                    }
                })
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'file_name' => $m->file_name,
                        'file_path' => $m->file_path,
                        'file_size' => $m->file_size,
                        'doctor_name' => $m->doctor->name ?? '',
                        'target_all' => $m->target_all,
                        'created_at' => $m->created_at,
                    ];
                });

            return response()->json(['success' => true, 'data' => $materials]);
        } catch (\Exception $e) {
            Log::error('Error in getMaterials: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * حذف ملف مقرر
     */
    public function deleteMaterial($id)
    {
        try {
            $material = CourseMaterial::find($id);
            if (!$material) {
                return response()->json(['success' => false, 'message' => 'الملف غير موجود'], 404);
            }

            DB::beginTransaction();

            // حذف الملف من التخزين
            if ($material->file_path && Storage::disk('public')->exists($material->file_path)) {
                Storage::disk('public')->delete($material->file_path);
            }

            // حذف الإشعارات المرتبطة بالمادة
            Notification::where('reference_type', 'material')
                ->where('reference_id', $id)
                ->delete();

            $material->delete();

            DB::commit();

            $this->logAction(auth()->id(), 'حذف محاضرة', 'تم حذف المحاضرة: ' . $material->file_name, $id, 'material');

            return response()->json(['success' => true, 'message' => 'تم حذف الملف وجميع البيانات المرتبطة به']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in deleteMaterial: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * حذف تكليف أو كويز مع جميع البيانات المرتبطة
     */
    public function deleteAssignment($id)
    {
        try {
            $assignment = Assignment::with(['submissions'])->find($id);
            if (!$assignment) {
                return response()->json(['success' => false, 'message' => 'التكليف غير موجود'], 404);
            }

            DB::beginTransaction();

            $submissionIds = $assignment->submissions()->pluck('id');
            $isQuiz = $assignment->type === 'quiz';

            // حذف ملفات التسليمات من التخزين
            $submissions = $assignment->submissions()->get();
            foreach ($submissions as $sub) {
                if ($sub->file_path && Storage::disk('public')->exists($sub->file_path)) {
                    Storage::disk('public')->delete($sub->file_path);
                }
            }

            // حذف الإشعارات المرتبطة بالتسليمات
            if ($submissionIds->isNotEmpty()) {
                Notification::where('reference_type', 'submission')
                    ->whereIn('reference_id', $submissionIds)
                    ->delete();
            }

            // حذف التسليمات
            $assignment->submissions()->delete();

            // حذف محاولات الكويز وإجاباتها إذا كان العنصر كويزاً
            if ($isQuiz) {
                DB::table('quiz_attempts')->where('assignment_id', $id)->delete();
                DB::table('quiz_answers')->where('assignment_id', $id)->delete();
            }

            // حذف درجات التكليف/الكويز من جدول grades_detail
            if (Schema::hasTable('grades_detail')) {
                DB::table('grades_detail')->where('assignment_id', $id)->delete();
            }

            // حذف الروابط في جدول الـ pivot
            DB::table('assignment_offering')->where('assignment_id', $id)->delete();

            // حذف الإشعارات المرتبطة بالتكليف/الكويز
            Notification::where(function ($q) use ($id) {
                    $q->where('reference_type', 'assignment')
                      ->orWhere('reference_type', 'quiz');
                })
                ->where('reference_id', $id)
                ->delete();

            $actionLabel = $isQuiz ? 'حذف كويز' : 'حذف تكليف';
            $actionName = $isQuiz ? 'الكويز' : 'التكليف';

            // حذف التكليف/الكويز
            $assignment->delete();

            DB::commit();

            $this->logAction(auth()->id(), $actionLabel, 'تم حذف ' . $actionName . ': ' . $assignment->title, $id, $isQuiz ? 'quiz' : 'assignment');

            return response()->json(['success' => true, 'message' => 'تم حذف ' . $actionName . ' وجميع البيانات المرتبطة به']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in deleteAssignment: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء حذف التكليف'], 500);
        }
    }

    /**
     * حذف جلسة حضور مع جميع سجلات الحضور المرتبطة
     */
    public function deleteAttendanceSession($id)
    {
        try {
            $session = AttendanceSession::find($id);
            if (!$session) {
                return response()->json(['success' => false, 'message' => 'الجلسة غير موجودة'], 404);
            }

            DB::beginTransaction();

            // حذف سجلات الحضور
            $session->attendances()->delete();

            // حذف الروابط في جدول الأقسام
            DB::table('attendance_session_departments')
                ->where('attendance_session_id', $id)
                ->delete();

            // حذف الإشعارات المرتبطة بالجلسة
            Notification::where('reference_type', 'attendance_session')
                ->where('reference_id', $id)
                ->delete();

            // حذف الجلسة
            $session->delete();

            DB::commit();

            $this->logAction(auth()->id(), 'حذف جلسة حضور', 'تم حذف جلسة الحضور', $id, 'attendance_session');

            return response()->json(['success' => true, 'message' => 'تم حذف الجلسة وجميع سجلات الحضور']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in deleteAttendanceSession: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء حذف الجلسة'], 500);
        }
    }

    /**
     * جلب الطلاب المرتبطين بالمقرر
     */
    public function getOfferingStudents(Request $request, $id)
    {
        try {
            $isPractical = $request->query('doctor_id') && isPracticalDoctorFor($request->query('doctor_id'), $id);

            $weights = DB::table('grade_weights')->where('offering_id', $id)->first();
            $wAtt = (float)($weights->attendance_weight ?? 0);
            $wAss = (float)($weights->assignments_weight ?? 0);
            $wQuiz = (float)($weights->quizzes_weight ?? 0);
            $wMid = (float)($weights->midterm_weight ?? 0);
            $wFinal = (float)($weights->final_weight ?? 0);

            $students = StudentEnrollment::where('offering_id', $id)
                ->with('student.department')
                ->get()
                ->map(function ($enrollment) use ($id, $wAtt, $wAss, $wQuiz, $wMid, $wFinal, $isPractical) {
                    $s = $enrollment->student;
                    $grade = \App\Models\Grade::where('student_id', $s->id)
                        ->where('offering_id', $id)->first();

                    $sessionIds = \App\Models\AttendanceSession::where('course_offering_id', $id)->pluck('id');
                    $totalSessions = $sessionIds->count();
                    $presentSessions = \App\Models\Attendance::where('student_id', $s->id)
                        ->whereIn('attendance_session_id', $sessionIds)
                        ->where('attendance_status', 'Present')
                        ->count();
                    $attPct = $totalSessions > 0 ? ($presentSessions / $totalSessions) : 0;
                    $attendanceGrade = round($attPct * $wAtt, 2);

                    // تصفية التكاليف والكويزات حسب نوع الدكتور (مع pivot)
                    $assignmentIdsQuery = \App\Models\Assignment::where(function ($q) use ($id) {
                        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
                    })->where('type', 'assignment');
                    if ($isPractical) $assignmentIdsQuery->where('category', 'practical');
                    $assignmentIds = $assignmentIdsQuery->pluck('id');
                    $allSubmissions = \App\Models\Submission::where('student_id', $s->id)
                        ->whereIn('assignment_id', $assignmentIds)->get();
                    $gradedSubmissions = $allSubmissions->filter(fn($sub) => $sub->grade !== null);
                    $submittedAssignmentsCount = $allSubmissions->count();
                    $totalAssignmentsCount = $assignmentIds->count();
                    $earned = $gradedSubmissions->sum('grade');
                    $totalMax = \App\Models\Assignment::whereIn('id', $assignmentIds)->sum('max_grade');
                    $assPct = $totalMax > 0 ? ($earned / $totalMax) : 0;
                    $assignmentsGrade = round($assPct * $wAss, 2);

                    $quizIdsQuery = \App\Models\Assignment::where(function ($q) use ($id) {
                        $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
                    })->where('type', 'quiz');
                    if ($isPractical) $quizIdsQuery->where('category', 'practical');
                    $quizIds = $quizIdsQuery->pluck('id');
                    $allQuizSubs = \App\Models\Submission::where('student_id', $s->id)
                        ->whereIn('assignment_id', $quizIds)->get();
                    $gradedQuizSubs = $allQuizSubs->filter(fn($sub) => $sub->grade !== null);
                    $submittedQuizzesCount = $allQuizSubs->count();
                    $totalQuizzesCount = $quizIds->count();
                    $quizEarned = $gradedQuizSubs->sum('grade');
                    $quizTotalMax = \App\Models\Assignment::whereIn('id', $quizIds)->sum('max_grade');
                    $quizPct = $quizTotalMax > 0 ? ($quizEarned / $quizTotalMax) : 0;
                    $quizzesGrade = round($quizPct * $wQuiz, 2);

                    $midtermGrade = $grade->midterm_grade ?? 0;
                    $finalGrade = $grade->final_exam_grade ?? 0;
                    $totalGrade = round($attendanceGrade + $assignmentsGrade + $quizzesGrade + $midtermGrade + $finalGrade, 2);

                    return [
                        'enrollment_id' => $enrollment->id,
                        'student_id' => $s->id,
                        'name' => $s->name,
                        'academic_number' => $s->academic_number ?? '-',
                        'department_id' => $s->department_id,
                        'department_name' => $s->department->name ?? '',
                        'level' => $s->level ?? '',
                        'study_type' => $s->study_type ?? 'general',
                        'grade_id' => $grade->id ?? null,
                        'attendance_grade' => $attendanceGrade,
                        'assignments_grade' => $assignmentsGrade,
                        'quizzes_grade' => $quizzesGrade,
                        'midterm_grade' => $midtermGrade,
                        'final_exam_grade' => $finalGrade,
                        'total_grade' => $totalGrade,
                        // Weight max values
                        'attendance_weight' => $wAtt,
                        'assignments_weight' => $wAss,
                        'quizzes_weight' => $wQuiz,
                        'midterm_weight' => $wMid,
                        'final_weight' => $wFinal,
                        // Actual statistics from DB
                        'attended_sessions' => $presentSessions,
                        'total_sessions' => $totalSessions,
                        'submitted_assignments_count' => $submittedAssignmentsCount,
                        'total_assignments_count' => $totalAssignmentsCount,
                        'assignment_earned' => $earned,
                        'assignment_possible' => $totalMax,
                        'submitted_quizzes_count' => $submittedQuizzesCount,
                        'total_quizzes_count' => $totalQuizzesCount,
                        'quiz_earned' => $quizEarned,
                        'quiz_possible' => $quizTotalMax,
                    ];
                });

            return response()->json(['success' => true, 'data' => $students, 'ta_id' => (int)(\App\Models\CourseOffering::find($id)->ta_id ?? 0)]);
        } catch (\Exception $e) {
            Log::error('Error in getOfferingStudents: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * جلب التخصصات المرتبطة بالمقرر
     */
    public function getOfferingDepartments($id)
    {
        try {
            $departments = StudentEnrollment::where('student_enrollments.offering_id', $id)
                ->join('users', 'student_enrollments.student_id', '=', 'users.id')
                ->join('departments', 'users.department_id', '=', 'departments.id')
                ->select('departments.id', 'departments.name')
                ->distinct()
                ->get();

            return response()->json(['success' => true, 'data' => $departments]);
        } catch (\Exception $e) {
            Log::error('Error in getOfferingDepartments: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * إزالة تسجيل طالب من المقرر
     */
    public function removeEnrollment($id)
    {
        try {
            $enrollment = StudentEnrollment::find($id);
            if (!$enrollment) {
                return response()->json(['success' => false, 'message' => 'التسجيل غير موجود'], 404);
            }
            $enrollment->delete();
            $this->logAction(auth()->id(), 'إلغاء ارتباط طالب', 'تم إلغاء ارتباط طالب من المقرر', $enrollment->id, 'enrollment');

            return response()->json(['success' => true, 'message' => 'تم إزالة الطالب']);
        } catch (\Exception $e) {
            Log::error('Error in removeEnrollment: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * جلب تفاصيل درجات الطالب في المقرر
     */
    public function getGradeDetails(Request $request, $id, $studentId)
    {
        try {
            $grade = \App\Models\Grade::where('offering_id', $id)
                ->where('student_id', $studentId)->first();
            if (!$grade) {
                // Search across all offerings of the same subject
                $offering = \App\Models\CourseOffering::find($id);
                if ($offering) {
                    $allIds = \App\Models\CourseOffering::where('subject_id', $offering->subject_id)->pluck('id');
                    foreach ($allIds as $oid) {
                        $g = \App\Models\Grade::where('offering_id', $oid)->where('student_id', $studentId)->first();
                        if ($g) { $grade = $g; break; }
                    }
                }
            }

            $assignments = \App\Models\Assignment::where(function ($q) use ($id) {
                    $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
                })->where('type', 'assignment')->get();

            $quizzes = \App\Models\Assignment::where(function ($q) use ($id) {
                    $q->where('offering_id', $id)->orWhereHas('offerings', fn($oq) => $oq->where('offering_id', $id));
                })->where('type', 'quiz')->get();

            $sessions = AttendanceSession::where('course_offering_id', $id)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'grade' => $grade ? [
                        'id' => $grade->id,
                        'attendance_grade' => $grade->attendance_grade,
                        'assignments_grade' => $grade->assignments_grade,
                        'quizzes_grade' => $grade->quizzes_grade,
                        'midterm_grade' => $grade->midterm_grade,
                        'final_exam_grade' => $grade->final_exam_grade,
                        'total_grade' => $grade->total_grade,
                    ] : null,
                    'assignments' => $assignments->map(function ($a) {
                        return ['id' => $a->id, 'title' => $a->title, 'max_grade' => $a->max_grade];
                    }),
                    'quizzes' => $quizzes->map(function ($q) {
                        return ['id' => $q->id, 'title' => $q->title, 'max_grade' => $q->max_grade];
                    }),
                    'sessions_count' => $sessions->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getGradeDetails: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * تحديث درجات الطالب
     */
    public function updateGrade(Request $request, $id)
    {
        try {
            $grade = \App\Models\Grade::find($id);
            if (!$grade) {
                return response()->json(['success' => false, 'message' => 'الدرجة غير موجودة'], 404);
            }

            // التحقق من صلاحية الدكتور العملي
            $doctorId = $request->doctor_id ?: $request->input('doctor_id');
            if ($doctorId && isPracticalDoctorFor($doctorId, $grade->offering_id)) {
                // الدكتور العملي: يعدل فقط assignments_grade و quizzes_grade
                $grade->assignments_grade = $request->input('assignments_grade', $grade->assignments_grade);
                $grade->quizzes_grade = $request->input('quizzes_grade', $grade->quizzes_grade);
            } else {
                $grade->attendance_grade = $request->input('attendance_grade', $grade->attendance_grade);
                $grade->assignments_grade = $request->input('assignments_grade', $grade->assignments_grade);
                $grade->quizzes_grade = $request->input('quizzes_grade', $grade->quizzes_grade);
                $grade->midterm_grade = $request->input('midterm_grade', $grade->midterm_grade);
                $grade->final_exam_grade = $request->input('final_exam_grade', $grade->final_exam_grade);
            }
            $grade->total_grade = $request->input('total_grade',
                $grade->attendance_grade + $grade->assignments_grade +
                $grade->quizzes_grade + $grade->midterm_grade + $grade->final_exam_grade
            );
            $grade->save();

            // Notify student
            try {
                $offering = \App\Models\CourseOffering::with('subject')->find($grade->offering_id);
                $subjectName = $offering->subject->name ?? '';
                \App\Models\Notification::create([
                    'user_id' => $grade->student_id,
                    'title' => 'تحديث الدرجات',
                    'type' => 'grade_update',
                    'message' => "تم تحديث درجاتك في {$subjectName}",
                    'notification_type' => 'grade_update',
                    'reference_type' => 'grade',
                    'reference_id' => $grade->id,
                    'offering_id' => $grade->offering_id,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            } catch (\Exception $e) { Log::error('notif updateGrade: ' . $e->getMessage()); }

            return response()->json(['success' => true, 'message' => 'تم تحديث الدرجات', 'data' => $grade]);
        } catch (\Exception $e) {
            Log::error('Error in updateGrade: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'حدث خطأ'], 500);
        }
    }

    /**
     * إرسال إشعارات لجميع الطلاب المرتبطين بمقرر
     */
    private function notifyStudents($offeringId, $message, $referenceType = null, $referenceId = null)
    {
        try {
            $students = StudentEnrollment::where('offering_id', $offeringId)->pluck('student_id');
            $offering = CourseOffering::find($offeringId);
            $title = $offering ? $offering->subject->name : 'إشعار مقرر دراسي';

            foreach ($students as $studentId) {
                Notification::create([
                    'user_id' => $studentId,
                    'title' => $title,
                    'type' => $referenceType ?? 'general',
                    'message' => $message,
                    'notification_type' => $referenceType ?? 'general',
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'offering_id' => $offeringId,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error in notifyStudents: ' . $e->getMessage());
        }
    }

    /**
     * إنشاء كويز أو تكليف جديد
     */
    public function storeAssignment(Request $request)
    {
        try {
            $data = $request->all();
            $filePath = null;
            if ($request->hasFile('file')) {
                $filePath = $request->file('file')->store('assignments', 'public');
            }

            $rawOfferingIds = $data['offering_ids'] ?? $data['offering_id'] ?? null;
            if (is_string($rawOfferingIds)) {
                $rawOfferingIds = json_decode($rawOfferingIds, true);
            }
            $offeringIds = is_array($rawOfferingIds) ? $rawOfferingIds : (array)$rawOfferingIds;
            $offeringIds = array_filter(array_map('intval', $offeringIds));

            $primaryOfferingId = $offeringIds[0];
            $targetAll = $request->boolean('target_all', false);

            $assignment = Assignment::create([
                'offering_id' => $primaryOfferingId,
                'creator_id' => $data['creator_id'],
                'title' => $data['title'],
                'type' => $data['type'] ?? 'assignment',
                'category' => $data['category'] ?? 'theoretical',
                'description' => $data['description'] ?? '',
                'max_grade' => $data['max_grade'] ?? 10,
                'due_date' => $data['due_date'] ?? null,
                'file_path' => $filePath,
                'target_all' => $targetAll,
                'created_at' => now(),
            ]);

            // Link to all selected offerings via pivot
            foreach ($offeringIds as $oid) {
                DB::table('assignment_offering')->insert([
                    'assignment_id' => $assignment->id,
                    'offering_id' => $oid,
                ]);
            }

            $assignment->load('offering.subject');

            // Notify students in each linked offering
            $notifType = $assignment->type === 'quiz' ? 'new_quiz' : 'new_assignment';
            $notifMsg = ($assignment->type === 'quiz' ? 'كويز جديد: ' : 'تكليف جديد: ') . $assignment->title;
            if ($targetAll) {
                $allOfferingIds = CourseOffering::where('subject_id', $assignment->offering->subject_id)
                    ->where(function ($q) {
                        $q->where('doctor_id', auth()->id())
                          ->orWhere('ta_id', auth()->id());
                    })
                    ->pluck('id');
                foreach ($allOfferingIds as $oid) {
                    $this->notifyStudents($oid, $notifMsg, $notifType, $assignment->id);
                }
            } else {
                foreach ($offeringIds as $oid) {
                    $this->notifyStudents($oid, $notifMsg, $notifType, $assignment->id);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'type' => $assignment->type,
                    'category' => $assignment->category,
                    'max_grade' => $assignment->max_grade,
                    'due_date' => $assignment->due_date,
                    'description' => $assignment->description,
                    'file_path' => $assignment->file_path,
                    'offering_id' => $assignment->offering_id,
                    'offering_name' => $assignment->offering->subject->name ?? '',
                    'target_all' => $targetAll,
                ],
                'message' => 'تم الإنشاء بنجاح',
            ]);
        } catch (\Exception $e) {
            Log::error('Error in storeAssignment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في الإنشاء',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * إرسال إشعار للدكتور
     */
    /**
     * تحديث كويز أو تكليف مع إعادة حساب الدرجات نسبياً
     */
    public function updateAssignment(Request $request, $id)
    {
        try {
            $assignment = Assignment::find($id);
            if (!$assignment) {
                return response()->json(['success' => false, 'message' => 'العنصر غير موجود'], 404);
            }

            $data = $request->all();
            $oldMaxGrade = $assignment->max_grade;

            $assignment->title = $data['title'] ?? $assignment->title;
            $assignment->description = $data['description'] ?? $assignment->description;
            $assignment->due_date = $data['due_date'] ?? $assignment->due_date;

            if (isset($data['max_grade']) && $data['max_grade'] != $oldMaxGrade) {
                $newMaxGrade = $data['max_grade'];
                $assignment->max_grade = $newMaxGrade;

                // إعادة حساب درجات الطلاب نسبياً
                if ($oldMaxGrade > 0) {
                    $ratio = $newMaxGrade / $oldMaxGrade;
                    $submissions = Submission::where('assignment_id', $id)->get();
                    foreach ($submissions as $submission) {
                        if ($submission->grade !== null) {
                            $newGrade = round($submission->grade * $ratio, 2);
                            DB::table('submissions')->where('id', $submission->id)->update(['grade' => $newGrade]);
                        }
                    }
                }
            }

            $assignment->save();

            // Notify students about update (all linked offerings)
            $notifType = $assignment->type === 'quiz' ? 'new_quiz' : 'new_assignment';
            $pivotIds = $assignment->offerings()->pluck('course_offerings.id')->toArray();
            $allOfferingIds = !empty($pivotIds) ? $pivotIds : [$assignment->offering_id];
            foreach ($allOfferingIds as $oid) {
                $this->notifyStudents($oid, 'تم تحديث ' . ($assignment->type === 'quiz' ? 'الكويز: ' : 'التكليف: ') . $assignment->title, $notifType, $assignment->id);
            }

            // إعادة تحميل العلاقات
            $assignment->load('offering.subject');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'type' => $assignment->type,
                    'category' => $assignment->category,
                    'max_grade' => $assignment->max_grade,
                    'due_date' => $assignment->due_date,
                    'description' => $assignment->description,
                    'file_path' => $assignment->file_path,
                    'offering_id' => $assignment->offering_id,
                    'offering_name' => $assignment->offering->subject->name ?? '',
                ],
                'message' => 'تم التحديث بنجاح',
            ]);
        } catch (\Exception $e) {
            Log::error('Error in updateAssignment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ في التحديث',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function notifyDoctor($doctorId, $title, $message, $referenceType = null, $referenceId = null, $offeringId = null)
    {
        try {
            Notification::create([
                'user_id' => $doctorId,
                'title' => $title,
                'type' => $referenceType ?? 'general',
                'message' => $message,
                'notification_type' => $referenceType ?? 'general',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'offering_id' => $offeringId,
                'is_read' => false,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in notifyDoctor: ' . $e->getMessage());
        }
    }
}