<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_inbox_items', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 20)->default('telegram');
            $table->foreignId('telegram_invoice_message_id')->constrained('telegram_invoice_messages')->cascadeOnDelete();
            $table->string('file_disk', 50)->default('local');
            $table->string('file_path', 500)->nullable();
            $table->string('original_file_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('telegram_file_id', 255)->nullable();
            $table->string('telegram_file_unique_id', 255)->nullable();
            $table->string('download_status', 30)->default('queued');
            $table->string('parse_status', 30)->default('pending');
            $table->string('readability_status', 30)->default('unknown');
            $table->longText('ocr_text')->nullable();
            $table->json('extracted_json')->nullable();
            $table->string('invoice_number', 80)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->date('invoice_date')->nullable();
            $table->foreignId('matched_sale_order_id')->nullable()->constrained('sale_orders')->nullOnDelete();
            $table->foreignId('matched_stock_out_id')->nullable()->constrained('stock_out')->nullOnDelete();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->string('duplicate_reason', 120)->nullable();
            $table->foreignId('duplicate_of_inbox_item_id')->nullable()->constrained('invoice_inbox_items')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_invoice_message_id']);
            $table->index(['source']);
            $table->index(['download_status']);
            $table->index(['parse_status']);
            $table->index(['invoice_number']);
            $table->index(['customer_name']);
            $table->index(['telegram_file_unique_id']);
            $table->index(['file_hash']);
            $table->index(['is_duplicate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_inbox_items');
    }
};
