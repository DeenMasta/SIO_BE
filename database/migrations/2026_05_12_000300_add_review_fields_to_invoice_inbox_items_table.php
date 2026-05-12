<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->after('matched_stock_out_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('matched_at');

            $table->index(['reviewed_by']);
            $table->index(['reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->dropIndex(['reviewed_by']);
            $table->dropIndex(['reviewed_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
    }
};
