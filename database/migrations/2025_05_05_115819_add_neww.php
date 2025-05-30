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
        Schema::table('invoices', function (Blueprint $table) {
            // Add invoice_number column if it doesn't exist
            if (!Schema::hasColumn('invoices', 'invoice_number')) {
                $table->string('invoice_number')->unique()->after('id');
            }

            // Add paid_date column if it doesn't exist
            if (!Schema::hasColumn('invoices', 'paid_date')) {
                $table->date('paid_date')->nullable()->after('due_date');
            }

            // Add notes column if it doesn't exist
            if (!Schema::hasColumn('invoices', 'notes')) {
                $table->text('notes')->nullable()->after('reference');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'invoice_number')) {
                $table->dropColumn('invoice_number');
            }

            if (Schema::hasColumn('invoices', 'paid_date')) {
                $table->dropColumn('paid_date');
            }

            if (Schema::hasColumn('invoices', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
