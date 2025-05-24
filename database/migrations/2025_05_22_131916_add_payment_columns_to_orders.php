<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        // Check current enum values
        $result = DB::select("SHOW COLUMNS FROM payment_plans WHERE Field = 'type'");
        echo "Current enum values: " . $result[0]->Type . "\n";

        // Update the enum to include 'trimester'
        DB::statement("ALTER TABLE payment_plans MODIFY COLUMN type ENUM('monthly', 'trimester', 'annual') NOT NULL DEFAULT 'monthly'");
    }

    public function down(): void
    {
        // Revert if needed
        DB::statement("ALTER TABLE payment_plans MODIFY COLUMN type ENUM('monthly', 'quarterly', 'annual') NOT NULL DEFAULT 'monthly'");
    }
};