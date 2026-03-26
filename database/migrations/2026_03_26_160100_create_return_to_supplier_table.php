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
        Schema::create('return_to_supplier', function (Blueprint $table): void {
            $table->id();
            $table->string('rts_transaction_number', 50)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('stock_in_id')->nullable()->constrained('stock_in')->nullOnDelete();
            $table->date('return_date');
            $table->string('status', 20)->default('POSTED');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['supplier_id']);
            $table->index(['stock_in_id']);
            $table->index(['status']);
            $table->index(['return_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_to_supplier');
    }
};
