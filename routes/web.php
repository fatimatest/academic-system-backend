<?php

use Illuminate\Support\Facades\Route;

/* الصفحة الرئيسية */
Route::get('/', function () {
    return "Laravel API يعمل";
});