<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required'
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'المستخدم غير موجود'
                ], 404);
            }

            // ✅ التحقق من كلمة المرور
            $passwordOk = false;

            if (Hash::check($request->password, $user->password)) {
                $passwordOk = true;
            } elseif ($request->password === $user->password) {
                $passwordOk = true;
                // تحديث كلمة المرور إلى Bcrypt
                $user->password = Hash::make($request->password);
                $user->save();
            }

            if (!$passwordOk) {
                return response()->json([
                    'success' => false,
                    'message' => 'كلمة المرور غير صحيحة'
                ], 401);
            }

            // ✅ استخدام role
            $allowedRoles = ['system_admin', 'college_manager', 'doctor', 'student'];

            if (!in_array($user->role, $allowedRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'هذا الحساب لا يملك صلاحية الدخول'
                ], 403);
            }

            $collegeId = null;
            if ($user->role === 'college_manager') {
                $collegeId = \App\Models\College::where('manager_id', $user->id)->value('id');
            } elseif ($user->department_id) {
                $collegeId = \App\Models\Department::where('id', $user->department_id)->value('college_id');
            }

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'title' => $user->title ?? '',
                    'department_id' => $user->department_id,
                    'college_id' => $collegeId,
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطأ في السيرفر: ' . $e->getMessage()
            ], 500);
        }
    }
}