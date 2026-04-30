<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if column already exists to avoid duplicate column error
        $table = DB::getSchemaBuilder()->getColumnListing('return_to_supplier_lines');

        if (!in_array('stock_in_line_id', $table)) {
            Schema::table('return_to_supplier_lines', function (Blueprint $table): void {
                $table->foreignId('stock_in_line_id')
                    ->nullable()
                    ->after('stock_item_id')
                    ->constrained('stock_in_lines')
                    ->nullOnDelete();

                $table->index(['stock_in_line_id']);
            });
        }

        // SQLite-compatible UPDATE statement
        // Updates stock_in_line_id from the related stock_items table
        DB::statement('
            UPDATE return_to_supplier_lines
            SET stock_in_line_id = (
                SELECT si.stock_in_line_id
                FROM stock_items si
                WHERE si.id = return_to_supplier_lines.stock_item_id
            )
            WHERE stock_item_id IS NOT NULL
              AND stock_in_line_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('return_to_supplier_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_in_line_id');
        });
    }
};
