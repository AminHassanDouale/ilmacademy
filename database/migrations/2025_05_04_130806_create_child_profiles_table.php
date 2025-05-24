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
        Schema::create('child_profiles', function (Blueprint $table) {
              $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();

            // Foreign keys
            $table->foreignId('parent_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            // Medical information
            $table->text('medical_conditions')->nullable();
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();

            // Additional fields that might be referenced
            $table->text('medical_information')->nullable(); // Legacy field
            $table->text('special_needs')->nullable();
            $table->text('additional_needs')->nullable();
            $table->string('photo')->nullable();

            $table->timestamps();
            $table->softDeletes(); // This adds the deleted_at column

            // Indexes
            $table->index(['parent_id', 'deleted_at']);
            $table->index(['user_id', 'deleted_at']);
            $table->index(['first_name', 'last_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('child_profiles');
    }
};
