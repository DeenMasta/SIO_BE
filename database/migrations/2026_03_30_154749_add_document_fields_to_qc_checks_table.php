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
        if (Schema::hasTable('qc_items')) {
            Schema::table('qc_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('qc_items', 'qc_document_id')) {
                    $table->foreignId('qc_document_id')->nullable()->constrained('quality_checks')->cascadeOnDelete();
                }

                if (! Schema::hasColumn('qc_items', 'checked_conditions')) {
                    $table->json('checked_conditions')->nullable();
                }

                if (! Schema::hasColumn('qc_items', 'checked_accessories')) {
                    $table->json('checked_accessories')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('qc_items')) {
            Schema::table('qc_items', function (Blueprint $table): void {
                if (Schema::hasColumn('qc_items', 'qc_document_id')) {
                    $table->dropForeign(['qc_document_id']);
                }

                $dropColumns = [];

                if (Schema::hasColumn('qc_items', 'qc_document_id')) {
                    $dropColumns[] = 'qc_document_id';
                }

                if (Schema::hasColumn('qc_items', 'checked_conditions')) {
                    $dropColumns[] = 'checked_conditions';
                }

                if (Schema::hasColumn('qc_items', 'checked_accessories')) {
                    $dropColumns[] = 'checked_accessories';
                }

                if ($dropColumns !== []) {
                    $table->dropColumn($dropColumns);
                }
            });
        }
    }
};
