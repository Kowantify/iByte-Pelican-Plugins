<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ibyte_restic_key_history')) {
            Schema::create('ibyte_restic_key_history', function (Blueprint $table) {
                $table->id();
                // No cascading FK: deleting a server/host must preserve its keys.
                $table->unsignedBigInteger('repository_id')->index();
                $table->string('fingerprint', 64);
                $table->string('kind', 24);
                $table->longText('secret');
                $table->timestamp('captured_at');
                $table->unique(['repository_id', 'fingerprint'], 'ibyte_restic_key_version');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('ibyte_restic_key_history')->exists()) {
            throw new RuntimeException('Recovery key history must be preserved; destructive uninstall is blocked.');
        }
        Schema::dropIfExists('ibyte_restic_key_history');
    }
};
