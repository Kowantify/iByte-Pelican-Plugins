<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // A new path avoids cached failed anonymous migrations in existing workers.
        // Native install workers can predate plugin namespace registration.
        // Load a native-Laravel-only schema closure directly from this package.
        (require dirname(__DIR__).'/schema.php')();
    }

    public function down(): void
    {
        // Native plugin uninstall rolls migrations back. Retain history, profiles,
        // encrypted credentials and inventory until a separately reviewed cleanup.
    }
};
