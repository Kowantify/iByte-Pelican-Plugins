<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$container = $capsule->getContainer();
$container->instance('db', $capsule->getDatabaseManager()); $container->instance('db.schema', $capsule->schema());
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$work = $root.'/.build/migration-cache-regression';
$directory = $work.'/database/migrations';
if (!is_dir($directory)) { mkdir($directory, 0700, true); }
$oldName = '2026_10_16_000001_create_minecraft_manager_tables.php';
$oldPath = $directory.'/'.$oldName;
$archive = new ZipArchive;
if ($archive->open($root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.2.zip') !== true) { throw new RuntimeException('Original preview.2 archive required'); }
$old = $archive->getFromName('ibyte-minecraft-manager/database/migrations/'.$oldName); $archive->close();
file_put_contents($oldPath, $old);
copy($root.'/plugin/database/schema.php', $work.'/database/schema.php');
$reflection = new ReflectionClass(Illuminate\Database\Migrations\Migrator::class);
$migrator = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('files')->setValue($migrator, new Illuminate\Filesystem\Filesystem);
$resolve = $reflection->getMethod('resolvePath');
$checkFailure = function (string $label) use ($resolve, $migrator, $oldPath): void {
    try { $resolve->invoke($migrator, $oldPath)->up(); }
    catch (Error $error) {
        if (!str_contains($error->getMessage(), 'SchemaInstaller')) { throw $error; }
        echo 'PASS ', $label, "\n"; return;
    }
    throw new RuntimeException('Expected original cached class failure');
};
$checkFailure('original preview.2 migration fails with helper unavailable');
$archive = new ZipArchive;
if ($archive->open($root.'/release/Panel Plugin/ibyte-minecraft-manager-v0.1.0-preview.3.zip') !== true
    && $archive->open($root.'/release/Archive/ibyte-minecraft-manager-v0.1.0-preview.3.zip') !== true) { throw new RuntimeException('Original preview.3 archive required'); }
file_put_contents($oldPath, $archive->getFromName('ibyte-minecraft-manager/database/migrations/'.$oldName)); $archive->close();
$checkFailure('preview.3 same-path replacement still executes Laravel cached preview.2 object');
if ($argc > 1 && $argv[1] === '--baseline-only') { exit(0); }
// Native ZIP import replaces the directory: the old migration path disappears.
unlink($oldPath);
foreach (glob($root.'/plugin/database/migrations/*.php') as $file) { copy($file, $directory.'/'.basename($file)); }
$files = $migrator->getMigrationFiles($directory);
if (array_key_exists(pathinfo($oldName, PATHINFO_FILENAME), $files)) { throw new RuntimeException('Fixed archive still supplies the cached migration path'); }
$migrator->requireFiles($files);
foreach ($files as $file) { $resolve->invoke($migrator, $file)->up(); }
foreach (['imm_settings', 'imm_profiles', 'imm_operations', 'imm_inventory'] as $table) {
    if (!$capsule->schema()->hasTable($table)) { throw new RuntimeException('Missing '.$table); }
}
if (class_exists('IByte\\MinecraftManager\\Support\\SchemaInstaller')) { throw new RuntimeException('Helper unexpectedly autoloaded'); }
echo "PASS new migration paths install all tables in the same native Migrator process without worker restart\n";
echo "RESULT 3 cached-migration regression checks passed.\n";
