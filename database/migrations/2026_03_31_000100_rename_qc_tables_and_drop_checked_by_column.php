<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('qc_documents') && ! Schema::hasTable('quality_checks')) {
            Schema::rename('qc_documents', 'quality_checks');
        }

        if (Schema::hasTable('qc_checks') && ! Schema::hasTable('qc_items')) {
            Schema::rename('qc_checks', 'qc_items');
        }

        if (Schema::hasTable('qc_items') && Schema::hasColumn('qc_items', 'checked_by')) {
            $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->select('CONSTRAINT_NAME')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'qc_items')
                ->where('COLUMN_NAME', 'checked_by')
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->pluck('CONSTRAINT_NAME');

            foreach ($foreignKeys as $foreignKey) {
                $escapedForeignKey = str_replace('`', '``', (string) $foreignKey);
                DB::statement("ALTER TABLE `qc_items` DROP FOREIGN KEY `{$escapedForeignKey}`");
            }

            Schema::table('qc_items', function (Blueprint $table): void {
                $table->dropColumn('checked_by');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('qc_items') && ! Schema::hasColumn('qc_items', 'checked_by')) {
            Schema::table('qc_items', function (Blueprint $table): void {
                $table->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('qc_items') && ! Schema::hasTable('qc_checks')) {
            Schema::rename('qc_items', 'qc_checks');
        }

        if (Schema::hasTable('quality_checks') && ! Schema::hasTable('qc_documents')) {
            Schema::rename('quality_checks', 'qc_documents');
        }
    }
};
