<?php

namespace App\Constants;

class ActionTypes
{
    // إدارة الحسابات
    const CREATE_ACCOUNT = 'إنشاء حساب';
    const UPDATE_ACCOUNT = 'تعديل حساب';
    const DELETE_ACCOUNT = 'حذف حساب';
    const ACTIVATE_ACCOUNT = 'تفعيل حساب';
    const DEACTIVATE_ACCOUNT = 'تعطيل حساب';
    const RESET_PASSWORD = 'إعادة تعيين كلمة المرور';

    // الكليات والأقسام
    const CREATE_COLLEGE = 'إنشاء كلية';
    const UPDATE_COLLEGE = 'تعديل كلية';
    const DELETE_COLLEGE = 'حذف كلية';
    const CREATE_DEPARTMENT = 'إنشاء قسم';
    const UPDATE_DEPARTMENT = 'تعديل قسم';
    const DELETE_DEPARTMENT = 'حذف قسم';

    // المواد
    const CREATE_SUBJECT = 'إنشاء مادة';
    const UPDATE_SUBJECT = 'تعديل مادة';
    const DELETE_SUBJECT = 'حذف مادة';
    const LINK_SUBJECT = 'ربط مادة';
    const UNLINK_SUBJECT = 'فك ارتباط مادة';

    // الطلبات
    const APPROVE_JOIN_REQUEST = 'قبول طلب ارتباط';
    const REJECT_JOIN_REQUEST = 'رفض طلب ارتباط';
    const CANCEL_ENROLLMENT = 'إلغاء ارتباط طالب';

    // الإعلانات والإشعارات
    const SEND_ANNOUNCEMENT = 'إرسال إعلان';
    const DELETE_ANNOUNCEMENT = 'حذف إعلان';
    const SEND_NOTIFICATION = 'إرسال إشعار عام';

    // النظام
    const UPDATE_COURSE_SETTINGS = 'تعديل إعدادات المادة';
    const UPDATE_SYSTEM_SETTINGS = 'تعديل إعدادات النظام';
    const ACTIVATE_TERM = 'تفعيل ترم';
    const DEACTIVATE_TERM = 'إلغاء تفعيل ترم';

    // تسجيل الدخول
    const LOGIN = 'تسجيل دخول';
    const LOGOUT = 'تسجيل خروج';

    public static function all(): array
    {
        return [
            self::CREATE_ACCOUNT,
            self::UPDATE_ACCOUNT,
            self::DELETE_ACCOUNT,
            self::ACTIVATE_ACCOUNT,
            self::DEACTIVATE_ACCOUNT,
            self::RESET_PASSWORD,
            self::CREATE_COLLEGE,
            self::UPDATE_COLLEGE,
            self::DELETE_COLLEGE,
            self::CREATE_DEPARTMENT,
            self::UPDATE_DEPARTMENT,
            self::DELETE_DEPARTMENT,
            self::CREATE_SUBJECT,
            self::UPDATE_SUBJECT,
            self::DELETE_SUBJECT,
            self::LINK_SUBJECT,
            self::UNLINK_SUBJECT,
            self::APPROVE_JOIN_REQUEST,
            self::REJECT_JOIN_REQUEST,
            self::CANCEL_ENROLLMENT,
            self::SEND_ANNOUNCEMENT,
            self::DELETE_ANNOUNCEMENT,
            self::SEND_NOTIFICATION,
            self::UPDATE_COURSE_SETTINGS,
            self::UPDATE_SYSTEM_SETTINGS,
            self::ACTIVATE_TERM,
            self::DEACTIVATE_TERM,
            self::LOGIN,
            self::LOGOUT,
        ];
    }
}
