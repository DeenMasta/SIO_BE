<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->boolean('is_extra')->default(false)->after('sale_order_line_id');
            $table->unsignedInteger('settled_qty')->default(0)->after('qty');
        });

        Schema::table('sale_order_lines', function (Blueprint $table): void {
            $table->foreignId('source_stock_out_line_id')
                ->nullable()
                ->after('product_id')
                ->constrained('stock_out_lines')
                ->nullOnDelete();
        });

        Schema::table('stock_out_line_items', function (Blueprint $table): void {
            $table->foreignId('settled_sale_order_line_id')
                ->nullable()
                ->after('stock_item_id')
                ->constrained('sale_order_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_out_line_items', function (Blueprint $table): void {
            $table->dropForeign(['settled_sale_order_line_id']);
            $table->dropColumn('settled_sale_order_line_id');
        });

        Schema::table('sale_order_lines', function (Blueprint $table): void {
            $table->dropForeign(['source_stock_out_line_id']);
            $table->dropColumn('source_stock_out_line_id');
        });

        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->dropColumn(['is_extra', 'settled_qty']);
        });
    }
};
