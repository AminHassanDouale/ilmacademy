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
        if (!Schema::hasTable('attendances')) {
            Schema::create('attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_id')->constrained()->onDelete('cascade');
                $table->foreignId('child_profile_id')->constrained()->onDelete('cascade');
                $table->enum('status', ['present', 'absent', 'late', 'excused'])->default('absent');
                $table->text('remarks')->nullable();
                $table->timestamps();

                // Ensure no duplicate attendance records
                $table->unique(['session_id', 'child_profile_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
