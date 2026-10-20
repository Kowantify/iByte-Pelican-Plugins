<?php

declare(strict_types=1);

// Read-only upstream research; all imports and migrations target disposable
// directories and an in-memory database. No installed Panel/plugin files change.
$root = dirname(__DIR__);
putenv('PANEL_PLUGIN_SAFE_MODE=true');
$_ENV['PANEL_PLUGIN_SAFE_MODE'] = $_SERVER['PANEL_PLUGIN_SAFE_MODE'] = 'true';
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Illuminate\Support\Facades\DB::purge('sqlite');
$repository = $app->make('migration.repository');
$repository->createRepository();
if (class_exists('IByte\\MinecraftManager\\Support\\SchemaInstaller')) {
    throw new RuntimeException('Research requires no plugin helper autoload.');
}
$id = 'ibyte-minecraft-manager';
$artifact = $root.'/release/Panel Plugin/'.$id.'-v0.1.0-preview.5.zip';
if (!is_file($artifact)) { $artifact = $root.'/release/Archive/'.$id.'-v0.1.0-preview.5.zip'; }
$oldArtifact = $root.'/release/Archive/'.$id.'-v0.1.0-preview.2.zip';
$oldName = '2026_10_16_000001_create_minecraft_manager_tables.php';
$sandboxRoot = $root.'/.build/import-research-'.bin2hex(random_bytes(5));
$results = [];
$check = function (bool $condition, string $message) use (&$results): void {
    if (!$condition) { throw new RuntimeException($message); }
    $results[] = ['name' => $message, 'status' => 'pass'];
    echo 'PASS ', $message, "\n";
};
$loadService = function (string $tag, string $namespace) use ($root, $app): object {
    $sourcePath = $root.'/.build/PluginService-'.$tag.'.php';
    $source = file_get_contents($sourcePath);
    if (!$source || !str_contains($source, 'class PluginService')) { throw new RuntimeException('Missing upstream source '.$tag); }
    // Namespace isolation only: importer and migration method bodies stay intact.
    $isolated = str_replace('namespace App\\Services\\Helpers;', 'namespace '.$namespace.';', $source);
    $classFile = $root.'/.build/ResearchPluginService-'.$tag.'.php';
    file_put_contents($classFile, $isolated);
    require $classFile;
    $class = $namespace.'\\PluginService';
    return new $class($app);
};
$rc = $loadService('v1.0.0-rc.1', 'ImportResearchRc1');
$beta = $loadService('v1.0.0-beta35', 'ImportResearchBeta35');
$seed = function (string $name) use ($app, $sandboxRoot, $oldArtifact): string {
    $base = $sandboxRoot.'/'.$name;
    mkdir($base.'/plugins', 0700, true);
    $app->setBasePath($base);
    $zip = new ZipArchive;
    if ($zip->open($oldArtifact) !== true || !$zip->extractTo($base.'/plugins')) { throw new RuntimeException('Cannot seed old archive'); }
    $zip->close();
    return $base;
};
$file = fn (string $name) => new Illuminate\Http\UploadedFile($artifact, $name, 'application/zip', null, true);
$migrator = $app->make(Illuminate\Database\Migrations\Migrator::class);
$resolve = new ReflectionMethod($migrator, 'resolvePath');
$base = $seed('rc1');
try { $resolve->invoke($migrator, $base.'/plugins/'.$id.'/database/migrations/'.$oldName)->up(); }
catch (Error $error) { $check(str_contains($error->getMessage(), 'SchemaInstaller'), 'original helper failure reproduced before import in same process'); }
$check($rc->downloadPluginFromFile($file(basename($artifact))) === $id, 'published RC.1 importer resolves the versioned ZIP to the correct plugin id');
$check(!is_file($base.'/plugins/'.$id.'/database/migrations/'.$oldName), 'published RC.1 directory replacement removes the original migration');
$plugin = new App\Models\Plugin;
$plugin->forceFill(['id' => $id]);
$rc->runPluginMigrations($plugin);
$check(Illuminate\Support\Facades\Schema::hasTable('imm_operations'), 'published RC.1 runs preview.5 migrations in the previously failed process without helper autoload');
$base = $seed('beta35-versioned');
$beta->downloadPluginFromFile($file(basename($artifact)));
$check(is_file($base.'/plugins/'.pathinfo($artifact, PATHINFO_FILENAME).'/'.$id.'/plugin.json'), 'published beta35 nests a versioned ZIP under its filename instead of replacing the original plugin');
$check(str_contains(file_get_contents($base.'/plugins/'.$id.'/database/migrations/'.$oldName), 'SchemaInstaller'), 'versioned import on beta35 leaves the original broken migration active');
$base = $seed('beta35-canonical');
$beta->downloadPluginFromFile($file($id.'.zip'));
$manifest = json_decode(file_get_contents($base.'/plugins/'.$id.'/plugin.json'), true, 64, JSON_THROW_ON_ERROR);
$check($manifest['version'] === '0.1.0-preview.5' && is_file($base.'/plugins/'.$id.'/database/migrations/'.$oldName), 'beta35 merge can show preview.5 while retaining the original broken migration');
try { $beta->runPluginMigrations($plugin); throw new RuntimeException('Expected retained migration failure'); }
catch (Error $error) { $check(str_contains($error->getMessage(), 'SchemaInstaller'), 'beta35 retained migration reproduces reported class error despite preview.5 manifest'); }
$check(!class_exists('IByte\\MinecraftManager\\Support\\SchemaInstaller'), 'no manual helper registration or plugin boot masked the investigation');
file_put_contents($root.'/docs/evidence/importer-research.json', json_encode([
    'artifact_sha256' => hash_file('sha256', $artifact),
    'upstream_sources' => array_map(fn ($tag) => ['tag' => $tag, 'url' => 'https://raw.githubusercontent.com/pelican/panel/'.$tag.'/app/Services/Helpers/PluginService.php', 'sha256' => hash_file('sha256', $root.'/.build/PluginService-'.$tag.'.php')], ['v1.0.0-rc.1', 'v1.0.0-beta35']),
    'checks' => $results,
    'scope' => 'Published upstream service bodies with namespace isolation; native Laravel fixture dependencies, disposable directories, in-memory SQLite; no UI or Yarn build in this research script; production importer not identified.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo 'RESULT ', count($results), " importer research checks passed.\n";
