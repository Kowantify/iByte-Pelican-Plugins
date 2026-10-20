<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ibyte_restic_repositories')) {
            Schema::create('ibyte_restic_repositories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('host_id');
                $table->unsignedBigInteger('node_id');
                $table->uuid('server_uuid')->index();
                $table->string('profile', 64);
                $table->string('backend', 8);
                $table->unsignedBigInteger('owner_id')->index();
                $table->string('owner_username')->index();
                $table->string('server_name');
                $table->text('metadata');
                $table->longText('owner_history');
                $table->longText('secret');
                $table->timestamp('captured_at');
                $table->timestamp('server_deleted_at')->nullable();
                $table->timestamps();
                $table->unique(['host_id', 'node_id', 'server_uuid', 'profile'], 'ibyte_restic_repository_identity');
            });
        }
        if (! Schema::hasTable('ibyte_restic_recovery_snapshots')) {
            Schema::create('ibyte_restic_recovery_snapshots', function (Blueprint $table) {
                $table->uuid('backup_uuid')->primary();
                $table->unsignedBigInteger('repository_id')->index();
                $table->string('snapshot_id', 64);
                $table->string('backup_name');
                $table->unsignedBigInteger('owner_id');
                $table->string('owner_username');
                $table->boolean('is_locked')->default(false);
                $table->text('metrics')->nullable();
                $table->timestamp('backup_created_at');
                $table->timestamp('forgotten_at')->nullable();
            });
        }
        if (! Schema::hasTable('ibyte_restic_recovery_audit')) {
            Schema::create('ibyte_restic_recovery_audit', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('repository_id')->nullable()->index();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('event', 48);
                $table->timestamp('created_at');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('ibyte_restic_repositories')->exists()) {
            throw new RuntimeException('Restic recovery inventory must be preserved; destructive uninstall is blocked.');
        }
        Schema::dropIfExists('ibyte_restic_recovery_audit');
        Schema::dropIfExists('ibyte_restic_recovery_snapshots');
        Schema::dropIfExists('ibyte_restic_repositories');
    }
};
