<?php

use App\Models\BackupHost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ibyte_restic_operations')) {
            return;
        }
        Schema::create('ibyte_restic_operations', function (Blueprint $table) {
            $table->uuid('request_uuid')->primary();
            $table->uuid('backup_uuid')->index();
            $table->uuid('server_uuid')->index();
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('host_id');
            $table->string('operation', 16);
            $table->string('status', 16);
            $table->text('payload');
            $table->string('snapshot_id', 64)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (BackupHost::where('schema', 'restic')->exists() || DB::table('ibyte_restic_operations')->exists()) {
            throw new RuntimeException('Restic uninstall blocked: preserve hosts and operation history. See ROLLBACK.md.');
        }
        Schema::dropIfExists('ibyte_restic_operations');
    }
};
