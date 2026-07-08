<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('offering_id')->unique();
            $table->integer('lecture_count')->default(0);
            $table->integer('attendance_session_count')->default(0);
            $table->integer('assignment_count')->default(0);
            $table->integer('quiz_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_settings');
    }
};
