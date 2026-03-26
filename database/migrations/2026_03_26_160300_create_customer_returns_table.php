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
        Schema::create('customer_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_transaction_number', 50)->unique();
            $table->date('return_date');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('original_invoice_number', 50);
            $table->foreignId('original_stock_out_id')->constrained('stock_out')->restrictOnDelete();
            $table->string('status', 20)->default('POSTED');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['customer_id']);
            $table->index(['original_stock_out_id']);
            $table->index(['status']);
            $table->index(['return_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_returns');
    }
};
