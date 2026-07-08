<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استعادة كلمة المرور</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { background: #1D4C4F; padding: 32px 24px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 22px; }
        .body { padding: 32px 24px; text-align: center; }
        .body p { color: #555; font-size: 15px; line-height: 1.7; margin: 0 0 20px; }
        .code { display: inline-block; background: #f0f7f7; padding: 16px 40px; border-radius: 12px; font-size: 36px; font-weight: bold; color: #1D4C4F; letter-spacing: 8px; direction: ltr; }
        .footer { padding: 20px 24px; text-align: center; border-top: 1px solid #eee; }
        .footer p { color: #999; font-size: 12px; margin: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>استعادة كلمة المرور</h1>
        </div>
        <div class="body">
            <p>مرحباً {{ $name }}</p>
            <p>لقد طلبت استعادة كلمة المرور لحسابك في نظام إدارة التفاعل الأكاديمي.</p>
            <p>رمز التحقق الخاص بك هو:</p>
            <div class="code">{{ $code }}</div>
            <p>يرجى إدخال هذا الرمز لإعادة تعيين كلمة المرور. هذا الرمز صالح لمدة 10 دقائق فقط.</p>
            <p>إذا لم تطلب استعادة كلمة المرور، يرجى تجاهل هذه الرسالة.</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} نظام إدارة التفاعل الأكاديمي</p>
        </div>
    </div>
</body>
</html>
