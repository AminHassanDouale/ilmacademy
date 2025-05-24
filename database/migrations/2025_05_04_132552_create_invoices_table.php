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
        Schema::create('invoices', function (Blueprint $table) {
         $table->id();
            $table->string('invoice_number')->unique();
            $table->decimal('amount', 10, 2);
            $table->datetime('invoice_date');
            $table->datetime('due_date');
            $table->datetime('paid_date')->nullable();
            $table->enum('status', ['draft', 'sent', 'pending', 'partially_paid', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            // Foreign keys
            $table->foreignId('child_profile_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained()->onDelete('cascade');
            $table->foreignId('curriculum_id')->constrained()->onDelete('cascade');
            $table->foreignId('program_enrollment_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['child_profile_id', 'status']);
            $table->index(['academic_year_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index('invoice_number');
        });

        // Create invoice items table for detailed line items
        Schema::create('invoice_items', function (Blueprint $table) {
              $table->id();
                $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('amount', 10, 2);
                $table->integer('quantity')->default(1);
                $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
