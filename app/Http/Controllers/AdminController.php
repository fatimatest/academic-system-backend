<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\College;
use App\Models\Department;
use App\Models\Subject;
use App\Models\CourseOffering;
use App\Models\StudentEnrollment;
use App\Models\Term;
use App\Models\AttendanceSession;
use App\Models\Grade;
use App\Models\JoinRequest;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\CourseMaterial;
use App\Models\Notification;
use App\Models\OfficialStudent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{


    public function getStats(Request $request)
    {
        try {
            $collegeId = $request->college_id;

            $collegeScoped = function ($query) use ($collegeId) {
                if ($collegeId) {
                    $deptIds = Department::where('college_id', $collegeId)->pluck('id');
                    $query->whereIn('department_id', $deptIds);
                }
                return $query;
            };

            $offeringIds = null;
            if ($collegeId) {
                $deptIds = Department::where('college_id', $collegeId)->pluck('id');
                $offeringIds = CourseOffering::whereIn('department_id', $deptIds)->pluck('id');
            }

            $collegeScopedOfferings = function ($query) use ($offeringIds, $collegeId) {
                if ($collegeId && $offeringIds) {
                    $query->whereIn('offering_id', $offeringIds);
                }
                return $query;
            };

            $collegeScopedSessions = function ($query) use ($offeringIds, $collegeId) {
                if ($collegeId && $offeringIds) {
                    $query->whereIn('course_offering_id', $offeringIds);
                }
                return $query;
            };

            $collegesCount = $collegeId ? 1 : College::count();
            $departmentsCount = $collegeId ? Department::where('college_id', $collegeId)->count() : Department::count();
            $usersCount = User::where($collegeScoped)->count();
            $doctorsCount = User::where('role', 'doctor')->where($collegeScoped)->count();
            $managersCount = $collegeId
                ? User::where('role', 'college_manager')
                    ->whereIn('department_id', Department::where('college_id', $collegeId)->pluck('id'))
                    ->orWhereIn('id', College::where('id', $collegeId)->whereNotNull('manager_id')->pluck('manager_id'))
                    ->count()
                : User::where('role', 'college_manager')->count();
            $studentsCount = User::where('role', 'student')->where($collegeScoped)->count();
            $levelsCount = $collegeId
                ? User::where('role', 'student')->where($collegeScoped)->distinct('level')->count('level')
                : User::where('role', 'student')->distinct('level')->count('level');
            $subjectsCount = $offeringIds ? CourseOffering::whereIn('id', $offeringIds)->distinct('subject_id')->count('subject_id') : Subject::count();
            $offeringsCount = $offeringIds ? $offeringIds->count() : CourseOffering::count();
            $assignmentsCount = Assignment::where($collegeScopedOfferings)->where('type', 'assignment')->count();
            $quizzesCount = Assignment::where($collegeScopedOfferings)->where('type', 'quiz')->count();
            $materialsCount = CourseMaterial::where($collegeScopedOfferings)->count();
            $pendingRequestsCount = $collegeId
                ? JoinRequest::where($collegeScopedOfferings)->where('status', 'pending')->count()
                : JoinRequest::where('status', 'pending')->count();
            $sessionsCount = AttendanceSession::where($collegeScopedSessions)->count();
            $termsCount = Term::count();
            $activeTerm = Term::where('status', 'active')->first();

            $announcementsCount = 0;
            if ($collegeId && $offeringIds) {
                $announcementsCount = Announcement::whereIn('offering_id', $offeringIds)->where('status', 'published')->count();
            } elseif (!$collegeId) {
                $announcementsCount = Announcement::where('status', 'published')->count();
            }

            $connectionsCount = 0;
            if ($collegeId && $offeringIds) {
                $connectionsCount = StudentEnrollment::whereIn('offering_id', $offeringIds)->count();
            } elseif (!$collegeId) {
                $connectionsCount = StudentEnrollment::count();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'colleges' => $collegesCount,
                    'departments' => $departmentsCount,
                    'users' => $usersCount,
                    'doctors' => $doctorsCount,
                    'managers' => $managersCount,
                    'students' => $studentsCount,
                    'levels' => $levelsCount,
                    'subjects' => $subjectsCount,
                    'course_offerings' => $offeringsCount,
                    'assignments' => $assignmentsCount,
                    'quizzes' => $quizzesCount,
                    'materials' => $materialsCount,
                    'pending_requests' => $pendingRequestsCount,
                    'attendance_sessions' => $sessionsCount,
                    'terms' => $termsCount,
                    'announcements' => $announcementsCount,
                    'connections' => $connectionsCount,
                    'active_term' => $activeTerm ? [
                        'id' => $activeTerm->id,
                        'name' => $activeTerm->name,
                        'start_date' => $activeTerm->start_date,
                        'end_date' => $activeTerm->end_date,
                        'status' => $activeTerm->status,
                    ] : null,
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getActivities(Request $request)
    {
        try {
            $collegeId = $request->college_id;
            $activities = collect();
            $deptIds = null;
            $collegeUserIds = null;
            $offeringIds = null;

            if ($collegeId) {
                $deptIds = Department::where('college_id', $collegeId)->pluck('id');
                $managerIds = College::where('id', $collegeId)->whereNotNull('manager_id')->pluck('manager_id');
                $collegeUserIds = User::whereIn('department_id', $deptIds)->pluck('id')
                    ->merge($managerIds)->unique()->values();
                $offeringIds = CourseOffering::whereIn('department_id', $deptIds)->pluck('id');
            }

            // 1. audit_logs (with user names)
            $logsQuery = DB::table('audit_logs')
                ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
                ->select('audit_logs.*', 'users.name as user_name');
            if ($collegeId && $collegeUserIds && $collegeUserIds->isNotEmpty()) {
                $logsQuery->whereIn('audit_logs.user_id', $collegeUserIds);
            }
            foreach ($logsQuery->orderBy('audit_logs.created_at', 'desc')->limit(20)->get() as $l) {
                $icon = 'clipboard';
                if (strpos($l->action, 'إضافة') !== false) $icon = 'plus';
                elseif (strpos($l->action, 'تعديل') !== false) $icon = 'edit';
                elseif (strpos($l->action, 'حذف') !== false) $icon = 'trash';
                elseif (strpos($l->action, 'تفعيل') !== false) $icon = 'check';
                elseif (strpos($l->action, 'تعطيل') !== false) $icon = 'x';
                $activities->push([
                    'type' => 'log',
                    'action_type' => $l->action,
                    'description' => $l->details ?? $l->action,
                    'actor_name' => $l->user_name ?? 'النظام',
                    'icon' => $icon,
                    'created_at' => $l->created_at,
                ]);
            }

            // 2. course_offerings creation
            $offeringsQuery = CourseOffering::with('subject');
            if ($collegeId && $deptIds) $offeringsQuery->whereIn('department_id', $deptIds);
            foreach ($offeringsQuery->orderBy('created_at', 'desc')->limit(10)->get() as $o) {
                $activities->push([
                    'type' => 'offering',
                    'action_type' => 'إنشاء مقرر',
                    'description' => 'إنشاء مقرر: ' . ($o->subject->name ?? ''),
                    'actor_name' => $o->doctor->name ?? 'النظام',
                    'icon' => 'link',
                    'created_at' => $o->created_at,
                ]);
            }

            // 3. assignments & quizzes
            if ($offeringIds && $offeringIds->isNotEmpty()) {
                $assignments = Assignment::whereIn('offering_id', $offeringIds)->orderBy('created_at', 'desc')->limit(10)->get();
            } else if (!$collegeId) {
                $assignments = Assignment::orderBy('created_at', 'desc')->limit(10)->get();
            } else { $assignments = collect(); }
            foreach ($assignments as $a) {
                $typeLabel = $a->type === 'quiz' ? 'كويز' : 'تكليف';
                $activities->push([
                    'type' => $a->type === 'quiz' ? 'quiz' : 'assignment',
                    'action_type' => 'إنشاء ' . $typeLabel,
                    'description' => 'إنشاء ' . $typeLabel . ': ' . $a->title,
                    'actor_name' => 'النظام',
                    'icon' => $a->type === 'quiz' ? 'help-circle' : 'file-text',
                    'created_at' => $a->created_at,
                ]);
            }

            // 4. course_materials uploads
            $matQuery = DB::table('course_materials')
                ->join('course_offerings', 'course_materials.offering_id', '=', 'course_offerings.id')
                ->join('subjects', 'course_offerings.subject_id', '=', 'subjects.id')
                ->select('course_materials.*', 'subjects.name as subject_name');
            if ($collegeId && $deptIds) $matQuery->whereIn('course_offerings.department_id', $deptIds);
            foreach ($matQuery->orderBy('course_materials.created_at', 'desc')->limit(10)->get() as $m) {
                $activities->push([
                    'type' => 'material',
                    'action_type' => 'رفع ملف',
                    'description' => 'رفع ملف مقرر: ' . $m->file_name . ' - ' . ($m->subject_name ?? ''),
                    'actor_name' => 'النظام',
                    'icon' => 'upload',
                    'created_at' => $m->created_at,
                ]);
            }

            // 5. announcements (published)
            if ($offeringIds && $offeringIds->isNotEmpty()) {
                $announcements = Announcement::whereIn('offering_id', $offeringIds)
                    ->where('status', 'published')
                    ->orderBy('created_at', 'desc')->limit(10)->get();
            } elseif (!$collegeId) {
                $announcements = Announcement::where('status', 'published')
                    ->orderBy('created_at', 'desc')->limit(10)->get();
            } else { $announcements = collect(); }
            foreach ($announcements as $a) {
                $activities->push([
                    'type' => 'announcement',
                    'action_type' => 'إعلان',
                    'description' => 'إعلان: ' . $a->title,
                    'actor_name' => 'النظام',
                    'icon' => 'bell',
                    'created_at' => $a->created_at,
                ]);
            }

            $sorted = $activities->sortByDesc('created_at')->take(20)->values();
            return response()->json(['success' => true, 'data' => $sorted]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getNotifications(Request $request)
    {
        try {
            $userId = $request->user_id;
            $query = Notification::orderBy('created_at', 'desc');
            if ($userId) $query->where('user_id', $userId);
            $notifications = $query->limit(20)->get();
            $unreadQuery = Notification::where('is_read', 0);
            if ($userId) $unreadQuery->where('user_id', $userId);
            $unreadCount = $unreadQuery->count();
            return response()->json(['success' => true, 'data' => $notifications, 'unread_count' => $unreadCount]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getColleges()
    {
        try {
            $colleges = College::with('manager')->get()->map(function ($c) {
                $deptIds = Department::where('college_id', $c->id)->pluck('id');
                $studentsCount = User::where('role', 'student')->whereIn('department_id', $deptIds)->count();
                $deptsCount = $deptIds->count();
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'manager_id' => $c->manager_id,
                    'manager_name' => $c->manager ? $c->manager->name : '—',
                    'departments_count' => $deptsCount,
                    'students_count' => $studentsCount,
                    'created_at' => $c->created_at,
                    'is_active' => $c->is_active ?? true,
                ];
            });
            return response()->json(['success' => true, 'data' => $colleges]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createCollege(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'manager_id' => 'nullable|integer|exists:users,id',
            ]);

            $college = College::create([
                'name' => $validated['name'],
                'manager_id' => $validated['manager_id'] ?? null,
            ]);

            $this->logAction('إنشاء كلية', 'تم إنشاء كلية: ' . $college->name);

            return response()->json(['success' => true, 'message' => 'تم إنشاء الكلية بنجاح', 'id' => $college->id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateCollege(Request $request, $id)
    {
        try {
            $college = College::find($id);
            if (!$college) return response()->json(['success' => false, 'message' => 'الكلية غير موجودة'], 404);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'manager_id' => 'nullable|integer|exists:users,id',
            ]);

            $college->update([
                'name' => $validated['name'],
                'manager_id' => $validated['manager_id'] ?? $college->manager_id,
            ]);

            $this->logAction('تعديل كلية', 'تم تعديل كلية: ' . $college->name);

            return response()->json(['success' => true, 'message' => 'تم تعديل الكلية بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteCollege($id)
    {
        try {
            $college = College::find($id);
            if (!$college) return response()->json(['success' => false, 'message' => 'الكلية غير موجودة'], 404);

            $deptCount = Department::where('college_id', $id)->count();
            if ($deptCount > 0) {
                return response()->json(['success' => false, 'message' => 'لا يمكن حذف الكلية لأنها تحتوي على أقسام'], 400);
            }

            $name = $college->name;
            $college->delete();

            $this->logAction('حذف كلية', 'تم حذف كلية: ' . $name);

            return response()->json(['success' => true, 'message' => 'تم حذف الكلية بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function toggleCollegeStatus($id)
    {
        try {
            $college = College::find($id);
            if (!$college) return response()->json(['success' => false, 'message' => 'الكلية غير موجودة'], 404);

            $current = $college->is_active ?? true;
            $newStatus = !$current;
            DB::table('colleges')->where('id', $id)->update(['is_active' => $newStatus]);

            return response()->json(['success' => true, 'message' => $newStatus ? 'تم تفعيل الكلية' : 'تم تعطيل الكلية', 'is_active' => $newStatus]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getUsers(Request $request)
    {
        try {
            $query = User::with('department.college');
            if ($request->role) $query->where('role', $request->role);
            if ($request->search) $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
            if ($request->status !== null) $query->where('is_active', $request->status === 'active' ? 1 : 0);
            if ($request->college_id) {
                $deptIds = Department::where('college_id', $request->college_id)->pluck('id');
                $query->whereIn('department_id', $deptIds);
            }

            $users = $query->orderBy('created_at', 'desc')->get()->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'phone' => $u->phone ?? '—',
                    'department_id' => $u->department_id,
                    'department_name' => $u->department->name ?? '—',
                    'college_name' => $u->department->college->name ?? '—',
                    'is_active' => $u->is_active ?? true,
                    'academic_number' => $u->academic_number ?? '—',
                    'level' => $u->level ?? '—',
                    'study_type' => $u->study_type ?? '—',
                    'created_at' => $u->created_at ? $u->created_at->format('Y-m-d H:i:s') : '—',
                    'last_login' => $u->last_login ?? '—',
                ];
            });

            $total = $users->count();
            $active = $users->where('is_active', true)->count();
            $inactive = $total - $active;

            $roleSummary = [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
                'system_admin' => User::where('role', 'system_admin')->count(),
                'college_manager' => User::where('role', 'college_manager')->count(),
                'doctor' => User::where('role', 'doctor')->count(),
                'student' => User::where('role', 'student')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $users,
                'summary' => ['total' => $total, 'active' => $active, 'inactive' => $inactive],
                'role_summary' => $roleSummary,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getUser($id)
    {
        try {
            $user = User::with('department.college', 'managedCollege')->find($id);
            if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);

            $college = null;
            if ($user->role === 'college_manager' && $user->managedCollege) {
                $college = $user->managedCollege;
            } elseif ($user->department && $user->department->college) {
                $college = $user->department->college;
            }

            $data = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'role' => $user->role,
                'is_active' => $user->is_active ?? true,
                'department_id' => $user->department_id,
                'academic_number' => $user->academic_number ?? '',
                'level' => $user->level ?? 1,
                'study_type' => $user->study_type ?? '',
                'qr_token' => $user->qr_token ?? '',
                'avatar_type' => $user->avatar_type ?? 1,
                'created_at' => $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : '',
                'department' => $user->department ? [
                    'id' => $user->department->id,
                    'name' => $user->department->name,
                ] : null,
                'college' => $college ? [
                    'id' => $college->id,
                    'name' => $college->name,
                ] : null,
                'college_id' => $college ? $college->id : null,
            ];

            if ($user->role === 'doctor') {
                $courseOfferings = CourseOffering::with('subject', 'department')
                    ->where('doctor_id', $user->id)
                    ->orWhere('ta_id', $user->id)
                    ->get()
                    ->map(function ($offering) {
                        return [
                            'id' => $offering->id,
                            'subject_name' => $offering->subject->name ?? '',
                            'department_name' => $offering->department->name ?? '',
                        ];
                    });
                $data['course_offerings'] = $courseOfferings;
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createUser(Request $request)
    {
        try {
            $role = $request->role;
            $rules = [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
                'role' => 'required|in:system_admin,college_manager,doctor,student',
                'phone' => 'nullable|string|max:20',
                'college_id' => 'nullable|integer|exists:colleges,id',
            ];
            if (in_array($role, ['student', 'doctor'])) {
                $rules['department_id'] = 'required|integer|exists:departments,id';
            }
            if ($role === 'student') {
                $rules['level'] = 'required|integer|min:1|max:12';
                $rules['academic_number'] = 'required|string|max:50|unique:users,academic_number';
                $rules['study_type'] = 'required|string|in:general,paid';
            }
            $validated = $request->validate($rules);

            $data = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $role,
                'phone' => $validated['phone'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'level' => $role === 'student' ? ($validated['level'] ?? 1) : 1,
                'academic_number' => $validated['academic_number'] ?? null,
                'study_type' => $validated['study_type'] ?? null,
                'is_active' => 1,
                'qr_token' => md5($validated['email']),
            ];

            if ($validated['role'] === 'college_manager' && $request->college_id) {
                College::where('id', $request->college_id)->update(['manager_id' => null]);
            }

            $user = User::create($data);

            if ($validated['role'] === 'college_manager' && $request->college_id) {
                College::where('id', $request->college_id)->update(['manager_id' => $user->id]);
            }

            $this->logAction('إنشاء حساب', 'تم إنشاء حساب: ' . $user->name . ' (' . $validated['role'] . ')');

            return response()->json(['success' => true, 'message' => 'تم إنشاء المستخدم بنجاح', 'id' => $user->id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);

            $role = $request->role ?? $user->role;

            $rules = [
                'name' => 'nullable|string|max:255',
                'email' => 'nullable|email|unique:users,email,' . $id,
                'phone' => 'nullable|string|max:20',
                'role' => 'nullable|in:system_admin,college_manager,doctor,student',
                'is_active' => 'nullable|boolean',
                'password' => 'nullable|string|min:6',
                'academic_number' => 'nullable|string|max:50|unique:users,academic_number,' . $id,
            ];

            if (in_array($role, ['student', 'doctor'])) {
                $rules['department_id'] = 'nullable|integer|exists:departments,id';
            }

            if ($role === 'student') {
                $rules['level'] = 'nullable|integer|min:1|max:12';
                $rules['study_type'] = 'nullable|string|in:general,paid';
            }

            if ($role === 'college_manager') {
                $rules['college_id'] = 'nullable|integer|exists:colleges,id';
            }

            $validated = $request->validate($rules);

            $updateData = [];
            $changes = [];

            if ($request->has('name')) {
                $updateData['name'] = $validated['name'];
                $changes[] = 'الاسم';
            }
            if ($request->has('email')) {
                $updateData['email'] = $validated['email'];
                $changes[] = 'البريد الإلكتروني';
            }
            if ($request->has('phone')) {
                $updateData['phone'] = $validated['phone'] ?: null;
                $changes[] = 'رقم الهاتف';
            }
            if ($request->has('role')) {
                $updateData['role'] = $validated['role'];
                $changes[] = 'الدور';
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $validated['is_active'] ? 1 : 0;
                $changes[] = 'الحالة';
            }
            if ($request->has('password') && !empty($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
                $changes[] = 'كلمة المرور';
            }
            if ($request->has('academic_number')) {
                $updateData['academic_number'] = $validated['academic_number'] !== '' ? $validated['academic_number'] : null;
                $changes[] = 'الرقم الأكاديمي';
            }
            if ($request->has('department_id')) {
                $updateData['department_id'] = $validated['department_id'];
                $changes[] = 'القسم';
            }
            if ($request->has('level')) {
                $updateData['level'] = $validated['level'];
                $changes[] = 'المستوى';
            }
            if ($request->has('study_type')) {
                $updateData['study_type'] = $validated['study_type'] ?: null;
                $changes[] = 'نوع الدراسة';
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }

            if ($request->has('college_id')) {
                if ($user->getOriginal('role') === 'college_manager') {
                    College::where('manager_id', $user->id)->update(['manager_id' => null]);
                }
                if ($request->college_id) {
                    College::where('id', $request->college_id)->update(['manager_id' => $user->id]);
                    $changes[] = 'الكلية';
                }
            }

            if ($request->has('role') && $request->role !== 'college_manager' && $user->getOriginal('role') === 'college_manager') {
                College::where('manager_id', $user->id)->update(['manager_id' => null]);
            }

            $detailsText = 'تم تعديل حساب: ' . $user->name;
            if (!empty($changes)) {
                $detailsText .= ' (تم تعديل: ' . implode('، ', $changes) . ')';
            }

            $this->logAction('تعديل حساب', $detailsText);

            return response()->json(['success' => true, 'message' => 'تم تحديث المستخدم بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function regenerateUserQr($id)
    {
        try {
            $user = User::find($id);
            if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);

            $newToken = md5($user->email . time() . rand(1000, 9999));
            $user->qr_token = $newToken;
            $user->save();

            $this->logAction('إعادة تعيين كلمة المرور', 'تم إعادة تعيين كلمة المرور للمستخدم: ' . $user->name);

            return response()->json([
                'success' => true,
                'message' => 'تم تجديد رمز QR بنجاح',
                'qr_token' => $newToken,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteUser($id)
    {
        try {
            $user = User::find($id);
            if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
            if (in_array($user->role, ['system_admin']) && User::where('role', 'system_admin')->count() <= 1) {
                return response()->json(['success' => false, 'message' => 'لا يمكن حذف آخر مدير نظام'], 400);
            }

            College::where('manager_id', $id)->update(['manager_id' => null]);

            $name = $user->name;
            $user->delete();

            $this->logAction('حذف حساب', 'تم حذف حساب: ' . $name);

            return response()->json(['success' => true, 'message' => 'تم حذف المستخدم بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function toggleUserStatus($id)
    {
        try {
            $user = User::find($id);
            if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);
            if (in_array($user->role, ['system_admin']) && User::where('role', 'system_admin')->count() <= 1) {
                return response()->json(['success' => false, 'message' => 'لا يمكن تعطيل آخر مدير نظام'], 400);
            }

            $newStatus = !($user->is_active ?? true);
            $user->update(['is_active' => $newStatus]);

            $this->logAction($newStatus ? 'تفعيل حساب' : 'تعطيل حساب', 'تم ' . ($newStatus ? 'تفعيل' : 'تعطيل') . ' حساب: ' . $user->name);

            return response()->json(['success' => true, 'message' => $newStatus ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم', 'is_active' => $newStatus]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getTerms()
    {
        try {
            $terms = Term::orderBy('start_date', 'desc')->get()->map(function ($t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'year' => $t->year ?? '',
                    'term' => $t->term ?? '',
                    'start_date' => $t->start_date,
                    'end_date' => $t->end_date,
                    'description' => $t->description ?? '',
                    'status' => $t->status ?? 'inactive',
                    'is_active' => ($t->status === 'active'),
                    'offerings_count' => CourseOffering::where('term_id', $t->id)->count(),
                ];
            });
            return response()->json(['success' => true, 'data' => $terms]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createTerm(Request $request)
    {
        try {
            $termData = $request->only(['name', 'start_date', 'end_date', 'year', 'term', 'description']);
            $termData['status'] = 'active';
            $term = Term::create($termData);

            return response()->json(['success' => true, 'message' => 'تم إنشاء الترم بنجاح', 'id' => $term->id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateTerm(Request $request, $id)
    {
        try {
            $term = Term::find($id);
            if (!$term) return response()->json(['success' => false, 'message' => 'الترم غير موجود'], 404);

            $allowedFields = ['name', 'start_date', 'end_date', 'year', 'term', 'description'];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $term->$field = $request->$field;
                }
            }
            $term->save();

            return response()->json(['success' => true, 'message' => 'تم تعديل الترم بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteTerm($id)
    {
        try {
            $term = Term::find($id);
            if (!$term) return response()->json(['success' => false, 'message' => 'الترم غير موجود'], 404);

            $offeringsCount = CourseOffering::where('term_id', $id)->count();
            if ($offeringsCount > 0) {
                return response()->json(['success' => false, 'message' => 'لا يمكن حذف الترم لأنه مرتبط بمقررات دراسية'], 400);
            }

            $name = $term->name;
            $term->delete();

            return response()->json(['success' => true, 'message' => 'تم حذف الترم بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function setActiveTerm(Request $request, $id)
    {
        try {
            $term = Term::find($id);
            if (!$term) return response()->json(['success' => false, 'message' => 'الترم غير موجود'], 404);

            $active = $request->active !== '0';

            if ($active) {
                DB::table('terms')->where('status', 'active')->update(['status' => 'inactive']);
                $term->update(['status' => 'active']);
                $this->logAction('تفعيل ترم', 'تم تعيين الترم النشط: ' . $term->name);
                return response()->json(['success' => true, 'message' => 'تم تعيين الترم النشط بنجاح']);
            } else {
                $term->update(['status' => 'inactive']);
                $this->logAction('إلغاء تفعيل ترم', 'تم إلغاء تفعيل الترم: ' . $term->name);
                return response()->json(['success' => true, 'message' => 'تم إلغاء تفعيل الترم']);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getCollegeManagers(Request $request)
    {
        try {
            $query = User::whereIn('role', ['college_manager', 'system_admin'])
                ->where('is_active', true)
                ->select('id', 'name', 'email', 'role');
            if ($request->role) {
                $query->where('role', $request->role);
            }
            $managers = $query->get();
            return response()->json(['success' => true, 'data' => $managers]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getDepartments(Request $request)
    {
        try {
            $query = Department::with('college');
            if ($request->college_id) {
                $query->where('college_id', $request->college_id);
            }
            if ($request->study_type) {
                $query->where('study_type', $request->study_type);
            }
            $departments = $query->get()->map(function ($d) {
                $studentsCount = User::where('role', 'student')->where('department_id', $d->id)->count();
                $doctorsCount = User::where('role', 'doctor')->where('department_id', $d->id)->count();
                $subjectIdsViaOfferings = CourseOffering::where('department_id', $d->id)->distinct()->pluck('subject_id');
                $subjectIdsDirect = Subject::where('department_id', $d->id)->pluck('id');
                $allSubjectIds = $subjectIdsViaOfferings->merge($subjectIdsDirect)->unique();
                $levelsList = User::where('role', 'student')->where('department_id', $d->id)->whereNotNull('level')->distinct()->orderBy('level')->pluck('level');
                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'study_type' => $d->study_type,
                    'code' => $d->code ?? '',
                    'college_id' => $d->college_id,
                    'college_name' => $d->college->name ?? '—',
                    'description' => $d->description ?? '',
                    'students_count' => $studentsCount,
                    'doctors_count' => $doctorsCount,
                    'subjects_count' => $allSubjectIds->count(),
                    'levels_count' => $levelsList->count() ?: ($d->levels_count ?? 0),
                ];
            });
            return response()->json(['success' => true, 'data' => $departments]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createDepartment(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'study_type' => 'nullable|string|in:general,paid',
                'code' => 'nullable|string|max:50',
                'college_id' => 'required|integer|exists:colleges,id',
                'description' => 'nullable|string',
                'levels_count' => 'nullable|integer|min:1',
            ]);

            $department = Department::create([
                'name' => $validated['name'],
                'study_type' => $validated['study_type'] ?? null,
                'code' => $validated['code'] ?? null,
                'college_id' => $validated['college_id'],
                'description' => $validated['description'] ?? null,
                'levels_count' => $validated['levels_count'] ?? null,
            ]);

            $this->logAction('إنشاء قسم', 'تم إنشاء قسم: ' . $department->name);

            return response()->json(['success' => true, 'message' => 'تم إنشاء القسم بنجاح', 'id' => $department->id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateDepartment(Request $request, $id)
    {
        try {
            $department = Department::find($id);
            if (!$department) return response()->json(['success' => false, 'message' => 'القسم غير موجود'], 404);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'study_type' => 'nullable|string|in:general,paid',
                'code' => 'nullable|string|max:50',
                'college_id' => 'required|integer|exists:colleges,id',
                'description' => 'nullable|string',
                'levels_count' => 'nullable|integer|min:1',
            ]);

            $department->update([
                'name' => $validated['name'],
                'study_type' => $validated['study_type'] ?? $department->study_type,
                'code' => $validated['code'] ?? $department->code,
                'college_id' => $validated['college_id'],
                'description' => $validated['description'] ?? $department->description,
                'levels_count' => $validated['levels_count'] ?? $department->levels_count,
            ]);

            $this->logAction('تعديل قسم', 'تم تعديل قسم: ' . $department->name);

            return response()->json(['success' => true, 'message' => 'تم تعديل القسم بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteDepartment($id)
    {
        try {
            $department = Department::find($id);
            if (!$department) return response()->json(['success' => false, 'message' => 'القسم غير موجود'], 404);

            $usersCount = User::where('department_id', $id)->count();
            if ($usersCount > 0) {
                return response()->json(['success' => false, 'message' => 'لا يمكن حذف القسم لأنه مرتبط بمستخدمين'], 400);
            }

            $offeringsCount = CourseOffering::where('department_id', $id)->count();
            if ($offeringsCount > 0) {
                return response()->json(['success' => false, 'message' => 'لا يمكن حذف القسم لأنه مرتبط بمقررات دراسية'], 400);
            }

            $name = $department->name;
            $department->delete();

            $this->logAction('حذف قسم', 'تم حذف قسم: ' . $name);

            return response()->json(['success' => true, 'message' => 'تم حذف القسم بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ================= Subjects =================
    public function getSubjects(Request $request)
    {
        try {
            $query = Subject::query();
            if ($request->college_id) {
                $deptIds = Department::where('college_id', $request->college_id)->pluck('id');
                $query->whereIn('department_id', $deptIds);
            }
            if ($request->department_id) {
                $query->where('department_id', $request->department_id);
            }
            if ($request->level) {
                $query->where('level', $request->level);
            }
            if ($request->search) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('code', 'like', '%' . $request->search . '%');
                });
            }
            $subjects = $query->orderBy('name')->get()->map(function ($s) use ($request) {
                $offerings = CourseOffering::where('subject_id', $s->id);
                if ($request->college_id) {
                    $deptIds = Department::where('college_id', $request->college_id)->pluck('id');
                    $offerings->whereIn('department_id', $deptIds);
                }
                if ($request->department_id) {
                    $offerings->where('department_id', $request->department_id);
                }
                $offeringList = $offerings->get();
                $offeringIds = $offeringList->pluck('id');
                $deptIdsForSubject = $offeringList->pluck('department_id')->unique();
                $departments = Department::whereIn('id', $deptIdsForSubject)->pluck('name');
                $firstDoctorId = $offeringList->whereNotNull('doctor_id')->first() ? $offeringList->whereNotNull('doctor_id')->first()->doctor_id : null;
                $doctorName = $firstDoctorId ? (User::find($firstDoctorId)->name ?? '—') : '—';
                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'code' => $s->code ?? '—',
                    'department_id' => $s->department_id,
                    'description' => '',
                    'departments' => $departments->implode('، '),
                    'levels' => [$s->level],
                    'level' => $s->level,
                    'doctor_name' => $doctorName,
                    'doctors_count' => $offeringList->whereNotNull('doctor_id')->pluck('doctor_id')->unique()->count(),
                    'offerings_count' => $offeringList->count(),
                    'students_count' => StudentEnrollment::whereIn('offering_id', $offeringIds)->count(),
                ];
            });
            return response()->json(['success' => true, 'data' => $subjects]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getSubject($id)
    {
        try {
            $subject = Subject::find($id);
            if (!$subject) return response()->json(['success' => false, 'message' => 'المادة غير موجودة'], 404);

            $offerings = CourseOffering::with(['doctor', 'department', 'term'])
                ->where('subject_id', $id)
                ->orderBy('level')->get();

            $offeringIds = $offerings->pluck('id');
            $activeTerm = Term::where('status', 'active')->first();

            $offeringsData = $offerings->map(function ($o) use ($activeTerm) {
                $materialsCount = DB::table('course_materials')->where('offering_id', $o->id)->count();
                $assignmentsCount = Assignment::where('offering_id', $o->id)->where('type', 'assignment')->count();
                $quizzesCount = Assignment::where('offering_id', $o->id)->where('type', 'quiz')->count();
                $studentsCount = StudentEnrollment::where('offering_id', $o->id)->count();
                return [
                    'id' => $o->id,
                    'doctor_name' => $o->doctor->name ?? '—',
                    'department_name' => $o->department->name ?? '—',
                    'level' => $o->level,
                    'term_name' => $o->term->name ?? '—',
                    'study_type' => $o->study_type ?? '—',
                    'students_count' => $studentsCount,
                    'materials_count' => $materialsCount,
                    'assignments_count' => $assignmentsCount,
                    'quizzes_count' => $quizzesCount,
                    'is_active_term' => $activeTerm && $o->term_id === $activeTerm->id,
                ];
            });

            $departmentsList = Department::whereIn('id', $offerings->pluck('department_id')->unique())->pluck('name');

            return response()->json(['success' => true, 'data' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code ?? '—',
                'description' => $subject->description ?? '',
                'departments' => $departmentsList->implode('، '),
                'levels' => $offerings->pluck('level')->unique()->sort()->values(),
                'offerings' => $offeringsData,
                'total_students' => StudentEnrollment::whereIn('offering_id', $offeringIds)->count(),
                'total_offerings' => $offerings->count(),
                'total_doctors' => $offerings->whereNotNull('doctor_id')->pluck('doctor_id')->unique()->count(),
            ]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createSubject(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'nullable|string|max:50',
                'department_ids' => 'required|array',
                'department_ids.*' => 'integer|exists:departments,id',
                'level' => 'required|integer|min:1|max:12',
                'doctor_id' => 'required|integer|exists:users,id',
            ]);

            $firstDeptId = $validated['department_ids'][0];
            $subject = Subject::create([
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'department_id' => $firstDeptId,
                'level' => $validated['level'],
            ]);

            // Auto-create course offerings for each selected department
            $activeTerm = Term::where('status', 'active')->first();
            if ($activeTerm) {
                foreach ($validated['department_ids'] as $deptId) {
                    CourseOffering::create([
                        'subject_id' => $subject->id,
                        'doctor_id' => $validated['doctor_id'],
                        'department_id' => $deptId,
                        'level' => $validated['level'],
                        'term_id' => $activeTerm->id,
                        'study_type' => 'general',
                    ]);
                }
            }

            $this->logAction('إنشاء مادة', 'تم إنشاء مادة: ' . $subject->name . ' مع ' . count($validated['department_ids']) . ' أقسام');

            return response()->json(['success' => true, 'message' => 'تم إنشاء المادة وربطها بالأقسام والدكتور بنجاح', 'id' => $subject->id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateSubject(Request $request, $id)
    {
        try {
            $subject = Subject::find($id);
            if (!$subject) return response()->json(['success' => false, 'message' => 'المادة غير موجودة'], 404);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'nullable|string|max:50',
                'department_ids' => 'required|array',
                'department_ids.*' => 'integer|exists:departments,id',
                'level' => 'required|integer|min:1|max:12',
                'doctor_id' => 'required|integer|exists:users,id',
            ]);

            $subject->update([
                'name' => $validated['name'],
                'code' => $validated['code'] ?? null,
                'level' => $validated['level'],
            ]);

            // Update course offerings: remove old ones for this subject, recreate for selected depts
            $activeTerm = Term::where('status', 'active')->first();
            CourseOffering::where('subject_id', $id)->delete();
            if ($activeTerm) {
                foreach ($validated['department_ids'] as $deptId) {
                    CourseOffering::create([
                        'subject_id' => $subject->id,
                        'doctor_id' => $validated['doctor_id'],
                        'department_id' => $deptId,
                        'level' => $validated['level'],
                        'term_id' => $activeTerm->id,
                        'study_type' => 'general',
                    ]);
                }
            }

            $this->logAction('تعديل مادة', 'تم تعديل مادة: ' . $subject->name);

            return response()->json(['success' => true, 'message' => 'تم تحديث المادة وإعادة ربطها بالأقسام والدكتور بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteSubject($id)
    {
        try {
            $subject = Subject::find($id);
            if (!$subject) return response()->json(['success' => false, 'message' => 'المادة غير موجودة'], 404);

            $offeringsCount = CourseOffering::where('subject_id', $id)->count();
            if ($offeringsCount > 0) {
                return response()->json(['success' => false, 'message' => 'لا يمكن حذف المادة لأنها مرتبطة بعروض دراسية'], 400);
            }

            $name = $subject->name;
            $subject->delete();

            $this->logAction('حذف مادة', 'تم حذف مادة: ' . $name);

            return response()->json(['success' => true, 'message' => 'تم حذف المادة بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getRoles()
    {
        try {
            $roles = [
                ['id' => 'system_admin', 'name' => 'مدير النظام', 'permissions' => ['الوصول الكامل', 'إدارة المستخدمين', 'إدارة الكليات', 'إدارة الأترام', 'إعدادات النظام', 'سجل العمليات'], 'users_count' => User::where('role', 'system_admin')->count()],
                ['id' => 'college_manager', 'name' => 'مدير كلية', 'permissions' => ['إدارة المواد', 'إدارة الدكاترة', 'عرض التقارير', 'إدارة المقررات', 'طلبات الانضمام'], 'users_count' => User::where('role', 'college_manager')->count()],
                ['id' => 'doctor', 'name' => 'دكتور', 'permissions' => ['إدارة المقرر', 'تصحيح التكاليف', 'تسجيل الحضور', 'إنشاء كويزات', 'إدارة المواد'], 'users_count' => User::where('role', 'doctor')->count()],
                ['id' => 'student', 'name' => 'طالب', 'permissions' => ['عرض المواد', 'تسليم التكاليف', 'أداء الكويزات', 'عرض الدرجات', 'طلب انضمام'], 'users_count' => User::where('role', 'student')->count()],
            ];
            return response()->json(['success' => true, 'data' => $roles]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getAuditLogs(Request $request)
    {
        try {
            $query = DB::table('audit_logs')
                ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
                ->leftJoin('colleges', 'audit_logs.college_id', '=', 'colleges.id')
                ->leftJoin('departments', 'audit_logs.department_id', '=', 'departments.id')
                ->select(
                    'audit_logs.*',
                    'users.name as user_name',
                    'colleges.name as college_name',
                    'departments.name as department_name'
                );

            if ($request->search) {
                $query->where(function ($q) use ($request) {
                    $q->where('users.name', 'like', '%' . $request->search . '%')
                      ->orWhere('audit_logs.action', 'like', '%' . $request->search . '%')
                      ->orWhere('audit_logs.details', 'like', '%' . $request->search . '%');
                });
            }

            // فلترة بالإجراء — تطابق تام وليس LIKE
            if ($request->action && $request->action !== 'all') {
                $query->where('audit_logs.action', $request->action);
            }

            // فلترة بالدور — يعتمد على audit_logs.role (غير nullable)
            if ($request->role) {
                $query->where('audit_logs.role', $request->role);
            }

            // فلترة التاريخ الصحيحة: BETWEEN from_date AND to_date
            if ($request->from_date && $request->to_date) {
                $query->whereBetween('audit_logs.created_at', [
                    $request->from_date . ' 00:00:00',
                    $request->to_date . ' 23:59:59'
                ]);
            } elseif ($request->from_date) {
                $query->where('audit_logs.created_at', '>=', $request->from_date . ' 00:00:00');
            } elseif ($request->to_date) {
                $query->where('audit_logs.created_at', '<=', $request->to_date . ' 23:59:59');
            }

            $perPage = min((int)($request->per_page ?? 50), 500);
            $page = (int)($request->page ?? 1);

            $total = $query->count();
            $logs = $query->orderBy('audit_logs.created_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get()
                ->map(function ($l) {
                    return [
                        'id' => $l->id,
                        'user_id' => $l->user_id,
                        'user_name' => $l->user_name ?? 'النظام',
                        'role' => $l->role ?? '',
                        'action' => $l->action,
                        'details' => $l->details,
                        'target_id' => $l->target_id,
                        'target_type' => $l->target_type,
                        'college_name' => $l->college_name ?? '',
                        'department_name' => $l->department_name ?? '',
                        'college_id' => $l->college_id,
                        'department_id' => $l->department_id,
                        'ip_address' => $l->ip_address ?? '—',
                        'browser' => $l->browser ?? '',
                        'os' => $l->os ?? '',
                        'device' => $l->device ?? '',
                        'created_at' => $l->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $logs,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getReport(Request $request, $type)
    {
        try {
            $format = $request->format ?? 'json';

            switch ($type) {
                case 'colleges':
                    $colleges = College::with('manager')->get()->map(function ($c) {
                        $deptIds = Department::where('college_id', $c->id)->pluck('id');
                        return [
                            'name' => $c->name,
                            'manager' => $c->manager->name ?? '—',
                            'departments' => $deptIds->count(),
                            'students' => User::where('role', 'student')->whereIn('department_id', $deptIds)->count(),
                            'doctors' => User::where('role', 'doctor')->whereIn('department_id', $deptIds)->count(),
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $colleges, 'summary' => [
                        'total' => $colleges->count(),
                        'total_students' => $colleges->sum('students'),
                    ]]);

                case 'users':
                    $role = $request->role;
                    $query = User::with('department.college');
                    if ($role) $query->where('role', $role);
                    if ($request->status === 'active') $query->where('is_active', true);
                    if ($request->status === 'inactive') $query->where('is_active', false);
                    if ($request->from) $query->whereDate('created_at', '>=', $request->from);
                    if ($request->to) $query->whereDate('created_at', '<=', $request->to);
                    $users = $query->orderBy('created_at', 'desc')->get()->map(function ($u) {
                        return [
                            'name' => $u->name,
                            'email' => $u->email,
                            'role' => $u->role,
                            'college' => $u->department->college->name ?? '—',
                            'status' => ($u->is_active ?? true) ? 'نشط' : 'غير نشط',
                            'created_at' => $u->created_at ? $u->created_at->format('Y-m-d') : '—',
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $users, 'summary' => [
                        'total' => $users->count(),
                        'active' => $users->where('status', 'نشط')->count(),
                    ]]);

                case 'roles':
                    $roles = [
                        ['role' => 'مدير النظام', 'users' => User::where('role', 'system_admin')->count()],
                        ['role' => 'مدير كلية', 'users' => User::where('role', 'college_manager')->count()],
                        ['role' => 'دكتور', 'users' => User::where('role', 'doctor')->count()],
                        ['role' => 'طالب', 'users' => User::where('role', 'student')->count()],
                    ];
                    return response()->json(['success' => true, 'data' => $roles, 'summary' => [
                        'total_roles' => count($roles),
                        'total_users' => User::count(),
                    ]]);

                case 'terms':
                    $terms = Term::orderBy('start_date', 'desc')->get()->map(function ($t) {
                        return [
                            'name' => $t->name,
                            'start_date' => $t->start_date,
                            'end_date' => $t->end_date,
                            'status' => ($t->status === 'active') ? 'نشط' : 'غير نشط',
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $terms, 'summary' => [
                        'total' => $terms->count(),
                        'active' => $terms->where('status', 'نشط')->count(),
                    ]]);

                case 'students':
                    $sq = User::where('role', 'student')->with('department.college');
                    if ($request->college_id) {
                        $deptIds = Department::where('college_id', $request->college_id)->pluck('id');
                        $sq->whereIn('department_id', $deptIds);
                    }
                    if ($request->department_id) $sq->where('department_id', $request->department_id);
                    if ($request->level) $sq->where('level', $request->level);
                    if ($request->study_type) $sq->where('study_type', $request->study_type);
                    if ($request->status === 'active') $sq->where('is_active', true);
                    if ($request->status === 'inactive') $sq->where('is_active', false);
                    if ($request->from) $sq->whereDate('created_at', '>=', $request->from);
                    if ($request->to) $sq->whereDate('created_at', '<=', $request->to);
                    $students = $sq->get()->map(function ($u) {
                        return [
                            'name' => $u->name,
                            'email' => $u->email,
                            'academic_number' => $u->academic_number ?? '—',
                            'college' => $u->department->college->name ?? '—',
                            'department' => $u->department->name ?? '—',
                            'level' => $u->level ?? '—',
                            'status' => ($u->is_active ?? true) ? 'نشط' : 'غير نشط',
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $students, 'summary' => [
                        'total' => $students->count(),
                        'active' => $students->where('status', 'نشط')->count(),
                    ]]);

                case 'instructors':
                case 'doctors':
                    $dq = User::where('role', 'doctor')->with('department.college');
                    if ($request->college_id) {
                        $deptIds = Department::where('college_id', $request->college_id)->pluck('id');
                        $dq->whereIn('department_id', $deptIds);
                    }
                    if ($request->department_id) $dq->where('department_id', $request->department_id);
                    if ($request->search) $dq->where('name', 'like', '%' . $request->search . '%');
                    if ($request->status === 'active') $dq->where('is_active', true);
                    if ($request->status === 'inactive') $dq->where('is_active', false);
                    if ($request->from) $dq->whereDate('created_at', '>=', $request->from);
                    if ($request->to) $dq->whereDate('created_at', '<=', $request->to);
                    $doctors = $dq->get()->map(function ($u) {
                        $phone = $u->phone ?? '—';
                        return [
                            'name' => $u->name,
                            'email' => $u->email,
                            'phone' => $phone,
                            'title' => $u->title ?? '—',
                            'college' => $u->department->college->name ?? '—',
                            'department' => $u->department->name ?? '—',
                            'status' => ($u->is_active ?? true) ? 'نشط' : 'غير نشط',
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $doctors, 'summary' => [
                        'total' => $doctors->count(),
                        'active' => $doctors->where('status', 'نشط')->count(),
                    ]]);

                case 'college_managers':
                    $managers = User::where('role', 'college_manager')->with('managedCollege')->get()->map(function ($u) {
                        return [
                            'name' => $u->name,
                            'email' => $u->email,
                            'college' => $u->managedCollege->name ?? '—',
                            'status' => ($u->is_active ?? true) ? 'نشط' : 'غير نشط',
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $managers, 'summary' => [
                        'total' => $managers->count(),
                    ]]);

                case 'system_admins':
                    $admins = User::where('role', 'system_admin')->get()->map(function ($u) {
                        return [
                            'name' => $u->name,
                            'email' => $u->email,
                            'status' => ($u->is_active ?? true) ? 'نشط' : 'غير نشط',
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $admins, 'summary' => [
                        'total' => $admins->count(),
                    ]]);

                case 'subjects':
                    $subjq = Subject::with('department.college');
                    $hasSubjectFilter = $request->college_id || $request->department_id;
                    if ($hasSubjectFilter) {
                        $subjq->where(function ($ssq) use ($request) {
                            if ($request->college_id) {
                                $sDeptIds = Department::where('college_id', $request->college_id)->pluck('id');
                                $ssq->whereIn('department_id', $sDeptIds);
                                $offeredSubjectIds = CourseOffering::whereIn('department_id', $sDeptIds)->distinct()->pluck('subject_id');
                                $ssq->orWhereIn('id', $offeredSubjectIds);
                            }
                            if ($request->department_id) {
                                $deptSubjectIds = CourseOffering::where('department_id', $request->department_id)->distinct()->pluck('subject_id');
                                $ssq->where(function ($sssq) use ($request, $deptSubjectIds) {
                                    $sssq->where('department_id', $request->department_id)
                                        ->orWhereIn('id', $deptSubjectIds);
                                });
                            }
                        });
                    }
                    if ($request->level) $subjq->where('level', $request->level);
                    if ($request->study_type) {
                        $deptIdsForStudy = Department::where('college_id', $request->college_id)->pluck('id');
                        $stSubjectIds = CourseOffering::where('study_type', $request->study_type)
                            ->when($deptIdsForStudy->isNotEmpty(), fn($q) => $q->whereIn('department_id', $deptIdsForStudy))
                            ->pluck('subject_id');
                        $subjq->whereIn('id', $stSubjectIds);
                    }
                    $subjects = $subjq->get()->unique('id')->values()->map(function ($s) {
                        $offeringIds = CourseOffering::where('subject_id', $s->id)->pluck('id');
                        return [
                            'name' => $s->name,
                            'code' => $s->code ?? '—',
                            'department' => $s->department->name ?? '—',
                            'college' => $s->department->college->name ?? '—',
                            'level' => $s->level ?? '—',
                            'hours' => $s->hours ?? '—',
                            'type' => $s->type ?? '—',
                            'offerings' => $offeringIds->count(),
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $subjects, 'summary' => [
                        'total' => $subjects->count(),
                    ]]);

                case 'course-offerings':
                case 'course_offerings':
                    $oq = CourseOffering::with(['subject', 'doctor', 'department.college', 'term']);
                    if ($request->college_id) {
                        $oqDeptIds = Department::where('college_id', $request->college_id)->pluck('id');
                        $oq->whereIn('department_id', $oqDeptIds);
                    }
                    if ($request->department_id) $oq->where('department_id', $request->department_id);
                    if ($request->level) $oq->where('level', $request->level);
                    if ($request->study_type) {
                        $st = $request->study_type === 'عام' ? 'general' : ($request->study_type === 'موازي' ? 'paid' : $request->study_type);
                        $oq->where('study_type', $st);
                    }
                    $offerings = $oq->get()->map(function ($o) {
                        return [
                            'subject' => $o->subject->name ?? '—',
                            'doctor' => $o->doctor->name ?? '—',
                            'department' => $o->department->name ?? '—',
                            'college' => $o->department->college->name ?? '—',
                            'level' => $o->level,
                            'term' => $o->term->name ?? '—',
                            'study_type' => $o->study_type === 'general' ? 'نظري' : ($o->study_type === 'both' ? 'نظري+عملي' : ($o->study_type === 'paid' ? 'مدفوع' : $o->study_type)),
                            'students' => StudentEnrollment::where('offering_id', $o->id)->count(),
                        ];
                    });
                    return response()->json(['success' => true, 'data' => $offerings, 'summary' => [
                        'total' => $offerings->count(),
                        'total_students' => $offerings->sum('students'),
                    ]]);

                case 'activities':
                    $logs = DB::table('audit_logs')
                        ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
                        ->select('audit_logs.*', 'users.name as user_name')
                        ->orderBy('created_at', 'desc')->limit(200)->get()->map(function ($l) {
                            return [
                                'user' => $l->user_name ?? 'النظام',
                                'action' => $l->action,
                                'details' => $l->details,
                                'time' => $l->created_at,
                                'ip' => $l->ip_address ?? '—',
                            ];
                        });
                    return response()->json(['success' => true, 'data' => $logs, 'summary' => [
                        'total' => DB::table('audit_logs')->count(),
                    ]]);

                default:
                    return response()->json(['success' => false, 'message' => 'نوع التقرير غير معروف'], 400);
            }
        } catch (\Throwable $e) {
            \Log::error('getReport error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء إنشاء التقرير: ' . $e->getMessage()], 500);
        }
    }

    public function exportReportCsv(Request $request, $type)
    {
        try {
            $reportResponse = $this->getReport($request, $type);
            $report = $reportResponse->getData(true);
            if (!($report['success'] ?? false) || !isset($report['data']) || empty($report['data'])) {
                return response()->json(['success' => false, 'message' => 'لا توجد بيانات'], 404);
            }
            $data = $report['data'];
            $headers = array_keys((array) $data[0]);
            $filename = $type . '_report_' . date('Y-m-d') . '.csv';

            $output = fopen('php://temp', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, $headers);
            foreach ($data as $row) {
                $csvRow = [];
                foreach ($headers as $h) {
                    $val = is_array($row) ? ($row[$h] ?? '') : ($row->$h ?? '');
                    $csvRow[] = $val;
                }
                fputcsv($output, $csvRow);
            }
            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'فشل تصدير التقرير: ' . $e->getMessage()], 500);
        }
    }

    public function getSettings()
    {
        try {
            $hasTable = DB::select("SHOW TABLES LIKE 'system_settings'");
            if (empty($hasTable)) {
                return response()->json(['success' => true, 'data' => []]);
            }
            $settings = DB::table('system_settings')->pluck('value', 'key');
            return response()->json(['success' => true, 'data' => $settings]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    public function updateSettings(Request $request)
    {
        try {
            $validated = $request->validate([
                'settings' => 'required|array',
            ]);

            $hasTable = DB::select("SHOW TABLES LIKE 'system_settings'");
            if (empty($hasTable)) {
                DB::statement("
                    CREATE TABLE system_settings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        `key` VARCHAR(191) NOT NULL UNIQUE,
                        value TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }

            foreach ($validated['settings'] as $key => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => is_array($value) ? json_encode($value) : $value]
                );
            }

            $this->logAction('تعديل إعدادات النظام', 'تم تعديل إعدادات النظام');

            return response()->json(['success' => true, 'message' => 'تم حفظ الإعدادات بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getSubjectsReport(Request $request)
    {
        try {
            $sq = Subject::with('department.college');
            if ($request->college_id) {
                $sDeptIds = Department::where('college_id', $request->college_id)->pluck('id');
                $sq->whereIn('department_id', $sDeptIds);
            }
            if ($request->department_id) $sq->where('department_id', $request->department_id);
            if ($request->level) $sq->where('level', $request->level);
            $subjects = $sq->get()->map(function ($s) {
                return [
                    'name' => $s->name,
                    'code' => $s->code ?? '—',
                    'department' => $s->department->name ?? '—',
                    'college' => $s->department->college->name ?? '—',
                    'level' => $s->level ?? '—',
                    'offerings' => CourseOffering::where('subject_id', $s->id)->count(),
                    'students' => StudentEnrollment::whereIn('offering_id', CourseOffering::where('subject_id', $s->id)->pluck('id'))->count(),
                    'hours' => $s->hours ?? '—',
                    'type' => $s->type ?? '—',
                ];
            });
            return response()->json(['success' => true, 'data' => $subjects, 'summary' => [
                'total' => $subjects->count(),
                'total_offerings' => $subjects->sum('offerings'),
            ]]);
        } catch (\Throwable $e) {
            \Log::error('getSubjectsReport error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء إنشاء تقرير المواد: ' . $e->getMessage()], 500);
        }
    }

    public function getCourseOfferingsForCollege(Request $request)
    {
        try {
            $query = CourseOffering::with(['subject', 'doctor', 'department', 'term']);

            if ($request->college_id) {
                $deptIds = Department::where('college_id', $request->college_id)->pluck('id');
                $query->whereIn('department_id', $deptIds);
            }
            if ($request->department_id) {
                $query->where('department_id', $request->department_id);
            }
            if ($request->level) {
                $query->where('level', $request->level);
            }
            if ($request->subject_id) {
                $query->where('subject_id', $request->subject_id);
            }

            $offerings = $query->orderBy('id', 'desc')->get()->map(function ($o) {
                return [
                    'id' => $o->id,
                    'subject_id' => $o->subject_id,
                    'subject_name' => $o->subject->name ?? '—',
                    'subject_code' => $o->subject->code ?? '',
                    'doctor_id' => $o->doctor_id,
                    'doctor_name' => $o->doctor->name ?? '—',
                    'ta_id' => $o->ta_id,
                    'ta_name' => $o->ta ? $o->ta->name : null,
                    'department_id' => $o->department_id,
                    'department_name' => $o->department->name ?? '—',
                    'level' => $o->level,
                    'term_id' => $o->term_id,
                    'term_name' => $o->term->name ?? '—',
                    'study_type' => $o->study_type ?? '—',
                    'students_count' => StudentEnrollment::where('offering_id', $o->id)->count(),
                ];
            });

            return response()->json(['success' => true, 'data' => $offerings]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createCourseOffering(Request $request)
    {
        try {
            $validated = $request->validate([
                'subject_id' => 'required|integer|exists:subjects,id',
                'doctor_id' => 'required|integer|exists:users,id',
                'ta_id' => 'nullable|integer|exists:users,id',
                'department_id' => 'required|integer|exists:departments,id',
                'level' => 'required|integer|min:1|max:12',
                'term_id' => 'required|integer|exists:terms,id',
                'study_type' => 'required|string|in:general,paid,both',
            ]);

            $offering = CourseOffering::create([
                'subject_id' => $validated['subject_id'],
                'doctor_id' => $validated['doctor_id'],
                'ta_id' => $validated['ta_id'] ?? null,
                'department_id' => $validated['department_id'],
                'level' => $validated['level'],
                'term_id' => $validated['term_id'],
                'study_type' => $validated['study_type'],
            ]);

            $this->logAction('ربط مادة', 'تم ربط المادة بالدكتور');

            return response()->json(['success' => true, 'message' => 'تم ربط المادة بالدكتور بنجاح', 'id' => $offering->id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteCourseOffering($id)
    {
        try {
            $offering = CourseOffering::find($id);
            if (!$offering) return response()->json(['success' => false, 'message' => 'الربط غير موجود'], 404);

            $enrollmentsCount = StudentEnrollment::where('offering_id', $id)->count();
            if ($enrollmentsCount > 0) {
                return response()->json(['success' => false, 'message' => 'لا يمكن حذف الربط لأنه مرتبط بطلاب'], 400);
            }

            $offering->delete();

            $this->logAction('فك ربط مادة', 'تم فك ربط المادة');

            return response()->json(['success' => true, 'message' => 'تم حذف الربط بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getCourseOfferingsReport(Request $request)
    {
        try {
            $oq = CourseOffering::with(['subject', 'doctor', 'department.college', 'term']);
            if ($request->college_id) {
                $oqDeptIds = Department::where('college_id', $request->college_id)->pluck('id');
                $oq->whereIn('department_id', $oqDeptIds);
            }
            if ($request->department_id) $oq->where('department_id', $request->department_id);
            if ($request->level) $oq->where('level', $request->level);
            if ($request->study_type) {
                $st = $request->study_type === 'عام' ? 'general' : ($request->study_type === 'موازي' ? 'paid' : $request->study_type);
                $oq->where('study_type', $st);
            }
            $offerings = $oq->get()->map(function ($o) {
                return [
                    'subject' => $o->subject->name ?? '—',
                    'doctor' => $o->doctor->name ?? '—',
                    'department' => $o->department->name ?? '—',
                    'college' => $o->department->college->name ?? '—',
                    'level' => $o->level,
                    'term' => $o->term->name ?? '—',
                    'study_type' => $o->study_type === 'general' ? 'نظري' : ($o->study_type === 'both' ? 'نظري+عملي' : ($o->study_type === 'paid' ? 'مدفوع' : $o->study_type)),
                    'students' => StudentEnrollment::where('offering_id', $o->id)->count(),
                ];
            });
            return response()->json(['success' => true, 'data' => $offerings, 'summary' => [
                'total' => $offerings->count(),
                'total_students' => $offerings->sum('students'),
            ]]);
        } catch (\Throwable $e) {
            \Log::error('getCourseOfferingsReport error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء إنشاء تقرير الشعب: ' . $e->getMessage()], 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $q = $request->q;
            if (!$q) return response()->json(['success' => true, 'data' => []]);

            $users = User::where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")->limit(10)->get()->map(function ($u) {
                return ['type' => 'user', 'id' => $u->id, 'text' => $u->name . ' (' . $u->email . ')', 'role' => $u->role];
            });

            $colleges = College::where('name', 'like', "%$q%")->limit(5)->get()->map(function ($c) {
                return ['type' => 'college', 'id' => $c->id, 'text' => $c->name];
            });

            $subjects = Subject::where('name', 'like', "%$q%")->limit(5)->get()->map(function ($s) {
                return ['type' => 'subject', 'id' => $s->id, 'text' => $s->name];
            });

            $results = collect()->concat($users)->concat($colleges)->concat($subjects);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateAdminProfile(Request $request, $id)
    {
        try {
            $user = User::find($id);
            if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);

            $validated = $request->validate([
                'email' => 'nullable|email|unique:users,email,' . $id,
                'phone' => 'nullable|string|max:20',
                'name' => 'nullable|string|max:255',
            ]);

            if ($request->has('email')) $user->email = $validated['email'];
            if ($request->has('phone')) $user->phone = $validated['phone'];
            if ($request->has('name')) $user->name = $validated['name'];
            $user->save();

            return response()->json(['success' => true, 'message' => 'تم تحديث الملف الشخصي']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getProfileWithQR($id)
    {
        try {
            $user = User::with('department.college')->find($id);
            if (!$user) return response()->json(['success' => false, 'message' => 'المستخدم غير موجود'], 404);

            $collegeName = '';
            $departmentName = '';
            if ($user->department && $user->department->college) {
                $departmentName = $user->department->name;
                $collegeName = $user->department->college->name;
            } elseif ($user->role === 'college_manager') {
                $college = College::where('manager_id', $user->id)->first();
                if ($college) $collegeName = $college->name;
            }

            $qrValue = 'RABET_USER:' . $user->id . ':' . ($user->qr_token ?? md5($user->email));

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                    'role' => $user->role,
                    'department_name' => $departmentName,
                    'college_name' => $collegeName,
                    'is_active' => $user->is_active ?? true,
                    'created_at' => $user->created_at ? $user->created_at->format('Y-m-d') : '',
                    'qr_code' => $qrValue,
                    'avatar_type' => $user->avatar_type ?? 'initials',
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getCollegesForSelect()
    {
        try {
            $colleges = College::select('id', 'name')->orderBy('name')->get();
            return response()->json(['success' => true, 'data' => $colleges]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getDepartmentsByCollege($collegeId)
    {
        try {
            $departments = Department::where('college_id', $collegeId)->select('id', 'name')->orderBy('name')->get();
            return response()->json(['success' => true, 'data' => $departments]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getDepartmentLevels(Request $request)
    {
        try {
            $query = Subject::select('level')->distinct()->whereNotNull('level')->orderBy('level');
            if ($request->department_id) $query->where('department_id', $request->department_id);
            if ($request->college_id) {
                $deptIds = Department::where('college_id', $request->college_id)->pluck('id');
                $query->whereIn('department_id', $deptIds);
            }
            $levels = $query->pluck('level');
            return response()->json(['success' => true, 'data' => $levels]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function fixPhoneNumbers()
    {
        try {
            $defaultPhone = '05XXXXXXXX';
            $count = User::whereNull('phone')->orWhere('phone', '')->orWhere('phone', 'null')->count();
            User::whereNull('phone')->orWhere('phone', '')->orWhere('phone', 'null')
                ->update(['phone' => $defaultPhone]);

            return response()->json(['success' => true, 'message' => "تم تحديث $count مستخدم برقم هاتف افتراضي"]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function addIsActiveToColleges()
    {
        try {
            $hasColumn = DB::select("SHOW COLUMNS FROM colleges LIKE 'is_active'");
            if (empty($hasColumn)) {
                DB::statement("ALTER TABLE colleges ADD COLUMN is_active tinyint(1) DEFAULT 1 AFTER manager_id");
                return response()->json(['success' => true, 'message' => 'تم إضافة عمود is_active إلى جدول الكليات']);
            }
            return response()->json(['success' => true, 'message' => 'العمود موجود مسبقاً']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportAuditLogs_csv(Request $request)
    {
        return $this->exportAuditLogs($request, 'csv');
    }

    public function exportAuditLogs_excel(Request $request)
    {
        return $this->exportAuditLogs($request, 'xlsx');
    }

    public function exportAuditLogs_pdf(Request $request)
    {
        return $this->exportAuditLogs($request, 'pdf');
    }

    private function exportAuditLogs(Request $request, $format)
    {
        try {
            $query = DB::table('audit_logs')
                ->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
                ->leftJoin('colleges', 'audit_logs.college_id', '=', 'colleges.id')
                ->leftJoin('departments', 'audit_logs.department_id', '=', 'departments.id')
                ->select(
                    'audit_logs.*',
                    'users.name as user_name',
                    'colleges.name as college_name',
                    'departments.name as department_name'
                );

            if ($request->action && $request->action !== 'all') {
                $query->where('audit_logs.action', $request->action);
            }
            if ($request->role) {
                $query->where('audit_logs.role', $request->role);
            }
            if ($request->college_id) {
                $query->where('audit_logs.college_id', $request->college_id);
            }
            if ($request->department_id) {
                $query->where('audit_logs.department_id', $request->department_id);
            }
            if ($request->from_date && $request->to_date) {
                $query->whereBetween('audit_logs.created_at', [
                    $request->from_date . ' 00:00:00',
                    $request->to_date . ' 23:59:59'
                ]);
            } elseif ($request->from_date) {
                $query->where('audit_logs.created_at', '>=', $request->from_date . ' 00:00:00');
            } elseif ($request->to_date) {
                $query->where('audit_logs.created_at', '<=', $request->to_date . ' 23:59:59');
            }

            $logs = $query->orderBy('audit_logs.created_at', 'desc')->limit(10000)->get();

            switch ($format) {
                case 'csv':
                    return $this->exportCsv($logs);
                case 'xlsx':
                    return $this->exportXlsx($logs);
                case 'pdf':
                    return $this->exportPdf($logs);
                default:
                    return response()->json(['success' => false, 'message' => 'صيغة غير مدعومة'], 400);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function exportCsv($logs)
    {
        $headers = ['المستخدم', 'الدور', 'نوع العملية', 'الوصف', 'التاريخ', 'الوقت', 'القسم', 'الكلية'];
        $output = "\xEF\xBB\xBF";
        $output .= implode(',', $headers) . "\n";

        foreach ($logs as $l) {
            $created = $l->created_at ? \Carbon\Carbon::parse($l->created_at) : null;
            $date = $created ? $created->format('Y-m-d') : '';
            $time = $created ? $created->format('H:i:s') : '';
            $row = [
                str_replace(',', '،', $l->user_name ?? 'النظام'),
                str_replace(',', '،', $l->role ?? ''),
                str_replace(',', '،', $l->action),
                str_replace(',', '،', $l->details ?? ''),
                $date,
                $time,
                str_replace(',', '،', $l->department_name ?? ''),
                str_replace(',', '،', $l->college_name ?? ''),
            ];
            $output .= implode(',', $row) . "\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="audit_logs.csv"',
        ]);
    }

    private function exportXlsx($logs)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        $sheet->setCellValue('A1', 'المستخدم');
        $sheet->setCellValue('B1', 'الدور');
        $sheet->setCellValue('C1', 'نوع العملية');
        $sheet->setCellValue('D1', 'الوصف');
        $sheet->setCellValue('E1', 'التاريخ');
        $sheet->setCellValue('F1', 'الوقت');
        $sheet->setCellValue('G1', 'القسم');
        $sheet->setCellValue('H1', 'الكلية');

        $row = 2;
        foreach ($logs as $l) {
            $created = $l->created_at ? \Carbon\Carbon::parse($l->created_at) : null;
            $sheet->setCellValue('A' . $row, $l->user_name ?? 'النظام');
            $sheet->setCellValue('B' . $row, $l->role ?? '');
            $sheet->setCellValue('C' . $row, $l->action);
            $sheet->setCellValue('D' . $row, $l->details ?? '');
            $sheet->setCellValue('E' . $row, $created ? $created->format('Y-m-d') : '');
            $sheet->setCellValue('F' . $row, $created ? $created->format('H:i:s') : '');
            $sheet->setCellValue('G' . $row, $l->department_name ?? '');
            $sheet->setCellValue('H' . $row, $l->college_name ?? '');
            $row++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="audit_logs.xlsx"',
        ]);
    }

    private function exportPdf($logs)
    {
        $html = '<style>
            @page { margin: 10mm; }
            body { font-family: "DejaVu Sans", sans-serif; direction: rtl; font-size: 12px; }
            h2 { text-align: center; margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: right; }
            th { background: #4a90d9; color: white; }
            tr:nth-child(even) { background: #f9f9f9; }
        </style>';
        $html .= '<h2>سجل العمليات</h2>';
        $html .= '<table><thead><tr>';
        $html .= '<th>المستخدم</th><th>الدور</th><th>نوع العملية</th><th>الوصف</th><th>التاريخ</th><th>الوقت</th><th>القسم</th><th>الكلية</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($logs as $l) {
            $created = $l->created_at ? \Carbon\Carbon::parse($l->created_at) : null;
            $html .= '<tr>';
            $html .= '<td>' . e($l->user_name ?? 'النظام') . '</td>';
            $html .= '<td>' . e($l->role ?? '') . '</td>';
            $html .= '<td>' . e($l->action) . '</td>';
            $html .= '<td>' . e($l->details ?? '') . '</td>';
            $html .= '<td>' . ($created ? $created->format('Y-m-d') : '') . '</td>';
            $html .= '<td>' . ($created ? $created->format('H:i:s') : '') . '</td>';
            $html .= '<td>' . e($l->department_name ?? '') . '</td>';
            $html .= '<td>' . e($l->college_name ?? '') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p style="text-align:center;margin-top:15px;color:#888;font-size:11px;">تم التصدير في: ' . now()->format('Y-m-d H:i:s') . '</p>';

        $pdf = new \Barryvdh\DomPDF\PDF(app());
        $pdf->loadHTML($html);
        $pdf->setPaper('A4', 'landscape');
        return $pdf->download('audit_logs.pdf');
    }

    // ================= Official Students =================

    public function getOfficialStudents(Request $request)
    {
        try {
            $query = OfficialStudent::query();
            if ($request->college_id) {
                $query->where(function ($q) use ($request) {
                    $q->where('college_id', $request->college_id)
                      ->orWhereNull('college_id');
                });
            }
            if ($request->department_id) {
                $query->where('department_id', $request->department_id);
            }
            if ($request->level) {
                $query->where('level', $request->level);
            }
            if ($request->search) {
                $q = $request->search;
                $query->where(function ($sub) use ($q) {
                    $sub->where('student_name', 'like', "%{$q}%")
                        ->orWhere('academic_number', 'like', "%{$q}%");
                });
            }
            $students = $query->orderBy('id', 'desc')->get()->map(function ($s) {
                return [
                    'id' => $s->id,
                    'college_id' => $s->college_id,
                    'department_id' => $s->department_id,
                    'department_name' => optional($s->department)->name,
                    'level' => $s->level,
                    'academic_number' => $s->academic_number,
                    'student_name' => $s->student_name,
                    'created_at' => $s->created_at,
                    'updated_at' => $s->updated_at,
                ];
            });
            return response()->json(['success' => true, 'data' => $students]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createOfficialStudent(Request $request)
    {
        try {
            $validated = $request->validate([
                'college_id' => 'required|integer|exists:colleges,id',
                'department_id' => 'required|integer|exists:departments,id',
                'level' => 'required|integer|min:1',
                'academic_number' => 'required|string|max:50|unique:official_students,academic_number',
                'student_name' => 'required|string|max:255',
            ]);

            $student = OfficialStudent::create($validated);

            $this->logAction('إضافة طالب رسمي', 'تم إضافة طالب: ' . $student->student_name);

            return response()->json(['success' => true, 'message' => 'تم إضافة الطالب بنجاح', 'id' => $student->id]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateOfficialStudent(Request $request, $id)
    {
        try {
            $student = OfficialStudent::find($id);
            if (!$student) return response()->json(['success' => false, 'message' => 'الطالب غير موجود'], 404);

            $validated = $request->validate([
                'college_id' => 'required|integer|exists:colleges,id',
                'department_id' => 'required|integer|exists:departments,id',
                'level' => 'required|integer|min:1',
                'academic_number' => 'required|string|max:50|unique:official_students,academic_number,' . $id,
                'student_name' => 'required|string|max:255',
            ]);

            $student->update($validated);

            $this->logAction('تعديل طالب رسمي', 'تم تعديل طالب: ' . $student->student_name);

            return response()->json(['success' => true, 'message' => 'تم تعديل الطالب بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteOfficialStudent($id)
    {
        try {
            $student = OfficialStudent::find($id);
            if (!$student) return response()->json(['success' => false, 'message' => 'الطالب غير موجود'], 404);

            $name = $student->student_name;
            $student->delete();

            $this->logAction('حذف طالب رسمي', 'تم حذف طالب: ' . $name);

            return response()->json(['success' => true, 'message' => 'تم حذف الطالب بنجاح']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function importOfficialStudents(Request $request)
    {
        try {
            $validated = $request->validate([
                'college_id' => 'required|integer|exists:colleges,id',
                'students' => 'required|array',
                'students.*.academic_number' => 'required|string|max:50',
                'students.*.student_name' => 'required|string|max:255',
                'students.*.department_id' => 'required|integer|exists:departments,id',
                'students.*.level' => 'required|integer|min:1',
            ]);

            $inserted = 0;
            $skipped = 0;
            foreach ($validated['students'] as $row) {
                $exists = OfficialStudent::where(function ($q) use ($validated, $row) {
                    $q->where('academic_number', $row['academic_number']);
                    $q->where(function ($sub) use ($validated) {
                        $sub->where('college_id', $validated['college_id'])
                             ->orWhereNull('college_id');
                    });
                })->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
                OfficialStudent::create([
                    'college_id' => $validated['college_id'],
                    'department_id' => $row['department_id'],
                    'level' => $row['level'],
                    'academic_number' => $row['academic_number'],
                    'student_name' => $row['student_name'],
                ]);
                $inserted++;
            }

            $this->logAction('استيراد طلاب رسميين', "تم استيراد {$inserted} طالب، تخطي {$skipped}");

            return response()->json([
                'success' => true,
                'message' => "تم استيراد {$inserted} طالب بنجاح، تخطي {$skipped} مكرر",
                'inserted' => $inserted,
                'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ===================== Announcements (College Manager) =====================

    public function getAnnouncements(Request $request)
    {
        try {
            $scope = $request->scope; // 'college' (default) or 'system'

            if ($scope === 'system') {
                $announcements = Announcement::with('sender', 'targetCollege')
                    ->whereNotNull('target_role')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(fn($a) => [
                        'id' => $a->id,
                        'title' => $a->title,
                        'body' => $a->body,
                        'target_role' => $a->target_role,
                        'target_college_id' => $a->target_college_id,
                        'target_college_name' => $a->targetCollege->name ?? '',
                        'attachment' => $a->attachment,
                        'status' => $a->status,
                        'sender_name' => $a->sender->name ?? '',
                        'created_at' => $a->created_at,
                        'updated_at' => $a->updated_at,
                    ]);
            } else {
                $collegeId = $request->college_id;
                $query = Announcement::with('sender')
                    ->whereNotNull('college_id')
                    ->orderBy('created_at', 'desc');

                if ($collegeId) {
                    $query->where('college_id', $collegeId);
                }

                $announcements = $query->get()->map(fn($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'target_type' => $a->target_type,
                    'attachment' => $a->attachment,
                    'status' => $a->status,
                    'sender_name' => $a->sender->name ?? '',
                    'created_at' => $a->created_at,
                    'updated_at' => $a->updated_at,
                ]);
            }

            return response()->json(['success' => true, 'data' => $announcements]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createAnnouncement(Request $request)
    {
        try {
            $senderRole = $request->sender_role ?? 'college_manager';

            if ($senderRole === 'system_admin') {
                $validated = $request->validate([
                    'sender_id' => 'required|integer|exists:users,id',
                    'title' => 'required|string|max:255',
                    'body' => 'required|string',
                    'target_role' => 'required|in:college_manager,doctor,student,all',
                    'target_college_id' => 'nullable|integer|exists:colleges,id',
                    'target_user_ids' => 'nullable|string',
                    'attachment' => 'nullable|string',
                ]);

                $ann = Announcement::create([
                    'sender_id' => $validated['sender_id'],
                    'title' => $validated['title'],
                    'body' => $validated['body'],
                    'target_role' => $validated['target_role'],
                    'target_college_id' => $validated['target_college_id'] ?? null,
                    'attachment' => $validated['attachment'] ?? null,
                    'status' => 'published',
                ]);

                $userQuery = User::where('is_active', true);

                if (!empty($validated['target_user_ids'])) {
                    $ids = array_map('intval', explode(',', $validated['target_user_ids']));
                    $userQuery->whereIn('id', $ids);
                } elseif ($validated['target_role'] === 'college_manager') {
                    $userQuery->where('role', 'college_manager');
                    if ($validated['target_college_id']) {
                        $userQuery->whereIn('id', College::where('id', $validated['target_college_id'])->pluck('manager_id'));
                    }
                } elseif ($validated['target_role'] === 'doctor') {
                    $userQuery->where('role', 'doctor');
                    if ($validated['target_college_id']) {
                        $deptIds = Department::where('college_id', $validated['target_college_id'])->pluck('id');
                        $userQuery->whereIn('department_id', $deptIds);
                    }
                } elseif ($validated['target_role'] === 'student') {
                    $userQuery->where('role', 'student');
                    if ($validated['target_college_id']) {
                        $deptIds = Department::where('college_id', $validated['target_college_id'])->pluck('id');
                        $userQuery->whereIn('department_id', $deptIds);
                    }
                } elseif ($validated['target_role'] === 'all') {
                    if ($validated['target_college_id']) {
                        $deptIds = Department::where('college_id', $validated['target_college_id'])->pluck('id');
                        $userQuery->whereIn('department_id', $deptIds);
                    }
                }

                $targetUsers = $userQuery->get();

                foreach ($targetUsers as $user) {
                    Notification::create([
                        'user_id' => $user->id,
                        'title' => $ann->title,
                        'type' => 'system_announcement',
                        'body' => $ann->body,
                        'message' => $ann->body,
                        'notification_type' => 'system_announcement',
                        'reference_type' => 'announcement',
                        'reference_id' => $ann->id,
                        'offering_id' => null,
                        'is_read' => false,
                        'created_at' => now(),
                    ]);
                }

                $this->logAction('إرسال إعلان نظام', 'إعلان نظام: ' . $ann->title);

                return response()->json([
                    'success' => true,
                    'data' => $ann,
                    'message' => 'تم إنشاء الإعلان وإرسال الإشعارات لـ ' . $targetUsers->count() . ' مستخدم',
                ]);
            } else {
                // College manager announcement
                $validated = $request->validate([
                    'college_id' => 'required|integer|exists:colleges,id',
                    'sender_id' => 'required|integer|exists:users,id',
                    'title' => 'required|string|max:255',
                    'body' => 'required|string',
                    'target_type' => 'required|in:students,doctors,all',
                    'attachment' => 'nullable|string',
                ]);

                $ann = Announcement::create([
                    'college_id' => $validated['college_id'],
                    'sender_id' => $validated['sender_id'],
                    'title' => $validated['title'],
                    'body' => $validated['body'],
                    'target_type' => $validated['target_type'],
                    'attachment' => $validated['attachment'] ?? null,
                    'status' => 'published',
                ]);

                $deptIds = Department::where('college_id', $validated['college_id'])->pluck('id');

                if ($deptIds->isNotEmpty()) {
                    $userQuery = User::whereIn('department_id', $deptIds)->where('is_active', true);

                    if ($validated['target_type'] === 'students') {
                        $userQuery->where('role', 'student');
                    } elseif ($validated['target_type'] === 'doctors') {
                        $userQuery->where('role', 'doctor');
                    }

                    $targetUsers = $userQuery->get();

                    foreach ($targetUsers as $user) {
                        Notification::create([
                            'user_id' => $user->id,
                            'title' => $ann->title,
                            'type' => 'announcement',
                            'body' => $ann->body,
                            'message' => $ann->body,
                            'notification_type' => 'college_announcement',
                            'reference_type' => 'announcement',
                            'reference_id' => $ann->id,
                            'offering_id' => null,
                            'is_read' => false,
                            'created_at' => now(),
                        ]);
                    }
                }

                $this->logAction('إرسال إعلان', 'إرسال إعلان للكلية: ' . $ann->title);

                return response()->json([
                    'success' => true,
                    'data' => $ann,
                    'message' => 'تم إنشاء الإعلان وإرسال الإشعارات',
                ]);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateAnnouncement(Request $request, $id)
    {
        try {
            $ann = Announcement::find($id);
            if (!$ann || (!$ann->college_id && !$ann->target_role)) {
                return response()->json(['success' => false, 'message' => 'الإعلان غير موجود'], 404);
            }

            $ann->title = $request->title ?? $ann->title;
            $ann->body = $request->body ?? $ann->body;
            if ($request->has('target_type')) {
                $ann->target_type = $request->target_type;
            }
            if ($request->has('target_role')) {
                $ann->target_role = $request->target_role;
            }
            if ($request->has('target_college_id')) {
                $ann->target_college_id = $request->target_college_id;
            }
            if ($request->has('attachment')) {
                $ann->attachment = $request->attachment;
            }
            $ann->save();

            $label = $ann->target_role ? 'إعلان نظام' : 'إعلان';
            $this->logAction('تعديل ' . $label, 'تم تعديل ' . $label . ': ' . $ann->title);

            return response()->json(['success' => true, 'data' => $ann, 'message' => 'تم تحديث الإعلان']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteAnnouncement(Request $request, $id)
    {
        try {
            $ann = Announcement::find($id);
            if (!$ann || (!$ann->college_id && !$ann->target_role)) {
                return response()->json(['success' => false, 'message' => 'الإعلان غير موجود'], 404);
            }

            DB::beginTransaction();

            Notification::where('reference_type', 'announcement')
                ->where('reference_id', $id)
                ->delete();

            $ann->delete();

            DB::commit();

            $label = $ann->target_role ? 'إعلان نظام' : 'إعلان';
            $this->logAction('حذف ' . $label, 'تم حذف ' . $label . ': ' . $ann->title);

            return response()->json(['success' => true, 'message' => 'تم حذف الإعلان وجميع الإشعارات المرتبطة به']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendNotification(Request $request)
    {
        try {
            $validated = $request->validate([
                'sender_id' => 'required|integer|exists:users,id',
                'title' => 'required|string|max:255',
                'body' => 'required|string',
                'target_role' => 'required|in:college_manager,doctor,student,all',
                'target_college_id' => 'nullable|integer|exists:colleges,id',
            ]);

            $userQuery = User::where('is_active', true);

            if ($validated['target_role'] === 'college_manager') {
                $userQuery->where('role', 'college_manager');
                if ($validated['target_college_id']) {
                    $userQuery->whereIn('id', College::where('id', $validated['target_college_id'])->pluck('manager_id'));
                }
            } elseif ($validated['target_role'] === 'doctor') {
                $userQuery->where('role', 'doctor');
                if ($validated['target_college_id']) {
                    $deptIds = Department::where('college_id', $validated['target_college_id'])->pluck('id');
                    $userQuery->whereIn('department_id', $deptIds);
                }
            } elseif ($validated['target_role'] === 'student') {
                $userQuery->where('role', 'student');
                if ($validated['target_college_id']) {
                    $deptIds = Department::where('college_id', $validated['target_college_id'])->pluck('id');
                    $userQuery->whereIn('department_id', $deptIds);
                }
            } elseif ($validated['target_role'] === 'all') {
                if ($validated['target_college_id']) {
                    $deptIds = Department::where('college_id', $validated['target_college_id'])->pluck('id');
                    $userQuery->whereIn('department_id', $deptIds);
                }
            }

            $targetUsers = $userQuery->get();
            $count = 0;

            foreach ($targetUsers as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => $validated['title'],
                    'type' => 'system_notification',
                    'body' => $validated['body'],
                    'message' => $validated['body'],
                    'notification_type' => 'system_notification',
                    'reference_type' => null,
                    'reference_id' => null,
                    'offering_id' => null,
                    'is_read' => false,
                    'created_at' => now(),
                ]);
                $count++;
            }

            $this->logAction('إرسال إشعار نظام', 'إشعار نظام: ' . $validated['title']);

            return response()->json([
                'success' => true,
                'message' => "تم إرسال الإشعار إلى {$count} مستخدم",
                'count' => $count,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
