<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('package_code', 50)->unique();
            $table->string('package_name', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['package_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
