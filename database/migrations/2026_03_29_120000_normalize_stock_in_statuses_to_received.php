<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('stock_in')
            ->whereIn('status', ['DRAFT', 'POSTED', 'CANCELLED'])
            ->update(['status' => 'RECEIVED']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('stock_in')
            ->where('status', 'RECEIVED')
            ->update(['status' => 'POSTED']);
    }
};
