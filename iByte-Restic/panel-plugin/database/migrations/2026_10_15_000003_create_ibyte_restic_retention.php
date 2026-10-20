<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ibyte_restic_retention')) {
            Schema::create('ibyte_restic_retention', function (Blueprint $table) {
                $table->uuid('server_uuid')->primary();
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('daily')->default(0);
                $table->unsignedInteger('weekly')->default(0);
                $table->string('timezone', 64)->default('UTC');
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('ibyte_restic_retention')->exists()) {
            throw new RuntimeException('Cannot discard saved Restic retention policies during uninstall.');
        }
        Schema::dropIfExists('ibyte_restic_retention');
    }
};
