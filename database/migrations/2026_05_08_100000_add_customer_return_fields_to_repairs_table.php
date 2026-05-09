<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table): void {
            $table->string('repair_flow', 20)->default('INTERNAL')->after('customer_id');
            $table->date('returned_to_customer_date')->nullable()->after('repair_status');
            $table->string('return_tracking_number', 100)->nullable()->after('returned_to_customer_date');
            $table->index(['repair_flow']);
            $table->index(['returned_to_customer_date']);
        });

        DB::table('repairs')
            ->whereNotNull('customer_id')
            ->update(['repair_flow' => 'CUSTOMER']);

        DB::table('repairs')
            ->where('repair_flow', 'CUSTOMER')
            ->where('repair_status', 'COMPLETED')
            ->update([
                'repair_status' => 'RETURNED_TO_CUSTOMER',
                'returned_to_customer_date' => DB::raw('COALESCE(returned_to_customer_date, DATE(updated_at))'),
            ]);
    }

    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table): void {
            $table->dropIndex(['repair_flow']);
            $table->dropIndex(['returned_to_customer_date']);
            $table->dropColumn([
                'repair_flow',
                'returned_to_customer_date',
                'return_tracking_number',
            ]);
        });
    }
};
