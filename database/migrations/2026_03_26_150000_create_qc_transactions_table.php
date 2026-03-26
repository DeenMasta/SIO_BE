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
        Schema::create('qc_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('qc_reference_number', 50)->unique();
            $table->foreignId('stock_in_id')->constrained('stock_in')->restrictOnDelete();
            $table->date('qc_date');
            $table->foreignId('qc_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('POSTED');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['stock_in_id']);
            $table->index(['qc_date']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qc_transactions');
    }
};
