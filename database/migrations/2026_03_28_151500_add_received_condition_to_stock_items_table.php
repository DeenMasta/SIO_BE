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
            if (! Schema::hasColumn('stock_items', 'received_condition')) {
                $table->string('received_condition', 50)->nullable()->after('current_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_items', 'received_condition')) {
                $table->dropColumn('received_condition');
            }
        });
    }
};
