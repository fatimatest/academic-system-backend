<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->boolean('target_all')->default(false)->after('file_size');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('target_all')->default(false)->after('category');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('target_all')->default(false)->after('target_department');
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropColumn('target_all');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('target_all');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('target_all');
        });
    }
};
