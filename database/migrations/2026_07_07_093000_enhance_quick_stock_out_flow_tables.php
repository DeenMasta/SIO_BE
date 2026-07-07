<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_stock_outs', function (Blueprint $table): void {
            $table->foreignId('stock_out_id')->nullable()->after('customer_id')->constrained('stock_out')->nullOnDelete();
            $table->foreignId('converted_sale_order_id')->nullable()->after('stock_out_id')->constrained('sale_orders')->nullOnDelete();
        });

        Schema::table('quick_stock_out_lines', function (Blueprint $table): void {
            $table->text('remarks')->nullable()->after('quantity');
        });

        Schema::create('quick_stock_out_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quick_stock_out_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->string('serial_number_snapshot', 80);
            $table->foreignId('stock_out_line_item_id')->nullable()->constrained('stock_out_line_items')->nullOnDelete();
            $table->timestamps();

            $table->unique('stock_item_id');
        });

        Schema::table('stock_out', function (Blueprint $table): void {
            $table->foreignId('quick_stock_out_id')->nullable()->after('sale_order_id')->constrained('quick_stock_outs')->nullOnDelete();
        });

        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->foreignId('quick_stock_out_line_id')->nullable()->after('sale_order_line_id')->constrained('quick_stock_out_lines')->nullOnDelete();
        });

        Schema::table('stock_out_line_items', function (Blueprint $table): void {
            $table->foreignId('quick_stock_out_line_item_id')->nullable()->after('stock_item_id')->constrained('quick_stock_out_line_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_out_line_items', function (Blueprint $table): void {
            $table->dropForeign(['quick_stock_out_line_item_id']);
            $table->dropColumn('quick_stock_out_line_item_id');
        });

        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->dropForeign(['quick_stock_out_line_id']);
            $table->dropColumn('quick_stock_out_line_id');
        });

        Schema::table('stock_out', function (Blueprint $table): void {
            $table->dropForeign(['quick_stock_out_id']);
            $table->dropColumn('quick_stock_out_id');
        });

        Schema::dropIfExists('quick_stock_out_line_items');

        Schema::table('quick_stock_out_lines', function (Blueprint $table): void {
            $table->dropColumn('remarks');
        });

        Schema::table('quick_stock_outs', function (Blueprint $table): void {
            $table->dropForeign(['stock_out_id']);
            $table->dropForeign(['converted_sale_order_id']);
            $table->dropColumn(['stock_out_id', 'converted_sale_order_id']);
        });
    }
};
