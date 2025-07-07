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
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();

            // Relationship to User
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Contact Information
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            // Emergency Contact (for the parent/client themselves)
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();

            // Relationship Information
            $table->string('relationship_to_children')->default('Parent'); // Parent, Guardian, Relative, etc.

            // Professional Information
            $table->string('occupation')->nullable();
            $table->string('company')->nullable();

            // Communication Preferences
            $table->enum('preferred_contact_method', ['email', 'phone', 'sms', 'whatsapp'])->default('email');
            $table->boolean('allow_marketing_emails')->default(false);
            $table->boolean('allow_sms_notifications')->default(true);

            // Additional Information
            $table->text('notes')->nullable();

            // Profile Picture
            $table->string('profile_picture')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->unique(['user_id']);
            $table->index(['phone']);
            $table->index(['preferred_contact_method']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_profiles');
    }
};
