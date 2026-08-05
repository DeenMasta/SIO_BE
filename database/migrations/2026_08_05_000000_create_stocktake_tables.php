<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table): void {
            $table->id();
            $table->string('stocktake_number', 50)->unique();
            $table->date('stocktake_date');
            $table->string('status', 20)->default('COUNTING');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['stocktake_date']);
            $table->index(['status']);
        });

        Schema::create('stocktake_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('expected_qty');
            $table->unsignedInteger('counted_qty')->nullable();
            $table->integer('variance_qty')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['stocktake_id', 'product_id']);
        });

        Schema::create('stocktake_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stocktake_line_id')->constrained('stocktake_lines')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->string('serial_number', 100);
            $table->boolean('is_counted')->default(false);
            $table->timestamps();

            $table->unique(['stocktake_line_id', 'stock_item_id']);
        });

        Schema::create('missing_item_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_number', 50)->unique();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->restrictOnDelete();
            $table->foreignId('stocktake_line_id')->constrained('stocktake_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->restrictOnDelete();
            $table->unsignedInteger('missing_qty');
            $table->string('status', 20)->default('OPEN');
            $table->string('resolution_type', 30)->nullable();
            $table->foreignId('stock_out_id')->nullable()->constrained('stock_out')->restrictOnDelete();
            $table->text('investigation_notes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['product_id', 'status']);
            $table->index(['stocktake_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missing_item_reports');
        Schema::dropIfExists('stocktake_line_items');
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
    }
};
