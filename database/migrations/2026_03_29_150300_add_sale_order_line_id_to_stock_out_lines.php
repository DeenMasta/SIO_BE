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
        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->foreignId('sale_order_line_id')->nullable()->after('stock_out_id')->constrained('sale_order_lines')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->dropForeign(['sale_order_line_id']);
            $table->dropColumn('sale_order_line_id');
        });
    }
};
