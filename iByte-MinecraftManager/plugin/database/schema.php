<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shared by standalone migrations and admin repair; no plugin class autoload required.
return static function (): void {
    if (!Schema::hasTable('imm_settings')) {
        Schema::create('imm_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->longText('value')->nullable();
            $table->text('secret')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('imm_profiles')) {
        Schema::create('imm_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('egg_id')->unique();
            $table->string('software', 128);
            $table->string('loader', 100)->nullable();
            $table->string('content_type', 20);
            $table->string('jar_path', 240)->default('server.jar');
            $table->string('version_variable', 100)->nullable();
            $table->text('images');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('imm_operations')) {
        Schema::create('imm_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('server_id')->index();
            $table->uuid('server_uuid');
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('actor_id')->index();
            $table->string('kind', 20);
            $table->string('provider', 30);
            $table->string('status', 40)->index();
            $table->string('step', 50);
            $table->string('error_code', 80)->nullable();
            $table->string('idempotency', 64)->unique();
            $table->unsignedInteger('server_slot')->nullable()->unique();
            $table->text('selection');
            $table->longText('plan');
            $table->string('digest', 64);
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }
    if (!Schema::hasTable('imm_inventory')) {
        Schema::create('imm_inventory', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id')->index();
            $table->string('path', 240);
            $table->text('metadata');
            $table->timestamps();
            $table->unique(['server_id', 'path']);
        });
    }
};
