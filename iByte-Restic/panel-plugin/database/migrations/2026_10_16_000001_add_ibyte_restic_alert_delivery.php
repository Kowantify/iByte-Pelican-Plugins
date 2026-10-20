<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ibyte_restic_operations', 'alerts_delivered_at')) {
            Schema::table('ibyte_restic_operations', fn (Blueprint $table) => $table->timestamp('alerts_delivered_at')->nullable());
        }
    }

    public function down(): void
    {
        // Delivery receipts prevent dismissed alerts reappearing. Preserve them.
        throw new RuntimeException('Restic alert delivery history must be preserved.');
    }
};
