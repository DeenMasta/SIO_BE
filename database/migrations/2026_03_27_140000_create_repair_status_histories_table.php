<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('repair_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->foreign('repair_id')->references('id')->on('repairs')->cascadeOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['repair_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_status_histories');
    }
};
