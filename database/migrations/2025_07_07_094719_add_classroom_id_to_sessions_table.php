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
        Schema::table('sessions', function (Blueprint $table) {
            // Add the classroom_id column if it doesn't exist
            if (!Schema::hasColumn('sessions', 'classroom_id')) {
                $table->unsignedBigInteger('classroom_id')->nullable()->after('teacher_profile_id');
            }

            // Add foreign key constraint to rooms table
            $table->foreign('classroom_id')->references('id')->on('rooms')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            // Drop foreign key constraint
            $table->dropForeign(['classroom_id']);

            // Optionally drop the column
            $table->dropColumn('classroom_id');
        });
    }
};
