<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAuditLogRoles extends Command
{
    protected $signature = 'audit:backfill-roles';
    protected $description = 'Backfill null role/college_id/department_id in existing audit_logs records';

    public function handle()
    {
        $nullRoles = DB::table('audit_logs')->whereNull('role')->get();
        $count = 0;
        foreach ($nullRoles as $log) {
            if (!$log->user_id) continue;
            $user = DB::table('users')->find($log->user_id);
            if (!$user) continue;
            $updates = ['role' => $user->role];
            if (!$log->department_id && $user->department_id) {
                $updates['department_id'] = $user->department_id;
            }
            if (!$log->college_id) {
                if ($user->role === 'college_manager') {
                    $collegeId = DB::table('colleges')->where('manager_id', $user->id)->value('id');
                    if ($collegeId) $updates['college_id'] = $collegeId;
                } elseif ($user->department_id) {
                    $collegeId = DB::table('departments')->where('id', $user->department_id)->value('college_id');
                    if ($collegeId) $updates['college_id'] = $collegeId;
                }
            }
            DB::table('audit_logs')->where('id', $log->id)->update($updates);
            $count++;
        }
        $this->info("Updated {$count} audit_logs records with missing role data.");
    }
}
