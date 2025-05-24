<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Add program_enrollment_id if it doesn't exist
            if (!Schema::hasColumn('invoices', 'program_enrollment_id')) {
                $table->foreignId('program_enrollment_id')
                      ->nullable()
                      ->after('child_profile_id')
                      ->constrained('program_enrollments')
                      ->onDelete('cascade');
            }
        });
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['program_enrollment_id']);
            $table->dropColumn('program_enrollment_id');
        });
    }
};
