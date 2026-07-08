<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCollegeIdAndTimestampsToOfficialStudentsTable extends Migration
{
    public function up()
    {
        Schema::table('official_students', function (Blueprint $table) {
            $table->unsignedBigInteger('college_id')->nullable()->after('id');
            $table->renameColumn('name', 'student_name');
            $table->timestamps();
            $table->index('college_id');
            $table->unique(['college_id', 'academic_number']);
        });
    }

    public function down()
    {
        Schema::table('official_students', function (Blueprint $table) {
            $table->dropUnique(['college_id', 'academic_number']);
            $table->dropIndex(['college_id']);
            $table->dropColumn(['college_id', 'created_at', 'updated_at']);
            $table->renameColumn('student_name', 'name');
        });
    }
}