<?php

declare(strict_types=1);

// Deliberately no Panel bootstrap, plugin provider, test bootstrap or plugin
// namespace registration: installation can run in a pre-existing worker.
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$container = $capsule->getContainer();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->schema());
Illuminate\Support\Facades\Facade::setFacadeApplication($container);

function migrationCheck(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
    echo 'PASS ', $message, "\n";
}

migrationCheck(!class_exists('IByte\\MinecraftManager\\Support\\SchemaInstaller'), 'plugin helper is not autoloadable before installation');
$directory = $argv[1] ?? $root.'/plugin/database/migrations';
$migrations = [];
foreach (glob($directory.'/*.php') as $file) { $migrations[] = require $file; }
foreach ($migrations as $migration) { $migration->up(); }
$schema = $capsule->schema();
migrationCheck($schema->hasTable('imm_settings') && $schema->hasTable('imm_profiles') && $schema->hasTable('imm_operations') && $schema->hasTable('imm_inventory'), 'fresh migrations create all tables without plugin autoload');
$capsule->table('imm_settings')->insert(['key' => 'regression-marker', 'value' => '{"retained":true}']);
foreach ($migrations as $migration) { $migration->up(); }
migrationCheck($capsule->table('imm_settings')->where('key', 'regression-marker')->exists(), 'repeated migration execution preserves existing data');
foreach (array_reverse($migrations) as $migration) { $migration->down(); }
migrationCheck($capsule->table('imm_settings')->where('key', 'regression-marker')->exists(), 'uninstall migration rollback preserves plugin data');
migrationCheck(!class_exists('IByte\\MinecraftManager\\Support\\SchemaInstaller'), 'migration success never depends on registering the plugin helper');
echo "RESULT 5 isolated migration checks passed; SQLite in memory; no plugin autoload or asset-build mock.\n";
