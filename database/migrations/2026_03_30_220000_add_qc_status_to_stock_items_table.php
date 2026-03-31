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
        Schema::table('stock_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_items', 'qc_status')) {
                $table->string('qc_status', 20)->default('PENDING')->after('received_condition');
                $table->index('qc_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_items', 'qc_status')) {
                $table->dropIndex(['qc_status']);
                $table->dropColumn('qc_status');
            }
        });
    }
};
