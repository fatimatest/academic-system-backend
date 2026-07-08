<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->integer('college_id')->nullable()->after('id');
            $table->integer('sender_id')->nullable()->after('college_id');
            $table->string('target_type')->nullable()->after('target_all');
            $table->string('attachment')->nullable()->after('target_type');

            $table->foreign('college_id')->references('id')->on('colleges')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
        });

        DB::statement('ALTER TABLE announcements MODIFY COLUMN offering_id INT NULL');
        DB::statement('ALTER TABLE announcements MODIFY COLUMN doctor_id INT NULL');
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign(['college_id']);
            $table->dropForeign(['sender_id']);
            $table->dropColumn(['college_id', 'sender_id', 'target_type', 'attachment']);
        });

        DB::statement('ALTER TABLE announcements MODIFY COLUMN offering_id INT NOT NULL');
        DB::statement('ALTER TABLE announcements MODIFY COLUMN doctor_id INT NOT NULL');
    }
};
