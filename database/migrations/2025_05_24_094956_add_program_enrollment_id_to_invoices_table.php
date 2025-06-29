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
        Schema::table('child_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('child_profiles', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('child_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('child_profiles', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
