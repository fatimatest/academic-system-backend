<?php
if (!function_exists('activity')) {
    function activity($userId = null, $action = null, $details = null, $targetId = null, $targetType = null, $extra = [])
    {
        // activity('إنشاء حساب', 'تم إنشاء...') → userId auto-detected
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
