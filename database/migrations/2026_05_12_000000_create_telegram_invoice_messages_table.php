<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_invoice_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('telegram_update_id')->nullable();
            $table->string('telegram_chat_id', 40);
            $table->string('telegram_chat_title', 255)->nullable();
            $table->unsignedBigInteger('telegram_message_id');
            $table->unsignedBigInteger('telegram_user_id')->nullable();
            $table->string('telegram_username', 255)->nullable();
            $table->string('telegram_first_name', 255)->nullable();
            $table->string('telegram_last_name', 255)->nullable();
            $table->text('message_text')->nullable();
            $table->text('caption')->nullable();
            $table->timestamp('message_date')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'telegram_message_id'], 'telegram_invoice_messages_chat_message_unique');
            $table->index(['telegram_update_id']);
            $table->index(['received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_invoice_messages');
    }
};
