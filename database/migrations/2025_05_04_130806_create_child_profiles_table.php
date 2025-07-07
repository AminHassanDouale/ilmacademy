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

            // Basic Information
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable();

            // Contact Information
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            // Emergency Contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();

            // Parent Relationships (choose one approach)
            $table->foreignId('parent_id')->nullable()->constrained('users')->onDelete('set null'); // User ID of parent
            $table->foreignId('parent_profile_id')->nullable()->constrained('parent_profiles')->onDelete('set null'); // Direct to ParentProfile

            // Child's own user account (optional)
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');

            // Medical Information
            $table->text('medical_conditions')->nullable();
            $table->text('allergies')->nullable();
            $table->text('medical_information')->nullable(); // Legacy field
            $table->text('special_needs')->nullable();
            $table->text('additional_needs')->nullable();

            // Additional Information
            $table->text('notes')->nullable();
            $table->string('photo')->nullable();

            // Timestamps and Soft Deletes
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['parent_id']);
            $table->index(['parent_profile_id']);
            $table->index(['user_id']);
            $table->index(['first_name', 'last_name']);
            $table->index(['email']);
            $table->index(['deleted_at']);
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
