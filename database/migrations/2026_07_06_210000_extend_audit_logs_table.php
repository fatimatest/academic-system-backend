<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('role', 50)->nullable()->after('user_id');
            $table->unsignedBigInteger('college_id')->nullable()->after('role');
            $table->unsignedBigInteger('department_id')->nullable()->after('college_id');
            $table->unsignedBigInteger('target_id')->nullable()->after('details');
            $table->string('target_type', 100)->nullable()->after('target_id');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->string('browser', 255)->nullable()->after('user_agent');
            $table->string('os', 255)->nullable()->after('browser');
            $table->string('device', 255)->nullable()->after('os');
        });

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Throwable $e) {}

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['role', 'college_id', 'department_id', 'target_id', 'target_type', 'user_agent', 'browser', 'os', 'device']);
        });
    }
};
