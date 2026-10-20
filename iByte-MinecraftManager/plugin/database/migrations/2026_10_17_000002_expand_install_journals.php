<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep this after the new idempotent initial-schema migration.
        Schema::table('imm_operations', function (Blueprint $table): void { $table->longText('plan')->change(); });
        Schema::table('imm_settings', function (Blueprint $table): void { $table->longText('value')->nullable()->change(); });
    }

    public function down(): void {}
};
