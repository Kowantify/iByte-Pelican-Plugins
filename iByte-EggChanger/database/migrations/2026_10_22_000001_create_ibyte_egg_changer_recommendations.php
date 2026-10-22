<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional purchasing guidance; existing package gates and switch history are untouched. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ibyte_egg_changer_recommendations')) {
            return;
        }

        Schema::create('ibyte_egg_changer_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('egg_id');
            $table->string('name', 90);
            $table->string('description', 400)->nullable();
            $table->unsignedInteger('recommended_memory_mb');
            $table->unsignedInteger('recommended_disk_mb');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['egg_id', 'sort_order'], 'ibyte_egg_recommendations_egg_order_idx');
            // Deliberately no FK to upstream Pelican egg table; plugin migrations
            // must not change Pelican core tables, and old eggs may be removed.
        });
    }

    public function down(): void
    {
        // Do not erase manually maintained recommendations during a plugin rollback.
    }
};
