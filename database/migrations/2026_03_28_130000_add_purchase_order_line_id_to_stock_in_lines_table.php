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
        Schema::table('stock_in_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->after('stock_in_id')
                ->constrained('purchase_order_lines')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_in_lines', function (Blueprint $table): void {
            $table->dropForeign(['purchase_order_line_id']);
            $table->dropColumn('purchase_order_line_id');
        });
    }
};
