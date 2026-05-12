<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->string('match_status', 30)->default('pending')->after('confidence_score');
            $table->text('match_notes')->nullable()->after('review_notes');
            $table->timestamp('matched_at')->nullable()->after('last_downloaded_at');

            $table->index(['match_status']);
            $table->index(['matched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->dropIndex(['match_status']);
            $table->dropIndex(['matched_at']);
            $table->dropColumn(['match_status', 'match_notes', 'matched_at']);
        });
    }
};
