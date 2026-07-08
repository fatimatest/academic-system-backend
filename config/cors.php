<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login'], // أضفنا api/* لتشمل كل مسارات الـ API
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'], // هذا يسمح لـ Vite (منفذ 5173) بالاتصال بـ Laravel (منفذ 8000)
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // قم بتغييرها إلى true إذا كنت تستخدم ملفات تعريف الارتباط (Cookies)
];
