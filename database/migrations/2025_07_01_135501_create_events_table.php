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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('general'); // general, academic, exam, holiday, meeting, event, deadline
            $table->datetime('start_date');
            $table->datetime('end_date');
            $table->boolean('is_all_day')->default(false);
            $table->string('location')->nullable();
            $table->string('color')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('academic_year_id')->nullable();

            // Recurring events
            $table->boolean('recurring')->default(false);
            $table->json('recurring_pattern')->nullable(); // Store pattern data as JSON

            // Registration/attendance
            $table->json('attendees')->nullable(); // Store attendee data as JSON
            $table->integer('max_attendees')->nullable();
            $table->boolean('registration_required')->default(false);
            $table->datetime('registration_deadline')->nullable();

            // Status and notes
            $table->string('status')->default('active'); // active, cancelled, postponed
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraints
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');

            // Only add academic_year_id foreign key if the table exists
            if (Schema::hasTable('academic_years')) {
                $table->foreign('academic_year_id')->references('id')->on('academic_years')->onDelete('set null');
            }

            // Indexes for better performance
            $table->index(['start_date', 'end_date']);
            $table->index('type');
            $table->index('created_by');
            $table->index('academic_year_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
