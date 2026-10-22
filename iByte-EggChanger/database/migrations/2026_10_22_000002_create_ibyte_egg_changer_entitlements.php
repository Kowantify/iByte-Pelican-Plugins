<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('ibyte_egg_changer_entitlements')) {
            Schema::create('ibyte_egg_changer_entitlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('server_id')->unique();
                $table->string('server_uuid', 36);
                $table->boolean('enabled')->default(false);
                $table->string('source', 16)->default('admin');
                $table->string('external_service_id', 80)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }
    // Keep entitlements on rollback. Removing rows could silently revoke paid access.
    public function down(): void {}
};
