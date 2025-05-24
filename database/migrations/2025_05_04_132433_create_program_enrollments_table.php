<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('program_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_profile_id')->constrained();
            $table->foreignId('curriculum_id')->constrained();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('payment_plan_id')->constrained();
            $table->enum('status', ['active', 'inactive', 'completed', 'withdrawn'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_enrollments');
    }
};