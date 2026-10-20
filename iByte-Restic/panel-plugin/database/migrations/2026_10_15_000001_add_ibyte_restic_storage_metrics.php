<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ibyte_restic_operations', 'metrics')) {
            Schema::table('ibyte_restic_operations', fn (Blueprint $table) => $table->text('metrics')->nullable());
        }
    }

    public function down(): void
    {
        // Preserve historical accounting during rollback; no repository changes.
    }
};
