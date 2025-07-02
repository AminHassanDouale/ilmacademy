<?php

// ===================================================================
// 1. MIGRATION: create_payment_plans_table.php
// ===================================================================

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
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();

            // Basic Information
            $table->string('name');
            $table->string('code', 20)->unique()->nullable();
            $table->text('description')->nullable();

            // Payment Structure
            $table->enum('type', ['monthly', 'quarterly', 'semi-annual', 'annual', 'one-time'])
                  ->default('monthly');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');

            // Payment Schedule
            $table->integer('installments')->default(1);
            $table->enum('frequency', ['monthly', 'quarterly', 'semi-annual', 'annual', 'one-time'])
                  ->nullable();
            $table->integer('due_day')->nullable()->comment('Day of month when payment is due (1-31)');

            // Relationships
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->onDelete('cascade');

            // Status and Settings
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('auto_generate_invoices')->default(true);

            // Pricing and Discounts
            $table->decimal('setup_fee', 10, 2)->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->date('discount_valid_until')->nullable();

            // Advanced Settings
            $table->integer('grace_period_days')->default(7);
            $table->decimal('late_fee_amount', 10, 2)->nullable();
            $table->decimal('late_fee_percentage', 5, 2)->nullable();

            // Terms and Conditions
            $table->text('terms_and_conditions')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->json('accepted_payment_methods')->nullable();

            // Metadata
            $table->json('metadata')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['type']);
            $table->index(['is_active']);
            $table->index(['curriculum_id']);
            $table->index(['amount']);
            $table->index(['currency']);
            $table->index(['is_default']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
