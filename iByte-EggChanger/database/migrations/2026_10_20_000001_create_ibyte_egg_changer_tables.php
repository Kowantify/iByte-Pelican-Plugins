<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * iByte Egg Changer schema.
 *
 * Additive and idempotent: safe to run over an existing installation, never drops
 * or destroys anything on rollback (rollback only drops these plugin tables after
 * confirming they exist). Package deletion never deletes servers or eggs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ibyte_egg_changer_packages')) {
            Schema::create('ibyte_egg_changer_packages', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->text('description')->nullable();
                // Resource minimums in the panel's native units: allocated MiB,
                // matching servers.memory and servers.disk columns. 0 = no minimum.
                $table->unsignedInteger('min_memory_mb')->default(0);
                $table->unsignedInteger('min_disk_mb')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('enabled')->default(false);
                $table->timestamps();
                $table->index(['enabled', 'sort_order'], 'ibyte_egg_changer_packages_enabled_sort_idx');
            });
        }

        if (!Schema::hasTable('ibyte_egg_changer_package_eggs')) {
            Schema::create('ibyte_egg_changer_package_eggs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('package_id');
                $table->unsignedInteger('egg_id');
                $table->timestamps();
                $table->unique(['package_id', 'egg_id'], 'ibyte_egg_changer_package_egg_unique');
                $table->index('egg_id', 'ibyte_egg_changer_package_eggs_egg_idx');
                // Policy mapping only: deleting a package must never delete an egg.
                $table->foreign('package_id', 'ibyte_egg_changer_package_fk')
                    ->references('id')->on('ibyte_egg_changer_packages')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('ibyte_egg_changer_operations')) {
            Schema::create('ibyte_egg_changer_operations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedInteger('server_id');
                $table->string('server_uuid', 36);
                $table->unsignedInteger('actor_id');
                $table->unsignedInteger('source_egg_id');
                $table->unsignedInteger('target_egg_id');
                $table->unsignedBigInteger('package_id')->nullable();
                // Eligibility snapshot at queue time (allocations + package mins).
                $table->unsignedInteger('snapshot_memory_mb');
                $table->unsignedInteger('snapshot_disk_mb');
                $table->unsignedInteger('snapshot_min_memory_mb');
                $table->unsignedInteger('snapshot_min_disk_mb');
                // queued | backup | cleaning | configuring | reinstalling |
                // completed | failed | needs_reconciliation
                $table->string('phase', 32)->default('queued');
                $table->text('failure_stage')->nullable();
                $table->text('failure_message')->nullable();
                // Recovery point created before cleanup (native Backup uuid).
                $table->uuid('backup_uuid')->nullable();
                $table->boolean('backup_successful')->default(false);
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index('server_uuid', 'ibyte_egg_changer_operations_server_idx');
                $table->index('phase', 'ibyte_egg_changer_operations_phase_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: keep history tables even on uninstall
        // rollback so operations records survive per AGENTS.md §9.
        Schema::dropIfExists('ibyte_egg_changer_operations');
        Schema::dropIfExists('ibyte_egg_changer_package_eggs');
        Schema::dropIfExists('ibyte_egg_changer_packages');
    }
};