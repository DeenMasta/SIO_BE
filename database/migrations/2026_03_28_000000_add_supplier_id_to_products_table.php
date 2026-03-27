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
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('product_type')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->index(['supplier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropIndex(['supplier_id']);
            $table->dropColumn('supplier_id');
        });
    }
};
