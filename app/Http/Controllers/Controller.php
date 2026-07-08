<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function logAction($userId = null, $action = null, $details = null, $targetId = null, $targetType = null, $extra = [])
    {
        // logAction('إنشاء حساب', 'تم إنشاء...') → userId auto-detected
        if (is_string($userId)) {
            $details = $action;
            $action = $userId;
            $userId = null;
        }
        app(\App\Services\ActivityService::class)->log(
            action: $action,
            description: $details,
            entityType: $targetType,
            entityId: $targetId,
            userId: $userId,
            extra: $extra
        );
    }
}
