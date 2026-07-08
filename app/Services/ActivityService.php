<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivityService
{
    public function log(
        ?string $action = null,
        ?string $description = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $userId = null,
        array $extra = []
    ): void {
        try {
            if (!$userId) {
                $userId = auth()->id() ?? request()->input('user_id');
            }
            if (!$userId) {
                return;
            }
            $request = request();
            $data = [
                'user_id' => $userId,
                'action' => $action,
                'details' => $description,
                'target_id' => $entityId,
                'target_type' => $entityType,
                'ip_address' => $request ? $request->ip() : null,
                'user_agent' => $request ? $request->header('User-Agent') : null,
                'created_at' => now(),
            ];
            $parsed = $this->parseUserAgent($data['user_agent']);
            $data['browser'] = $parsed['browser'];
            $data['os'] = $parsed['os'];
            $data['device'] = $parsed['device'];
            $user = User::find($userId);
            if ($user) {
                $data['role'] = $user->role;
                $data['college_id'] = $this->getUserCollegeId($user);
                $data['department_id'] = $user->department_id;
            }
            foreach ($extra as $key => $value) {
                if (in_array($key, ['college_id', 'department_id', 'role'])) {
                    $data[$key] = $value;
                }
            }
            DB::table('audit_logs')->insert($data);
        } catch (\Throwable $e) {
        }
    }

    private function parseUserAgent(?string $ua): array
    {
        $browser = 'Unknown';
        $os = 'Unknown';
        $device = 'Desktop';
        if (!$ua) {
            return ['browser' => $browser, 'os' => $os, 'device' => $device];
        }
        if (preg_match('/Chrome\/(\d+)/', $ua) && !preg_match('/Edg\/|OPR\/|Brave/', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\/(\d+)/', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\/(\d+)/', $ua) && !preg_match('/Chrome/', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/Edg\/(\d+)/', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\//', $ua)) {
            $browser = 'Opera';
        }
        if (preg_match('/Windows NT/', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/', $ua) && !preg_match('/Android/', $ua)) {
            $os = 'Linux';
        } elseif (preg_match('/Android/', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad/', $ua)) {
            $os = 'iOS';
        }
        if (preg_match('/Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/', $ua)) {
            $device = 'Mobile';
        } elseif (preg_match('/Tablet|iPad/', $ua)) {
            $device = 'Tablet';
        }
        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }

    private function getUserCollegeId(User $user): ?int
    {
        if ($user->role === 'college_manager') {
            return \App\Models\College::where('manager_id', $user->id)->value('id');
        }
        if ($user->department_id) {
            return \App\Models\Department::where('id', $user->department_id)->value('college_id');
        }
        return null;
    }
}
