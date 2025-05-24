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
        Schema::table('payment_plans', function (Blueprint $table) {
            // Drop the existing enum column and recreate with all values
            $table->dropColumn('type');
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            // Add the new enum column with all required values
            $table->enum('type', ['monthly', 'quarterly', 'semi-annual', 'annual', 'one-time'])->after('name');
        });

        // Also update frequency column if it has the same issue
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn('frequency');
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->enum('frequency', ['monthly', 'quarterly', 'semi-annual', 'annual', 'one-time'])->nullable()->after('installments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn(['type', 'frequency']);
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->enum('type', ['monthly', 'quarterly', 'annual', 'one-time'])->after('name');
            $table->enum('frequency', ['monthly', 'quarterly', 'annual', 'one-time'])->nullable()->after('installments');
        });
    }
};
