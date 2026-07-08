<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_offering', function (Blueprint $table) {
            $table->id();
            $table->integer('assignment_id');
            $table->integer('offering_id');
            $table->unique(['assignment_id', 'offering_id']);
            $table->index('offering_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_offering');
    }
};
