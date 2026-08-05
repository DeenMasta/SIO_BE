<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_exchanges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_id')->unique()->constrained('customer_returns')->restrictOnDelete();
            $table->foreignId('sale_order_id')->constrained('sale_orders')->restrictOnDelete();
            $table->foreignId('replacement_stock_out_id')->nullable()->constrained('stock_out')->nullOnDelete();
            $table->string('status', 30)->default('PENDING');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['sale_order_id', 'status']);
        });

        Schema::create('customer_exchange_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_exchange_id')->constrained('customer_exchanges')->cascadeOnDelete();
            $table->foreignId('customer_return_line_id')->unique()->constrained('customer_return_lines')->restrictOnDelete();
            $table->foreignId('replacement_product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->foreignId('sale_order_line_id')->nullable()->unique()->constrained('sale_order_lines')->nullOnDelete();
            $table->foreignId('replacement_stock_out_line_id')->nullable()->unique()->constrained('stock_out_lines')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::table('sale_order_lines', function (Blueprint $table): void {
            $table->string('line_type', 30)->default('SALE')->after('sale_order_id');
            $table->index(['sale_order_id', 'line_type']);
        });

        Schema::table('stock_out', function (Blueprint $table): void {
            $table->foreignId('customer_exchange_id')->nullable()->after('sale_order_id')->constrained('customer_exchanges')->nullOnDelete();
            $table->index(['customer_exchange_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_out', function (Blueprint $table): void {
            $table->dropForeign(['customer_exchange_id']);
            $table->dropIndex(['customer_exchange_id']);
            $table->dropColumn('customer_exchange_id');
        });

        Schema::table('sale_order_lines', function (Blueprint $table): void {
            $table->dropIndex(['sale_order_id', 'line_type']);
            $table->dropColumn('line_type');
        });

        Schema::dropIfExists('customer_exchange_lines');
        Schema::dropIfExists('customer_exchanges');
    }
};
