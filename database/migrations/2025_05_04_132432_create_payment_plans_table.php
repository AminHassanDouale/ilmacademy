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
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->enum('type', ['monthly', 'quarterly', 'semi-annual', 'annual', 'one-time']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('curriculum_id')->nullable()->constrained()->onDelete('cascade');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('installments')->default(1);
            $table->enum('frequency', ['monthly', 'quarterly', 'semi-annual', 'annual', 'one-time'])->nullable();
            $table->timestamps();

            // Indexes for better performance
            $table->index(['curriculum_id', 'is_active']);
            $table->index(['type', 'is_active']);
            $table->index(['is_active', 'created_at']);
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
