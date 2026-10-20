<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
require $root.'/plugin/tests/bootstrap.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Egg;
use App\Models\Server;
use App\Models\User;
use IByte\MinecraftManager\Catalog\Catalog;
use IByte\MinecraftManager\Catalog\JsonTransport;
use IByte\MinecraftManager\Catalog\McJars;
use IByte\MinecraftManager\Models\Operation;
use IByte\MinecraftManager\Models\Profile;
use IByte\MinecraftManager\Operations\CheckReview;
use IByte\MinecraftManager\Operations\InstallPreparer;
use IByte\MinecraftManager\Operations\NodeFiles;
use IByte\MinecraftManager\Operations\ReviewService;
use IByte\MinecraftManager\Support\ArtifactDownloader;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Settings;
use Illuminate\Support\Facades\Http;

final class DownloadFixture extends ArtifactDownloader
{
    public array $files = [];
    public function download(string $url, string $destination, ?int $size, array $hashes = []): array
    {
        $data = $this->files[$url] ?? throw new Failure('artifact_download_failed');
        check(strlen($data) === $size, 'Fixture artifact size mismatch');
        foreach ($hashes as $algo => $hash) { check(hash($algo, $data) === $hash, 'Fixture checksum mismatch'); }
        file_put_contents($destination, $data);
        return ['size' => strlen($data), 'sha256' => hash('sha256', $data)];
    }
}

final class MemoryNode extends NodeFiles
{
    public array $data = [];
    public bool $running = false;
    public bool $corrupt = false;
    public bool $failMove = false;
    public bool $editAfterMove = false;
    public ?string $failStagingTarget = null;
    public array $downloads = [];
    public bool $failExtract = false;
    public ?Closure $onPull = null;
    public ?Closure $onVerify = null;
    public ?Closure $onInitialVerify = null;
    public function assertStopped(): void { if ($this->running) { throw new Failure('server_must_be_stopped'); } }
    public function mkdir(string $path): void {}
    public function assertRegular(string $path): void { check(isset($this->data[$path]), 'Expected regular fixture file'); }
    public function removeEmptyDirectory(string $path): void {}
    public function exists(string $path): bool { return array_key_exists($path, $this->data); }
    public function hashOrNull(string $path): ?string { return isset($this->data[$path]) ? hash('sha256',$this->data[$path]) : null; }
    public function entries(array $paths, ?callable $guard = null, ?callable $found = null): array
    {
        $entries=[];foreach($paths as $path){if(isset($this->data[$path])){$entries[$path]=['name'=>basename($path),'file'=>true,'directory'=>false,'symlink'=>false,'size'=>strlen($this->data[$path])];}}return $entries;
    }
    public function upload(string $path, string $local): void { $this->data[$path] = file_get_contents($local).($this->corrupt ? 'corrupt' : ''); }
    public function pullMany(array $downloads, callable $progress): void
    {
        $fixture = app(ArtifactDownloader::class);
        foreach ($downloads as $index=>$download) {
            $this->downloads[] = $download['path'];
            $this->data[$download['path']] = $fixture->files[$download['url']] ?? throw new Failure('artifact_download_failed');
            if ($this->corrupt) { $this->data[$download['path']] .= 'corrupt'; }
            ($this->onPull)?->__invoke();
            $progress(['phase'=>'download','files'=>$index+1,'total'=>count($downloads),'fraction'=>($index+1)/count($downloads),'file'=>$download['path']]);
        }
    }
    public function verifiedRead(string $path, ?int $size, array $hashes = [], ?string $destination = null): array
    {
        $bytes = $this->data[$path] ?? throw new Failure('missing_file');
        if ($size !== null && strlen($bytes) !== $size) { throw new Failure('size_mismatch'); }
        foreach ($hashes as $algorithm=>$hash) { if (!hash_equals($hash, hash($algorithm,$bytes))) { throw new Failure('hash_mismatch'); } }
        if ($destination) { file_put_contents($destination,$bytes); }
        return ['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
    }
    public function verifiedReadMany(array $reads, callable $completed, callable $heartbeat): void
    {
        foreach ($reads as $path=>$item) {
            ($this->onInitialVerify)?->__invoke();
            $heartbeat();
            try { $completed($path,$this->verifiedRead($path,$item['size'] ?? null,$item['hashes'] ?? [],$item['destination'] ?? null)); }
            finally { if (isset($item['destination']) && is_file($item['destination'])) { unlink($item['destination']); } }
        }
    }
    public function extract(string $archive, string $directory, ?callable $progress = null): void
    {
        $local = tempnam(sys_get_temp_dir(),'imm-zip'); file_put_contents($local,$this->data[$archive]);
        $zip = new ZipArchive; check($zip->open($local) === true);
        try {
            for ($index=0;$index<$zip->numFiles;$index++) { $name=$zip->getNameIndex($index); if (!str_ends_with($name,'/')) { $this->data[($directory==='.'?'':$directory.'/').$name]=$zip->getFromIndex($index); } if($this->failExtract){$this->failExtract=false;throw new Failure('fixture_extract_failure');} }
        } finally { $zip->close(); unlink($local); }
    }
    public function hash(string $path, int $limit = 536870912): string { return hash('sha256', $this->data[$path] ?? throw new Failure('missing_file')); }
    public function move(string $from, string $to): void
    {
        if (str_contains($from, '.download-') && $to === $this->failStagingTarget) { $this->failStagingTarget = null; throw new Failure('fixture_move_failure'); }
        if ($this->failMove && str_contains($from, '.download-') && !str_contains($to, '.download-')) {
            $this->failMove = false;
            if ($this->editAfterMove) { $this->data[$to] = 'concurrent-user-edit'; }
            throw new Failure('fixture_move_failure');
        }
        check(!$this->exists($to), 'Fixture refuses overwrite'); $this->data[$to] = $this->data[$from]; unset($this->data[$from]);
    }
    public function remove(string $path): void { unset($this->data[$path]); }
    public function moveMany(array $moves, ?callable $progress = null): void { foreach ($moves as $move) { $this->move($move['from'],$move['to']); } }
    public function removeMany(array $paths): void { foreach ($paths as $path) { $this->remove($path); } }
    public function verifyMany(array $journal, callable $progress): void
    {
        ($this->onVerify)?->__invoke();
        $done=0;foreach($journal as $path=>$item){if($item['new']===null?$this->exists($path):$this->hash($path)!==$item['new']){throw new Failure('node_hash_mismatch');}$progress(++$done,count($journal),$path);}
    }
}

$passed = 0;
function installTest(string $name, callable $test): void
{
    global $passed;
    try { $test(); ++$passed; echo "PASS $name\n"; }
    catch (Throwable $e) { fwrite(STDERR, "FAIL $name: ".$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); }
}

$owner = User::factory()->create();
$egg = Egg::factory()->create(['docker_images' => ['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21', 'Java 25' => 'ghcr.io/pelican-eggs/yolks:java_25']]);
$server = Server::factory()->create(['owner_id' => $owner->id, 'egg_id' => $egg->id, 'image' => 'ghcr.io/pelican-eggs/yolks:java_21']);
$profile = Profile::create(['egg_id' => $egg->id, 'name' => 'Install fixture', 'software' => 'FABRIC', 'loader' => 'fabric', 'content_type' => 'mod', 'images' => array_values($egg->docker_images), 'jar_path' => 'server.jar', 'enabled' => true]);
app(Settings::class)->save(['enabled' => true, 'experimental' => false, 'providers' => ['modrinth', 'curseforge', 'mcjars']]);
$http = new FixtureTransport([]); $download = new DownloadFixture; $node = new MemoryNode($server);
$app->instance(JsonTransport::class, $http); $app->instance(ArtifactDownloader::class, $download); $app->bind(NodeFiles::class, fn () => $node);

function modFixture(FixtureTransport $http, DownloadFixture $download, string $project, string $id, string $filename, string $bytes, array $dependencies = []): void
{
    $url = 'https://cdn.modrinth.com/'.$id.'.jar'; $download->files[$url] = $bytes;
    $http->responses['modrinth/v2/project/'.$project] = ['id' => $project, 'project_type' => 'mod', 'server_side' => 'required'];
    $http->responses['modrinth/v2/version/'.$id] = ['id' => $id, 'project_id' => $project, 'version_number' => $id, 'name' => $id, 'version_type' => 'release',
        'game_versions' => ['1.21.1'], 'loaders' => ['fabric'], 'dependencies' => $dependencies,
        'files' => [['filename' => $filename, 'url' => $url, 'size' => strlen($bytes), 'primary' => true, 'hashes' => ['sha512' => hash('sha512', $bytes)]]]];
}

function installOperation(User $owner, Server $server, string $id, string $project = 'root'): Operation
{
    $op = app(ReviewService::class)->create($owner, $server->fresh(), ['kind' => 'package', 'provider' => 'modrinth', 'project' => $project, 'release' => $id, 'version' => '1.21.1', 'type' => 'mod']);
    app(ReviewService::class)->queue($owner, $server->fresh(), $op->id, true);
    app()->call([new CheckReview($op->id, true), 'handle']); return $op->fresh();
}

modFixture($http, $download, 'dep', 'dep1', 'dependency.jar', 'dependency');
modFixture($http, $download, 'root', 'v1', 'root-v1.jar', 'root-v1', [['dependency_type' => 'required', 'project_id' => 'dep', 'version_id' => 'dep1']]);
installTest('required dependency and package install, durable hash inventory, duplicate job inert', function () use ($owner, $server, $node): void {
    $node->data['world/level.dat'] = 'world'; $node->data['mods/manual.jar'] = 'manual';
    $op = installOperation($owner, $server, 'v1');
    check($op->status === 'succeeded', json_encode([$op->status, $op->error_code]));
    check($node->data['mods/dependency.jar'] === 'dependency' && $node->data['mods/root-v1.jar'] === 'root-v1');
    check($node->data['world/level.dat'] === 'world' && $node->data['mods/manual.jar'] === 'manual');
    check(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id', $server->id)->count() === 2);
    $before = $node->data; app()->call([new CheckReview($op->id, true), 'handle']); check($node->data === $before);
});
modFixture($http, $download, 'root', 'v2', 'root-v2.jar', 'root-v2');
installTest('package update retires unchanged old filename without a manager folder or leftover downloads', function () use ($owner, $server, $node): void {
    $op = installOperation($owner, $server, 'v2'); check($op->status === 'succeeded', (string) $op->error_code);
    check(!isset($node->data['mods/root-v1.jar']) && $node->data['mods/root-v2.jar'] === 'root-v2');
    foreach (array_keys($node->data) as $path) { check(!str_contains($path, '.minecraft-manager') && !str_contains($path, '.download-')); }
    check(!is_dir(storage_path('app/minecraft-manager-recovery/'.$op->id)));
});
modFixture($http, $download, 'graph-a', 'graph-a1', 'graph-a.jar', 'a', [['dependency_type' => 'incompatible', 'project_id' => 'graph-b']]);
modFixture($http, $download, 'graph-b', 'graph-b1', 'graph-b.jar', 'b');
modFixture($http, $download, 'badgraph', 'badgraph1', 'graph-root.jar', 'root', [
    ['dependency_type' => 'required', 'project_id' => 'graph-a', 'version_id' => 'graph-a1'],
    ['dependency_type' => 'required', 'project_id' => 'graph-b', 'version_id' => 'graph-b1']]);
installTest('incompatibility with a later dependency fails before node staging', function () use ($owner, $server, $node): void {
    $before = $node->data; $op = installOperation($owner, $server, 'badgraph1', 'badgraph');
    check($op->status === 'failed' && $op->error_code === 'dependency_incompatible'); check($node->data === $before);
});
installTest('native file permissions are required in addition to plugin install permission', function () use ($server): void {
    $user = User::factory()->create();
    App\Models\Subuser::create(['server_id' => $server->id, 'user_id' => $user->id, 'permissions' => ['minecraft-manager.browse', 'minecraft-manager.plan', 'minecraft-manager.packages']]);
    $op = app(ReviewService::class)->create($user, $server->fresh(), ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'root', 'release' => 'v2', 'version' => '1.21.1', 'type' => 'mod']);
    try { app(ReviewService::class)->queue($user, $server->fresh(), $op->id, true); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { check($error->getStatusCode() === 403 && $op->fresh()->status === 'planned'); return; }
    throw new RuntimeException('Expected native file permission denial');
});
modFixture($http, $download, 'root', 'v3', 'root-v3.jar', 'root-v3');
installTest('modified managed jar blocks update before live changes', function () use ($owner, $server, $node): void {
    $node->data['mods/root-v2.jar'] = 'edited';
    $op = installOperation($owner, $server, 'v3'); check($op->status === 'failed' && $op->error_code === 'managed_file_modified');
    check($node->data['mods/root-v2.jar'] === 'edited' && !isset($node->data['mods/root-v3.jar']));
    $node->data['mods/root-v2.jar'] = 'root-v2';
});
modFixture($http, $download, 'running', 'running1', 'running.jar', 'running');
installTest('running server refuses installation before download and upload', function () use ($owner, $server, $node): void {
    $node->running = true; $before = $node->data;
    $op = installOperation($owner, $server, 'running1', 'running'); check($op->status === 'blocked' && $op->error_code === 'server_must_be_stopped');
    check($node->data === $before); $node->running = false;
    $retry = installOperation($owner, $server, 'running1', 'running');
    check($retry->id !== $op->id && $retry->status === 'succeeded', 'Corrected server should retry immediately with a fresh review');
});
modFixture($http, $download, 'corrupt', 'corrupt1', 'corrupt.jar', 'valid');
installTest('corrupt node staging refuses live replacement', function () use ($owner, $server, $node): void {
    $node->corrupt = true; $op = installOperation($owner, $server, 'corrupt1', 'corrupt');
    check($op->status === 'failed' && $op->error_code === 'size_mismatch'); check(!isset($node->data['mods/corrupt.jar'])); $node->corrupt = false;
});
modFixture($http, $download, 'rollback', 'rollback1', 'rollback.jar', 'new');
installTest('rename failure restores prior file from verified backup', function () use ($owner, $server, $node): void {
    $node->data['mods/rollback.jar'] = 'prior'; $node->failMove = true;
    $op = installOperation($owner, $server, 'rollback1', 'rollback');
    check($op->status === 'failed', json_encode([$op->status, $op->error_code])); check($node->data['mods/rollback.jar'] === 'prior');
});
modFixture($http, $download, 'conflict', 'conflict1', 'conflict.jar', 'new');
installTest('concurrent edit during failure is retained and blocks slot for manual recovery', function () use ($owner, $server, $node): void {
    $node->data['mods/conflict.jar'] = 'prior'; $node->failMove = true; $node->editAfterMove = true;
    $op = installOperation($owner, $server, 'conflict1', 'conflict');
    check($op->status === 'needs-reconciliation' && $op->server_slot === $server->id);
    check($node->data['mods/conflict.jar'] === 'concurrent-user-edit');
    $node->editAfterMove = false;
});
installTest('manual reconciliation retains reservation until original files and startup verify', function () use ($server, $node): void {
    Http::fake(['*' => Http::response('', 204)]);
    $op = Operation::where('server_id', $server->id)->where('status', 'needs-reconciliation')->latest()->firstOrFail();
    $args = ['operation' => $op->id, '--worker-stopped' => true];
    check(Illuminate\Support\Facades\Artisan::call('minecraft-manager:reconcile', $args) === 1);
    check($op->fresh()->server_slot === $server->id);
    $node->data['mods/conflict.jar'] = 'prior';
    check(Illuminate\Support\Facades\Artisan::call('minecraft-manager:reconcile', $args) === 0);
    check($op->fresh()->server_slot === null && $op->fresh()->step === 'manually-restored-and-verified');
});
installTest('admin read-only reconciliation cannot release an install; stopped-worker stale pre-apply recovery can', function () use ($owner, $server): void {
    $op = app(ReviewService::class)->create($owner, $server->fresh(), ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'root', 'release' => 'v2', 'version' => '1.21.1', 'type' => 'mod']);
    app(ReviewService::class)->queue($owner, $server->fresh(), $op->id, true); $op->update(['updated_at' => now()->subMinutes(6)]);
    $admin = User::factory()->create(); $admin->assignRole(App\Models\Role::findOrCreate(App\Models\Role::ROOT_ADMIN, 'web')); auth()->login($admin);
    $page = new IByte\MinecraftManager\Filament\Admin\MinecraftManager; $page->reconcileReview($op->id);
    check($page->error === 'reconciliation_not_available' && $op->fresh()->server_slot === $server->id);
    $op->update(['updated_at' => now()->subMinutes(32)]);
    check(Illuminate\Support\Facades\Artisan::call('minecraft-manager:reconcile', ['operation' => $op->id, '--worker-stopped' => true]) === 0);
    check($op->fresh()->server_slot === null && $op->fresh()->step === 'interrupted-before-apply');
});

$pluginBytes=zipBytes(['plugin.yml'=>"name: Fixture\nversion: '1'\nmain: fixture.Main\n",'fixture/Main.class'=>"\xca\xfe\xba\xbe".pack('nn',0,65)]);
modFixture($http, $download, 'paper-plugin', 'paper-version', 'server-plugin.jar', $pluginBytes);
$http->responses['modrinth/v2/version/paper-version']['loaders'] = ['paper'];
installTest('plugin release from legacy mod metadata installs into plugins without egg content mapping', function () use ($owner, $server, $node,$pluginBytes): void {
    $op = app(ReviewService::class)->create($owner, $server->fresh(), ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'paper-plugin', 'release' => 'paper-version', 'version' => '1.21.1', 'loader' => 'paper', 'type' => 'plugin']);
    check($op->plan['executable']);
    app(ReviewService::class)->queue($owner, $server->fresh(), $op->id, true); app()->call([new CheckReview($op->id, true), 'handle']);
    check($op->fresh()->status === 'succeeded', (string) $op->fresh()->error_code);
    check($node->data['plugins/server-plugin.jar'] === $pluginBytes && !isset($node->data['mods/server-plugin.jar']));
});

$build = ['uuid' => 'fabric-build', 'type' => 'FABRIC', 'version_id' => '1.21.1', 'project_version_id' => '0.16.10', 'experimental' => false,
    'installation' => [[['type' => 'download', 'url' => 'https://versions.mcjars.app/fabric.jar', 'file' => 'server.jar', 'size' => 8]]]];
$download->files['https://versions.mcjars.app/fabric.jar'] = 'launcher';
$http->responses['mcjars/api/v3/builds/fabric-build'] = ['build' => $build, 'version' => ['java' => 25, 'type' => 'RELEASE']];
installTest('native startup transaction failure restores staged runtime without requiring manual recovery', function () use ($owner,$server,$node): void {
    $service=app(App\Services\Servers\StartupModificationService::class);$before=$server->fresh();$node->data['server.jar']='original-launcher';
    $mock=Mockery::mock(App\Services\Servers\StartupModificationService::class);
    $mock->shouldReceive('setUserLevel')->andReturnSelf();$mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('Simulated rolled-back native startup transaction'));
    app()->instance(App\Services\Servers\StartupModificationService::class,$mock);
    try{
        $op=app(ReviewService::class)->create($owner,$before,['kind'=>'software','provider'=>'mcjars','project'=>'FABRIC','version'=>'1.21.1','release'=>'fabric-build']);
        app(ReviewService::class)->queue($owner,$before,$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);$op->refresh();
        check($op->status==='failed' && $op->server_slot===null && $node->data['server.jar']==='original-launcher');
        check($server->fresh()->startup===$before->startup && $server->fresh()->image===$before->image);
    }finally{app()->instance(App\Services\Servers\StartupModificationService::class,$service);Mockery::close();}
});
installTest('runtime install changes only native startup and approved Java image then syncs', function () use ($owner, $server, $node): void {
    Http::fake(['*' => Http::response('', 204)]); $node->data['server.jar'] = 'old-launcher';
    $before = $server->fresh(); $op = app(ReviewService::class)->create($owner, $before, ['kind' => 'software', 'provider' => 'mcjars', 'project' => 'FABRIC', 'version' => '1.21.1', 'release' => 'fabric-build']);
    app(ReviewService::class)->queue($owner, $before, $op->id, true); app()->call([new CheckReview($op->id, true), 'handle']);
    $op->refresh(); check($op->status === 'succeeded', json_encode([$op->status, $op->error_code]));
    $after = $server->fresh(); check($node->data['server.jar'] === 'launcher' && $after->image === 'ghcr.io/pelican-eggs/yolks:java_25');
    check(str_contains($after->startup, '-jar "server.jar"') && $after->skip_scripts === $before->skip_scripts);
    foreach (['memory', 'disk', 'cpu', 'allocation_id', 'egg_id'] as $field) { check($before->$field === $after->$field); }
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sync'));
});

function zipBytes(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'imm-zip'); $zip = new ZipArchive; check($zip->open($path, ZipArchive::OVERWRITE) === true);
    foreach ($files as $name => $value) { $zip->addFromString($name, $value); } $zip->close(); $bytes = file_get_contents($path); unlink($path); return $bytes;
}

$manifest = ['formatVersion' => 1, 'game' => 'minecraft', 'versionId' => 'pack1', 'dependencies' => ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'],
    'files' => [['path' => 'mods/pack.jar', 'fileSize' => 8, 'hashes' => ['sha512' => hash('sha512', 'pack-mod')], 'downloads' => ['https://cdn.modrinth.com/pack-mod.jar']],
        ['path'=>'resourcepacks/Better-Leaves-9.6.zip','fileSize'=>8,'hashes'=>['sha512'=>hash('sha512','resource')],'downloads'=>['https://cdn.modrinth.com/resource.zip'],'env'=>['server'=>'required']],
        ['path'=>'shaderpacks/Complementary.zip','fileSize'=>6,'hashes'=>['sha512'=>hash('sha512','shader')],'downloads'=>['https://cdn.modrinth.com/shader.zip'],'env'=>['server'=>'required']]]];
$packBytes = zipBytes(['modrinth.index.json' => json_encode($manifest), 'overrides/config/test.toml' => 'common', 'server-overrides/config/test.toml' => 'server', 'client-overrides/config/client.toml' => 'client',
    'overrides/config/yosbr/saves/Tutorial World/level.dat'=>'world-template']);
$download->files['https://cdn.modrinth.com/pack-mod.jar'] = 'pack-mod';
$download->files['https://cdn.modrinth.com/resource.zip']='resource';$download->files['https://cdn.modrinth.com/shader.zip']='shader';
modFixture($http, $download, 'pack', 'pack1', 'pack.mrpack', $packBytes);
$http->responses['modrinth/v2/project/pack']['project_type'] = 'modpack';
$http->responses['mcjars/api/v3/builds/types/FABRIC/versions/1.21.1'] = ['builds' => ['data' => [$build], 'total' => 1]];
installTest('Modrinth pack installs server files, exact loader, preserves existing configuration', function () use ($owner, $server, $node): void {
    $node->data['config/test.toml'] = 'operator-edit';
    $op = app(ReviewService::class)->create($owner, $server->fresh(), ['kind' => 'pack', 'provider' => 'modrinth', 'project' => 'pack', 'release' => 'pack1', 'version' => '1.21.1', 'type' => 'modpack']);
    $initialSeen=false;
    $node->onInitialVerify=function()use($op,&$initialSeen){$current=$op->fresh();check($current->status==='verifying'&&$current->plan['progress']['phase']==='verify'&&$current->plan['progress']['total']>0);$initialSeen=true;};
    app(ReviewService::class)->queue($owner, $server->fresh(), $op->id, true); app()->call([new CheckReview($op->id, true), 'handle']); $op->refresh();
    $node->onInitialVerify=null;check($initialSeen);
    check($op->status === 'succeeded', json_encode([$op->status, $op->error_code]));
    foreach(['download','archive-check','extract','verify','existing','apply','result','cleanup','record'] as $phase){check(isset($op->plan['progress']['phase_seconds'][$phase]),'Missing measured phase: '.$phase);}
    check($node->data['mods/pack.jar'] === 'pack-mod' && $node->data['config/test.toml'] === 'operator-edit');
    check($node->data['resourcepacks/Better-Leaves-9.6.zip']==='resource' && $node->data['shaderpacks/Complementary.zip']==='shader');
    check($node->data['config/yosbr/saves/Tutorial World/level.dat']==='world-template');
    check(!isset($node->data['config/client.toml']) && $node->data['world/level.dat'] === 'world');
});

function cfFixture(FixtureTransport $http, DownloadFixture $download, string $project, string $id, string $name, string $bytes, int $classId = 6): void
{
    $http->responses['modrinth/v2/version_file/'.hash('sha1',$bytes)] = new Failure('exact_release_unavailable');
    $http->responses['curseforge/v1/mods/'.$project] = ['data' => ['id' => (int) $project, 'classId' => $classId, 'allowModDistribution' => true]];
    $url = 'https://mediafilez.forgecdn.net/'.$id.'/'.$name; $download->files[$url] = $bytes;
    $http->responses['curseforge/v1/mods/'.$project.'/files/'.$id] = ['data' => ['id' => (int) $id, 'modId' => (int) $project,
        'fileName' => $name, 'fileLength' => strlen($bytes), 'displayName' => $name, 'downloadUrl' => $url, 'releaseType' => 1,
        'gameVersions' => ['1.21.1', 'Fabric'], 'dependencies' => [], 'hashes' => [['algo' => 1, 'value' => hash('sha1', $bytes)], ['algo' => 2, 'value' => hash('md5', $bytes)]]]];
}

cfFixture($http, $download, '123', '456', 'curse.jar', zipBytes(['fabric.mod.json' => '{"environment":"*"}']));
installTest('CurseForge mod installs with provider SHA-1 and MD5 metadata retained', function () use ($owner, $server, $node): void {
    $op = app(ReviewService::class)->create($owner, $server->fresh(), ['kind' => 'package', 'provider' => 'curseforge', 'project' => '123', 'release' => '456', 'version' => '1.21.1', 'type' => 'mod']);
    check($op->plan['executable']);
    app(ReviewService::class)->queue($owner, $server->fresh(), $op->id, true); app()->call([new CheckReview($op->id, true), 'handle']); $op->refresh();
    check($op->status === 'succeeded', json_encode([$op->status, $op->error_code])); check(isset($node->data['mods/curse.jar']));
});
installTest('CurseForge exact public CDN fallback completes a verified installation and records resolution', function () use ($owner,$server,$node,$http,$download): void {
    $bytes=zipBytes(['fabric.mod.json'=>'{"environment":"*"}']);
    cfFixture($http,$download,'912345','5052580','missing-url.jar',$bytes);
    $http->responses['curseforge/v1/mods/912345']['data']['allowModDistribution']=false;
    $http->responses['curseforge/v1/mods/912345/files/5052580']['data']['downloadUrl']=null;
    $download->files['https://edge.forgecdn.net/files/5052/580/missing-url.jar']=$bytes;
    $op=app(ReviewService::class)->create($owner,$server->fresh(),['kind'=>'package','provider'=>'curseforge','project'=>'912345','release'=>'5052580','version'=>'1.21.1','type'=>'mod']);
    app(ReviewService::class)->queue($owner,$server->fresh(),$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);$op->refresh();
    check($op->status==='succeeded' && $node->data['mods/missing-url.jar']===$bytes);
    check($op->plan['source_resolutions']===['missing-url.jar']);
});
$cfManifest = ['minecraft' => ['version' => '1.21.1', 'modLoaders' => [['id' => 'fabric-0.16.10', 'primary' => true]]],
    'files' => [['projectID' => 123, 'fileID' => 456, 'required' => true]], 'overrides' => 'overrides'];
cfFixture($http, $download, '789', '790', 'pack.zip', zipBytes(['manifest.json' => json_encode($cfManifest), 'overrides/config/fresh.toml' => 'new-config']), 4471);
installTest('CurseForge manifest pack resolves exact authorized mod files and loader', function () use ($owner, $server, $node): void {
    $op = app(ReviewService::class)->create($owner, $server->fresh(), ['kind' => 'pack', 'provider' => 'curseforge', 'project' => '789', 'release' => '790', 'version' => '1.21.1', 'type' => 'modpack']);
    app(ReviewService::class)->queue($owner, $server->fresh(), $op->id, true); app()->call([new CheckReview($op->id, true), 'handle']); $op->refresh();
    check($op->status === 'succeeded', json_encode([$op->status, $op->error_code]));
    check(isset($node->data['mods/curse.jar']) && $node->data['config/fresh.toml'] === 'new-config');
    check(!isset($node->data['mods/pack.jar']) && $node->data['world/level.dat'] === 'world');
});
cfFixture($http, $download, '791', '792', 'client.zip', zipBytes(['manifest.json' => json_encode($cfManifest)]), 4471);
installTest('CurseForge manifest skips reported client shader and resource packs without downloading them', function () use ($owner,$server,$node,$http,$download,$cfManifest): void {
    $manifest = $cfManifest;
    $manifest['files'][] = ['projectID'=>901,'fileID'=>902,'required'=>true];
    $manifest['files'][] = ['projectID'=>903,'fileID'=>904,'required'=>true];
    $http->responses['curseforge/v1/mods/901']=['data'=>['id'=>901,'name'=>'Complementary shader pack','classId'=>6552,'allowModDistribution'=>false]];
    $http->responses['curseforge/v1/mods/903']=['data'=>['id'=>903,'name'=>'Faithful resource pack','classId'=>12,'allowModDistribution'=>false]];
    cfFixture($http,$download,'905','906','client-assets-pack.zip',zipBytes(['manifest.json'=>json_encode($manifest)]),4471);
    $op=app(ReviewService::class)->create($owner,$server->fresh(),['kind'=>'pack','provider'=>'curseforge','project'=>'905','release'=>'906','version'=>'1.21.1','type'=>'modpack']);
    app(ReviewService::class)->queue($owner,$server->fresh(),$op->id,true); app()->call([new CheckReview($op->id,true),'handle']); $op->refresh();
    check($op->status==='succeeded',json_encode([$op->status,$op->error_code]));
    check(count($op->plan['skipped'])===2 && isset($node->data['mods/curse.jar']));
});
installTest('unknown required non-JAR mods still fail with the exact filename', function () use ($owner,$server,$http,$download,$cfManifest): void {
    $manifest=$cfManifest; $manifest['files']=[['projectID'=>907,'fileID'=>908,'required'=>true]];
    cfFixture($http,$download,'907','908','unsupported.zip',zipBytes(['unexpected.bin'=>'bytes']),6);
    cfFixture($http,$download,'909','910','unknown-mod-pack.zip',zipBytes(['manifest.json'=>json_encode($manifest)]),4471);
    $op=app(ReviewService::class)->create($owner,$server->fresh(),['kind'=>'pack','provider'=>'curseforge','project'=>'909','release'=>'910','version'=>'1.21.1','type'=>'modpack']);
    app(ReviewService::class)->queue($owner,$server->fresh(),$op->id,true); app()->call([new CheckReview($op->id,true),'handle']); $op->refresh();
    check($op->status==='failed' && $op->error_code==='invalid_pack_file' && $op->plan['failure']['file']==='unsupported.zip');
});
installTest('exact hash metadata identifies client-only Forge mods without restricted downloads', function () use ($owner,$server,$http,$download,$cfManifest): void {
    $bytes='client-only-forge-file'; cfFixture($http,$download,'911','912','client-only.jar',$bytes,6);
    $http->responses['curseforge/v1/mods/911']['data']['allowModDistribution']=false;
    $http->responses['modrinth/v2/version_file/'.hash('sha1',$bytes)]=['id'=>'client-v1','project_id'=>'clientmod','environment'=>'client_only',
        'files'=>[['hashes'=>['sha1'=>hash('sha1',$bytes)],'size'=>strlen($bytes)]]];
    $manifest=$cfManifest; $manifest['files'][]=['projectID'=>911,'fileID'=>912,'required'=>true];
    cfFixture($http,$download,'913','914','client-mod-pack.zip',zipBytes(['manifest.json'=>json_encode($manifest)]),4471);
    $op=app(ReviewService::class)->create($owner,$server->fresh(),['kind'=>'pack','provider'=>'curseforge','project'=>'913','release'=>'914','version'=>'1.21.1','type'=>'modpack']);
    app(ReviewService::class)->queue($owner,$server->fresh(),$op->id,true); app()->call([new CheckReview($op->id,true),'handle']); $op->refresh();
    check($op->status==='succeeded' && count($op->plan['skipped'])===1 && $op->plan['skipped'][0]['skip']==='client_mod');
});
installTest('mismatched hash or size cannot classify a required mod as client-only', function () use ($http): void {
    $key='modrinth/v2/version_file/'.hash('sha1','client-only-forge-file');
    $http->responses[$key]['files'][0]['size']++;
    $release=app(Catalog::class)->provider('curseforge')->manifestRelease('911','912');
    check(!isset($release['skip']) && $release['id']==='912');
});
cfFixture($http, $download, '791', '793', 'server.zip', zipBytes(['mods/dedicated.jar' => 'dedicated', 'config/dedicated.toml' => 'dedicated-config', 'start.sh' => 'never execute']), 4471);
$http->responses['curseforge/v1/mods/791/files/792']['data']['serverPackFileId'] = 793;
installTest('CurseForge linked dedicated server pack uses safe content and ignores startup scripts', function () use ($owner, $server, $node): void {
    $op = app(ReviewService::class)->create($owner, $server->fresh(), ['kind' => 'pack', 'provider' => 'curseforge', 'project' => '791', 'release' => '792', 'version' => '1.21.1', 'type' => 'modpack']);
    app(ReviewService::class)->queue($owner, $server->fresh(), $op->id, true); app()->call([new CheckReview($op->id, true), 'handle']); $op->refresh();
    check($op->status === 'succeeded', json_encode([$op->status, $op->error_code]));
    check($node->data['mods/dedicated.jar'] === 'dedicated' && !isset($node->data['start.sh']));
});

installTest('stock Wings streamed binary write/read uses native authenticated HTTP client', function () use ($server): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    $bytes = random_bytes(1048576); $file = tempnam(sys_get_temp_dir(), 'imm-stream'); file_put_contents($file, $bytes);
    $sent = false;
    Http::fake(function ($request) use ($bytes, &$sent) {
        if (str_contains($request->url(), '/files/list-directory')) { return Http::response([['name' => 'mods', 'directory' => true, 'file' => false, 'symlink' => false]]); }
        if (str_contains($request->url(), '/files/contents')) { return Http::response($bytes, 200, ['Content-Length' => (string) strlen($bytes)]); }
        if (str_contains($request->url(), '/files/write')) { check($request->body() === $bytes && $request->hasHeader('Authorization')); $sent = true; }
        return Http::response('', 204);
    });
    $native = new NodeFiles($server->fresh());
    try { $native->upload('mods/stream.jar', $file); check($native->hash('mods/stream.jar') === hash('sha256', $bytes)); }
    finally { unlink($file); }
    rejects(fn () => $native->hash('mods/stream.jar', 1024), 'artifact_size_limit');
    check($sent, 'Native authenticated binary write was not sent');
});
installTest('native node-state contract accepts only offline and rejects missing or running nodes', function () use ($server): void {
    Http::swap(new \Illuminate\Http\Client\Factory); $state = 'running';
    Http::fake(function () use (&$state) { return Http::response(['state' => $state]); });
    $native = new NodeFiles($server->fresh());
    rejects(fn () => $native->assertStopped(), 'server_must_be_stopped');
    $state = 'offline'; $native->assertStopped();
    $state = 'missing'; rejects(fn () => $native->assertStopped(), 'node_unavailable');
});

installTest('prepared Fabric and Quilt launchers become server.jar with their properties and libraries retained', function () use ($download,$profile,$root,$http): void {
    foreach (['fabric','quilt'] as $family) {
        $zip=zipBytes([$family.'-server-launch.jar'=>'prepared-launcher','minecraft.jar'=>'vanilla-base', $family.'-server-launcher.properties'=>'serverJar=minecraft.jar', 'libraries/example/lib.jar'=>'library']);
        $url='https://versions.mcjars.app/'.$family.'-prepared.zip'; $download->files[$url]=$zip;
        $release=['provider'=>'mcjars','project'=>strtoupper($family),'id'=>'prepared-'.$family,'version'=>'1.21.1','java'=>21,
            'recipe'=>[[['type'=>'download','url'=>$url,'file'=>'runtime.zip','size'=>strlen($zip)],['type'=>'unzip','file'=>'runtime.zip','location'=>'.'],['type'=>'remove','location'=>'runtime.zip']]]];
        $http->responses['mcjars/api/v3/builds/'.$release['id']]=['build'=>['uuid'=>$release['id'],'type'=>$release['project'],'version_id'=>$release['version'],'installation'=>$release['recipe']], 'version'=>['java'=>21,'type'=>'RELEASE']];
        $release=app(McJars::class)->exact($release['project'],$release['version'],$release['id']);
        $work=$root.'/.build/launcher-regression-'.$family.'-'.bin2hex(random_bytes(4)); mkdir($work,0700,true);
        $prepared=app(InstallPreparer::class)->prepare($release,['kind'=>'software'],$profile,$work);
        check($prepared['runtime']['launcher']==='server.jar' && file_get_contents($prepared['files']['server.jar']['local'])==='prepared-launcher');
        check(!isset($prepared['files'][$family.'-server-launch.jar']) && isset($prepared['files']['minecraft.jar'],$prepared['files']['libraries/example/lib.jar']));
        check(file_get_contents($prepared['files'][$family.'-server-launcher.properties']['local'])==='serverJar=minecraft.jar');
    }
});
$batchFixture = function () use ($owner, $http, $download): array {
    $egg = Egg::factory()->create();
    $server = Server::factory()->create(['owner_id'=>$owner->id, 'egg_id'=>$egg->id]);
    app(IByte\MinecraftManager\Software\ProfileWizard::class)->save(['egg_id'=>$egg->id,'enabled'=>true]);
    $node = new MemoryNode($server); app()->bind(NodeFiles::class, fn () => $node);
    modFixture($http,$download,'batchdep','batch-dep-v1','batch-dep.jar','dep');
    $dep = [['dependency_type'=>'required','version_id'=>'batch-dep-v1','project_id'=>'batchdep']];
    modFixture($http,$download,'batcha','batch-a-v1','batch-a.jar','aaaa',$dep);
    modFixture($http,$download,'batchb','batch-b-v1','batch-b.jar','bbbb',$dep);
    $reviews = app(ReviewService::class); $ids=[];
    foreach (['a','b'] as $suffix) { $ids[]=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'batch'.$suffix,'release'=>'batch-'.$suffix.'-v1','version'=>'1.21.1','loader'=>'fabric','type'=>'mod'])->id; }
    return [$server,$node,$reviews,$ids];
};
installTest('multiple reviewed mods share one reservation, dependency graph and verified install', function () use ($batchFixture,$owner): void {
    [$server,$node,$reviews,$ids]=$batchFixture();
    $op=$reviews->createBatch($owner,$server,$ids); check(count($op->selection['items'])===2 && $op->plan['executable']);
    $reviews->queue($owner,$server,$op->id,true);
    app()->call([new CheckReview($op->id,true),'handle']);
    check($op->fresh()->status==='succeeded' && $op->fresh()->server_slot===null, 'Batch install failed: '.$op->fresh()->error_code);
    check($node->data['mods/batch-a.jar']==='aaaa' && $node->data['mods/batch-b.jar']==='bbbb' && $node->data['mods/batch-dep.jar']==='dep');
    check(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->count()===3);
});
installTest('batch rejects expired, foreign, duplicate and incompatible selections', function () use ($batchFixture,$owner): void {
    [$server,$node,$reviews,$ids]=$batchFixture();
    rejects(fn ()=>$reviews->createBatch($owner,$server,[$ids[0],$ids[0]]),'batch_limit');
    $other=User::factory()->create(); $op=Operation::findOrFail($ids[0]); $op->update(['actor_id'=>$other->id]);
    rejects(fn ()=>$reviews->createBatch($owner,$server,$ids),'batch_review_expired');
    $op->update(['actor_id'=>$owner->id,'expires_at'=>now()->subSecond()]);
    rejects(fn ()=>$reviews->createBatch($owner,$server,$ids),'batch_review_expired');
    $op->update(['expires_at'=>now()->addMinutes(10)]);
    $second=Operation::findOrFail($ids[1]); $selection=$second->selection; $selection['loader']='quilt'; $plan=$second->plan;
    $second->update(['selection'=>$selection,'digest'=>IByte\MinecraftManager\Operations\PlanBuilder::digest($plan)]);
    rejects(fn ()=>$reviews->createBatch($owner,$server,$ids),'batch_context_mismatch'); check($node->data===[]);
});
installTest('changed exact metadata in any batch item blocks before node writes', function () use ($batchFixture,$owner,$http): void {
    [$server,$node,$reviews,$ids]=$batchFixture(); $op=$reviews->createBatch($owner,$server,$ids); $reviews->queue($owner,$server,$op->id,true);
    $http->responses['modrinth/v2/version/batch-b-v1']['name']='Changed after confirmation';
    app()->call([new CheckReview($op->id,true),'handle']);
    check($op->fresh()->error_code==='provider_release_changed' && $node->data===[]);
});
installTest('batch dependency conflict is detected before live file replacement', function () use ($batchFixture,$owner,$http,$download): void {
    [$server,$node,$reviews,$ids]=$batchFixture();
    modFixture($http,$download,'batchdep','batch-dep-v2','batch-dep-v2.jar','dep2');
    $http->responses['modrinth/v2/version/batch-b-v1']['dependencies']=[['dependency_type'=>'required','version_id'=>'batch-dep-v2','project_id'=>'batchdep']];
    $fresh=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'batchb','release'=>'batch-b-v1','version'=>'1.21.1','loader'=>'fabric','type'=>'mod']);
    $op=$reviews->createBatch($owner,$server,[$ids[0],$fresh->id]); $reviews->queue($owner,$server,$op->id,true);
    app()->call([new CheckReview($op->id,true),'handle']);
    check($op->fresh()->error_code==='dependency_conflict' && $node->data===[]);
});
installTest('later batch file failure restores earlier promoted mods and original files', function () use ($batchFixture,$owner): void {
    [$server,$node,$reviews,$ids]=$batchFixture();
    $node->data['mods/batch-a.jar']='original-a'; $node->data['mods/batch-b.jar']='original-b';
    $node->failStagingTarget='mods/batch-b.jar';
    $op=$reviews->createBatch($owner,$server,$ids); $reviews->queue($owner,$server,$op->id,true);
    app()->call([new CheckReview($op->id,true),'handle']);
    check($op->fresh()->status==='failed' && $op->fresh()->server_slot===null);
    check($node->data['mods/batch-a.jar']==='original-a' && $node->data['mods/batch-b.jar']==='original-b' && !isset($node->data['mods/batch-dep.jar']));
    check(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->count()===0);
});
installTest('mod downloads stay in mods with temporary sibling files and no manager path', function () use ($batchFixture,$owner): void {
    [$server,$node,$reviews,$ids]=$batchFixture();
    $op=$reviews->createBatch($owner,$server,$ids); $reviews->queue($owner,$server,$op->id,true); app()->call([new CheckReview($op->id,true),'handle']);
    check($op->fresh()->status==='succeeded');
    foreach($node->downloads as $path){check(dirname($path)==='mods' && str_ends_with($path,'.part') && !str_contains($path,'.minecraft-manager'));}
    foreach(array_keys($node->data) as $path){check(!str_contains($path,'.download-') && !str_contains($path,'.minecraft-manager'));}
});

$workspaceFixture=function()use($batchFixture,$owner){
    [$server,$node,$reviews,$ids]=$batchFixture();$op=$reviews->createBatch($owner,$server,$ids);
    return [$server,$node,$op,new IByte\MinecraftManager\Operations\InstallWorkspace($node,$op)];
};
installTest('native archive extraction restores existing raw entries and routes selected files to correct directories', function()use($workspaceFixture,$root){
    [$server,$node,$op,$workspace]=$workspaceFixture();
    $node->data['manifest.json']='operator-manifest';$node->data['overrides/config/test.toml']='operator-raw';
    $bytes=zipBytes(['manifest.json'=>'provider-manifest','overrides/config/test.toml'=>'provider-config']);$node->data['provider.zip']=$bytes;
    $directory=$root.'/.build/workspace-archive-'.$op->id;mkdir($directory,0700,true);file_put_contents($directory.'/pack.zip',$bytes);
    $entries=(new IByte\MinecraftManager\Modpacks\ArchiveExtractor)->extract($directory.'/pack.zip',$directory.'/expanded');
    $files=$workspace->expand('provider.zip',$entries,['overrides/config/test.toml'=>'config/test.toml']);
    check($node->data['manifest.json']==='operator-manifest'&&$node->data['overrides/config/test.toml']==='operator-raw');
    check(dirname($files['overrides/config/test.toml'])==='config' && $node->data[$files['overrides/config/test.toml']]==='provider-config');
    $workspace->cleanup(true);foreach(array_keys($node->data) as $path){check(!str_contains($path,'.download-')&&!str_contains($path,'.minecraft-manager'));}
});
installTest('1000 extracted pack files verify before promotion with monotonic completion counts',function()use($workspaceFixture,$root){
    [$server,$node,$op,$workspace]=$workspaceFixture();$contents=['overrides/config/empty.toml'=>''];
    for($i=1;$i<1000;$i++){$contents['overrides/config/test-'.$i.'.toml']='configuration-'.$i;}
    $bytes=zipBytes($contents);$node->data['large-provider.zip']=$bytes;
    $directory=$root.'/.build/workspace-large-'.$op->id;mkdir($directory,0700,true);file_put_contents($directory.'/pack.zip',$bytes);
    $entries=(new IByte\MinecraftManager\Modpacks\ArchiveExtractor)->extract($directory.'/pack.zip',$directory.'/expanded');
    $selected=[];foreach($contents as $path=>$value){$selected[$path]=substr($path,10);}
    $counts=[];$node->onVerify=function()use($node,$contents){foreach($contents as $path=>$value){check(isset($node->data[$path])&&$node->data[$path]===$value);}foreach(array_keys($node->data) as $path){check(!str_contains($path,'.download-'),'Archive outputs moved before batch verification');}};
    $files=$workspace->expand('large-provider.zip',$entries,$selected,[],static function($state)use(&$counts){check($state['phase']==='extract'&&$state['total']===1000);$counts[]=$state['files'];});
    $node->onVerify=null;check(count($files)===1000&&$counts===range(1,1000));
    foreach($files as $raw=>$staged){check($node->data[$staged]===$contents[$raw]&&!isset($node->data[$raw]));}
    $workspace->cleanup(true);
});
installTest('corrupt extracted pack output is rejected before selected files are moved',function()use($workspaceFixture,$root){
    [$server,$node,$op,$workspace]=$workspaceFixture();$bytes=zipBytes(['overrides/config/test.toml'=>'valid']);$node->data['provider.zip']=$bytes;
    $directory=$root.'/.build/workspace-corrupt-'.$op->id;mkdir($directory,0700,true);file_put_contents($directory.'/pack.zip',$bytes);
    $entries=(new IByte\MinecraftManager\Modpacks\ArchiveExtractor)->extract($directory.'/pack.zip',$directory.'/expanded');
    $node->onVerify=function()use($node){$node->data['overrides/config/test.toml']='corrupted';};
    rejects(fn()=>$workspace->expand('provider.zip',$entries,['overrides/config/test.toml'=>'config/test.toml']),'file_changed_during_install');
    $node->onVerify=null;check($node->data['overrides/config/test.toml']==='corrupted'&&!isset($node->data['config/test.toml']));
    foreach(array_keys($node->data) as $path){check(!str_contains($path,'.download-'));}
});
installTest('partial native extraction restores old entries from private Panel recovery copies', function()use($workspaceFixture,$root){
    [$server,$node,$op,$workspace]=$workspaceFixture();$node->data['config/test.toml']='operator';
    $bytes=zipBytes(['config/test.toml'=>'provider','config/new.toml'=>'new']);$node->data['provider.zip']=$bytes;
    $directory=$root.'/.build/workspace-archive-'.$op->id;mkdir($directory,0700,true);file_put_contents($directory.'/pack.zip',$bytes);
    $entries=(new IByte\MinecraftManager\Modpacks\ArchiveExtractor)->extract($directory.'/pack.zip',$directory.'/expanded');$node->failExtract=true;
    rejects(fn()=>$workspace->expand('provider.zip',$entries,['config/test.toml'=>'config/test.toml']),'fixture_extract_failure');
    (new IByte\MinecraftManager\Operations\InstallWorkspace($node,$op->fresh()))->rollback();$workspace->cleanup(true);
    check($node->data['config/test.toml']==='operator' && !isset($node->data['config/new.toml']));
});
installTest('durable recovery survives a fresh workspace instance with no node backup directory', function()use($workspaceFixture){
    [$server,$node,$op,$workspace]=$workspaceFixture();$node->data['mods/original.jar']='old';
    $workspace->reserve(['mods/original.jar'=>hash('sha256','new')]);$node->data['mods/original.jar']='new';
    check(is_file($workspace->directory().'/'.hash('sha256','mods/original.jar').'.bin'));
    $reloaded=new IByte\MinecraftManager\Operations\InstallWorkspace($node,$op->fresh());$reloaded->rollback();$reloaded->cleanup(true);
    check($node->data===['mods/original.jar'=>'old'] && !is_dir($workspace->directory()));
});
installTest('runtime archive writes final paths directly and preserves omitted operator files', function()use($workspaceFixture,$root){
    [$server,$node,$op,$workspace]=$workspaceFixture();
    $node->data=['server.jar'=>'old-runtime','start.sh'=>'operator-script'];
    $bytes=zipBytes(['server.jar'=>'new-runtime','libraries/new.jar'=>'library','start.sh'=>'provider-script']);$node->data['provider.zip']=$bytes;
    $directory=$root.'/.build/direct-runtime-'.$op->id;mkdir($directory,0700,true);file_put_contents($directory.'/runtime.zip',$bytes);
    $entries=(new IByte\MinecraftManager\Modpacks\ArchiveExtractor)->extract($directory.'/runtime.zip',$directory.'/expanded');
    $result=$workspace->expandRuntime('provider.zip',$entries,['server.jar'=>'server.jar','libraries/new.jar'=>'libraries/new.jar'],[]);
    check($result===['server.jar'=>'server.jar','libraries/new.jar'=>'libraries/new.jar']);
    check($node->data['server.jar']==='new-runtime'&&$node->data['libraries/new.jar']==='library'&&$node->data['start.sh']==='operator-script');
    check($workspace->original('server.jar')===hash('sha256','old-runtime'));
    $workspace->cleanup(false);
    $restored=new IByte\MinecraftManager\Operations\InstallWorkspace($node,$op->fresh());$restored->rollback();$restored->cleanup(true);
    check($node->data['server.jar']==='old-runtime'&&!isset($node->data['libraries/new.jar'])&&$node->data['start.sh']==='operator-script');
});
installTest('partial direct runtime extraction restores old bytes and preserves the world', function()use($workspaceFixture,$root){
    [$server,$node,$op,$workspace]=$workspaceFixture();$node->data=['server.jar'=>'old','world/level.dat'=>'world'];
    $bytes=zipBytes(['server.jar'=>'new','libraries/new.jar'=>'library']);$node->data['provider.zip']=$bytes;
    $directory=$root.'/.build/direct-runtime-'.$op->id;mkdir($directory,0700,true);file_put_contents($directory.'/runtime.zip',$bytes);
    $entries=(new IByte\MinecraftManager\Modpacks\ArchiveExtractor)->extract($directory.'/runtime.zip',$directory.'/expanded');$node->failExtract=true;
    rejects(fn()=>$workspace->expandRuntime('provider.zip',$entries,['server.jar'=>'server.jar','libraries/new.jar'=>'libraries/new.jar'],[]),'fixture_extract_failure');
    $restored=new IByte\MinecraftManager\Operations\InstallWorkspace($node,$op->fresh());$restored->rollback();$restored->cleanup(true);
    check($node->data['server.jar']==='old'&&$node->data['world/level.dat']==='world'&&!isset($node->data['libraries/new.jar']));
});
installTest('missing private recovery bytes prevent any rollback mutation', function()use($workspaceFixture){
    [$server,$node,$op,$workspace]=$workspaceFixture();$node->data['mods/original.jar']='old';$workspace->reserve(['mods/original.jar'=>hash('sha256','new')]);$node->data['mods/original.jar']='new';
    $backup=$workspace->directory().'/'.hash('sha256','mods/original.jar').'.bin';unlink($backup);
    rejects(fn()=>$workspace->rollback(),'rollback_backup_missing');check($node->data['mods/original.jar']==='new');$workspace->cleanup(true);
});
installTest('worker timeout during preparation retains its reservation when direct writes have a journal', function()use($workspaceFixture){
    [$server,$node,$op,$workspace]=$workspaceFixture();$workspace->reserve(['mods/interrupted.jar'=>hash('sha256','new')]);
    $op->update(['status'=>'downloading','server_slot'=>$server->id]);(new CheckReview($op->id,true))->failed(new RuntimeException('timeout'));
    check($op->fresh()->status==='needs-reconciliation'&&$op->fresh()->server_slot===$server->id);
    $op->update(['status'=>'failed','server_slot'=>null]);$workspace->cleanup(true);
});
installTest('archive entries cannot recreate the removed manager directory', function()use($workspaceFixture){
    [$server,$node,$op,$workspace]=$workspaceFixture();rejects(fn()=>$workspace->reserve(['.minecraft-manager/anything'=>hash('sha256','x')]),'unsafe_path');check($node->data===[]);
});

$runtimeFixture = static function () use ($owner): array {
    $egg = Egg::factory()->create(['docker_images'=>['Java 21'=>'ghcr.io/pelican-eggs/yolks:java_21','Java 25'=>'ghcr.io/pelican-eggs/yolks:java_25']]);
    foreach (['SERVER_TYPE'=>'neoforge','VERSION'=>'1.20.4','BUILDS'=>'20.4.10','JVM_ARGS'=>'-XX:+AlwaysPreTouch','MAXIMUM_MEMORY'=>'90','SERVER_JARFILE'=>'old.jar'] as $key=>$value) {
        App\Models\EggVariable::factory()->create(['egg_id'=>$egg->id,'name'=>$key,'env_variable'=>$key,'default_value'=>$value,'rules'=>'required|string','user_editable'=>false,'user_viewable'=>true]);
    }
    $startup='java -Xmx$(({{SERVER_MEMORY}}*{{MAXIMUM_MEMORY}}/100))M {{JVM_ARGS}} -jar server.jar nogui';
    $server=Server::factory()->create(['owner_id'=>$owner->id,'egg_id'=>$egg->id,'image'=>'ghcr.io/pelican-eggs/yolks:java_21','startup'=>$startup]);
    Profile::create(['egg_id'=>$egg->id,'name'=>'Runtime context','enabled'=>true,'jar_path'=>'server.jar','software'=>'UNKNOWN','loader'=>'','content_type'=>'none','images'=>[]]);
    $node=new MemoryNode($server);$node->data=['world/level.dat'=>'keep-world','server.jar'=>'old-runtime','libraries/neoforge/old.jar'=>'managed-old-library'];
    Illuminate\Support\Facades\DB::table('imm_inventory')->insert(['server_id'=>$server->id,'path'=>'libraries/neoforge/old.jar','metadata'=>json_encode(['sha256'=>hash('sha256','managed-old-library'),'owner'=>['provider'=>'mcjars','project'=>'NEOFORGE','release'=>'old-managed','kind'=>'software']]),'created_at'=>now(),'updated_at'=>now()]);
    app()->bind(NodeFiles::class,fn()=>$node);
    Http::swap(new Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response('',204)]);
    return [$server,$node];
};
$installPack=static function(Server $server,string $provider='modrinth',string $project='pack',string $release='pack1')use($owner):Operation{
    $reviews=app(ReviewService::class);$op=$reviews->create($owner,$server,['kind'=>'pack','provider'=>$provider,'project'=>$project,'release'=>$release,'type'=>'modpack']);
    $reviews->queue($owner,$server,$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);return $op->fresh();
};
installTest('Modrinth pack replaces configured Minecraft and loader variables while preserving JVM settings and custom server.jar startup',function()use($runtimeFixture,$installPack){
    [$server,$node]=$runtimeFixture();$before=IByte\MinecraftManager\Support\RuntimeVariables::environment($server);$eggBefore=$server->egg->variables()->get()->toJson();
    $op=$installPack($server);check($op->status==='succeeded',(string)$op->error_code);
    $environment=IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh());
    check($environment['SERVER_TYPE']==='fabric'&&$environment['VERSION']==='1.21.1'&&$environment['BUILDS']==='0.16.10'&&$environment['SERVER_JARFILE']==='server.jar');
    check($environment['JVM_ARGS']===$before['JVM_ARGS']&&$environment['MAXIMUM_MEMORY']===$before['MAXIMUM_MEMORY']&&$server->fresh()->startup===$server->startup);
    check($server->egg->variables()->get()->toJson()===$eggBefore&&$node->data['server.jar']==='launcher'&&$node->data['world/level.dat']==='keep-world');
    check(!isset($node->data['libraries/neoforge/old.jar'])&&!Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','libraries/neoforge/old.jar')->exists());
    $runtime=IByte\MinecraftManager\Support\Access::runtime($server->fresh(),Profile::where('egg_id',$server->egg_id)->firstOrFail());
    check($runtime['version']==='1.21.1'&&$runtime['loader']==='fabric'&&$runtime['build_version']==='0.16.10'&&$runtime['source']==='installed');
    check(!isset($op->plan['previous_startup']['environment']['JVM_ARGS']));
});
installTest('CurseForge pack automatically installs its declared runtime and synchronizes startup variables',function()use($runtimeFixture,$installPack){
    [$server,$node]=$runtimeFixture();$op=$installPack($server,'curseforge','789','790');check($op->status==='succeeded',(string)$op->error_code);
    $environment=IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh());
    check($environment['VERSION']==='1.21.1'&&$environment['SERVER_TYPE']==='fabric'&&$environment['BUILDS']==='0.16.10');check(isset($node->data['mods/curse.jar'])&&$node->data['server.jar']==='launcher');
});
installTest('Paper runtime writes a numeric build value instead of its display prefix or UUID',function()use($runtimeFixture,$owner,$http,$download){
    [$server,$node]=$runtimeFixture();$server->egg->variables()->where('env_variable','BUILDS')->firstOrFail()->update(['rules'=>'required|integer']);
    $download->files['https://versions.mcjars.app/paper14.jar']='paper14';
    $http->responses['mcjars/api/v3/builds/paper14']=['build'=>['uuid'=>'paper14','name'=>'#232','type'=>'PAPER','version_id'=>'1.21.4','installation'=>[[['type'=>'download','url'=>'https://versions.mcjars.app/paper14.jar','file'=>'server.jar','size'=>7]]]],'version'=>['java'=>21,'type'=>'RELEASE']];
    $reviews=app(ReviewService::class);$op=$reviews->create($owner,$server,['kind'=>'software','provider'=>'mcjars','project'=>'PAPER','version'=>'1.21.4','release'=>'paper14']);
    $reviews->queue($owner,$server,$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);$op->refresh();check($op->status==='succeeded',(string)$op->error_code);
    $environment=IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh());check($environment['SERVER_TYPE']==='paper'&&$environment['VERSION']==='1.21.4'&&$environment['BUILDS']==='232');
    $missing=$http->responses['mcjars/api/v3/builds/paper14'];$missing['build']['uuid']='paper14-missing';unset($missing['build']['name']);$http->responses['mcjars/api/v3/builds/paper14-missing']=$missing;
    $op=$reviews->create($owner,$server->fresh(),['kind'=>'software','provider'=>'mcjars','project'=>'PAPER','version'=>'1.21.4','release'=>'paper14-missing']);$reviews->queue($owner,$server->fresh(),$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);
    check($op->fresh()->error_code==='provider_schema_changed'&&IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh())===$environment&&$node->data['server.jar']==='paper14');
});
installTest('incompatible egg variable validation fails a pack before content downloads and preserves the original runtime',function()use($runtimeFixture,$installPack){
    [$server,$node]=$runtimeFixture();$server->egg->variables()->where('env_variable','SERVER_TYPE')->firstOrFail()->update(['rules'=>'required|in:neoforge']);
    $before=$node->data;$environment=IByte\MinecraftManager\Support\RuntimeVariables::environment($server);$op=$installPack($server);
    check($op->status==='failed'&&$op->error_code==='runtime_variables_invalid'&&$op->server_slot===null);
    check($node->data===$before&&IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh())===$environment);
    check(!array_filter($node->downloads,fn($path)=>str_starts_with($path,'mods/')));
});
installTest('native sync failure restores runtime variables and original files while preserving an unrelated operator edit',function()use($runtimeFixture,$installPack){
    [$server,$node]=$runtimeFixture();$before=IByte\MinecraftManager\Support\RuntimeVariables::snapshot($server);$syncs=0;
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(function($request)use($server,&$syncs){if(str_ends_with($request->url(),'/sync')&&++$syncs===1){
        $variable=$server->egg->variables()->where('env_variable','JVM_ARGS')->firstOrFail();App\Models\ServerVariable::updateOrCreate(['server_id'=>$server->id,'variable_id'=>$variable->id],['variable_value'=>'operator-change']);
        return Http::response('',503,['User-Agent'=>'Pelican Wings/v1.0.0 (id:'.$server->node->daemon_token_id.')']);}return Http::response('',204);});
    $op=$installPack($server);check($op->status==='failed'&&$op->server_slot===null,(string)$op->error_code);
    check(IByte\MinecraftManager\Support\RuntimeVariables::snapshot($server->fresh())===$before&&$node->data['server.jar']==='old-runtime'&&$node->data['world/level.dat']==='keep-world');
    check($node->data['libraries/neoforge/old.jar']==='managed-old-library');
    check(IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh())['JVM_ARGS']==='operator-change'&&$syncs===2);
});
installTest('a concurrent runtime variable edit retains recovery and reconciliation requires the original variables',function()use($runtimeFixture,$installPack){
    [$server,$node]=$runtimeFixture();$edited=false;
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(function($request)use($server,&$edited){if(str_ends_with($request->url(),'/sync')&&!$edited){$edited=true;$variable=$server->egg->variables()->where('env_variable','VERSION')->firstOrFail();App\Models\ServerVariable::where('server_id',$server->id)->where('variable_id',$variable->id)->update(['variable_value'=>'1.21.4']);return Http::response('',503,['User-Agent'=>'Pelican Wings/v1.0.0 (id:'.$server->node->daemon_token_id.')']);}return Http::response('',204);});
    $op=$installPack($server);check($op->status==='needs-reconciliation'&&$op->server_slot===$server->id);
    check(IByte\MinecraftManager\Support\RuntimeVariables::environment($server->fresh())['VERSION']==='1.21.4');
    $previous=$op->plan['previous_startup'];$service=app(App\Services\Servers\StartupModificationService::class)->setUserLevel(User::USER_LEVEL_ADMIN);
    $service->handle($server->fresh(),IByte\MinecraftManager\Support\RuntimeVariables::forNativeUpdate($server->fresh(),$previous));
    check(Illuminate\Support\Facades\Artisan::call('minecraft-manager:reconcile',['operation'=>$op->id,'--worker-stopped'=>true,'--restore-files'=>true])===0);
    check($op->fresh()->server_slot===null&&$node->data['server.jar']==='old-runtime');
});
installTest('runtime variable changes invalidate a queued review before any download',function()use($runtimeFixture,$owner){
    [$server,$node]=$runtimeFixture();$reviews=app(ReviewService::class);$op=$reviews->create($owner,$server,['kind'=>'software','provider'=>'mcjars','project'=>'FABRIC','release'=>'fabric-build','version'=>'1.21.1']);$reviews->queue($owner,$server,$op->id,true);
    $variable=$server->egg->variables()->where('env_variable','VERSION')->firstOrFail();App\Models\ServerVariable::updateOrCreate(['server_id'=>$server->id,'variable_id'=>$variable->id],['variable_value'=>'1.21.4']);
    app()->call([new CheckReview($op->id,true),'handle']);$op->refresh();check($op->status==='blocked'&&$op->error_code==='stale_plan'&&$op->server_slot===null&&$node->downloads===[]&&$node->data['server.jar']==='old-runtime');
});
installTest('ambiguous CurseForge primary loaders cannot silently choose a runtime',function()use($runtimeFixture,$installPack,$http,$download,$cfManifest){
    foreach([[],[['id'=>'fabric-0.16.10','primary'=>true],['id'=>'fabric-0.17.0','primary'=>true]]] as $loaders){
        [$server,$node]=$runtimeFixture();$manifest=$cfManifest;$manifest['minecraft']['modLoaders']=$loaders?:[['id'=>'fabric-0.16.10','primary'=>false]];
        cfFixture($http,$download,'14001','14002','ambiguous.zip',zipBytes(['manifest.json'=>json_encode($manifest)]),4471);
        $op=$installPack($server,'curseforge','14001','14002');check($op->status==='failed'&&$op->error_code==='pack_runtime_unknown'&&$node->data['server.jar']==='old-runtime'&&$op->server_slot===null);
    }
});
installTest('a missing exact pack loader never falls back to another build',function()use($runtimeFixture,$installPack,$http,$build){
    $key='mcjars/api/v3/builds/types/FABRIC/versions/1.21.1';$original=$http->responses[$key];
    try{[$server,$node]=$runtimeFixture();$http->responses[$key]=['builds'=>['data'=>[[...$build,'project_version_id'=>'0.17.0']],'total'=>1]];
        $op=$installPack($server);check($op->status==='failed'&&$op->error_code==='exact_release_unavailable'&&$node->data['server.jar']==='old-runtime'&&$op->server_slot===null);
    }finally{$http->responses[$key]=$original;}
});

installTest('plugin entry-point Java requirements fail before replacing any server file',function()use($runtimeFixture,$owner,$http,$download){
    [$server,$node]=$runtimeFixture();$before=$node->data;
    IByte\MinecraftManager\Models\Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'PAPER','version'=>'1.21.1','loader'=>'paper']]);
    $bytes=zipBytes(['plugin.yml'=>"name: Fixture\nversion: '1'\nmain: fixture.Main\n",'fixture/Main.class'=>"\xca\xfe\xba\xbe".pack('nn',0,69)]);
    modFixture($http,$download,'java-plugin','java25','java-plugin.jar',$bytes);$http->responses['modrinth/v2/version/java25']['loaders']=['bukkit'];
    $reviews=app(ReviewService::class);$op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'java-plugin','release'=>'java25','type'=>'plugin']);
    check($op->plan['executable']);$reviews->queue($owner,$server,$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);$op->refresh();
    check($op->status==='failed'&&$op->error_code==='plugin_java_incompatible'&&$op->server_slot===null&&$node->data===$before);
    check(str_contains($op->plan['failure']['requirement'],'Java 25')&&str_contains($op->plan['failure']['requirement'],'Java 21'));
});
installTest('a Java 21 plugin with an optional Java 25 multi-release entry installs on Paper 21',function()use($runtimeFixture,$owner,$http,$download){
    [$server,$node]=$runtimeFixture();IByte\MinecraftManager\Models\Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'PAPER','version'=>'1.21.1','loader'=>'paper']]);
    $bytes=zipBytes(['plugin.yml'=>"name: Fixture\nversion: '1'\nmain: fixture.Main\n",'fabric.mod.json'=>'{"environment":"client"}', 'META-INF/MANIFEST.MF'=>"Manifest-Version: 1.0\r\nMulti-Release: true\r\n\r\n",'fixture/Main.class'=>"\xca\xfe\xba\xbe".pack('nn',0,65),'META-INF/versions/25/fixture/Main.class'=>"\xca\xfe\xba\xbe".pack('nn',0,69)]);
    modFixture($http,$download,'java-plugin-ok','java21','java-plugin-ok.jar',$bytes);$http->responses['modrinth/v2/version/java21']['loaders']=['bukkit'];
    $reviews=app(ReviewService::class);$op=$reviews->create($owner,$server,['kind'=>'package','provider'=>'modrinth','project'=>'java-plugin-ok','release'=>'java21','type'=>'plugin']);
    $reviews->queue($owner,$server,$op->id,true);app()->call([new CheckReview($op->id,true),'handle']);check($op->fresh()->status==='succeeded',(string)$op->fresh()->error_code);
    check($node->data['plugins/java-plugin-ok.jar']===$bytes&&$node->data['server.jar']==='old-runtime');
});
installTest('entry-point checks use the selected platform descriptor and reject preview or unsafe metadata',function(){
    $local=tempnam(sys_get_temp_dir(),'imm-plugin');$guard=new IByte\MinecraftManager\Support\PluginJava;
    try{
        file_put_contents($local,zipBytes(['plugin.yml'=>"main: fixture.Main\n",'paper-plugin.yml'=>"main: fixture.Main\nbootstrapper: fixture.Bootstrap\n",'fixture/Main.class'=>"\xca\xfe\xba\xbe".pack('nn',0,65),'fixture/Bootstrap.class'=>"\xca\xfe\xba\xbe".pack('nn',0,69)]));
        check($guard->check($local,'spigot',21)===21);rejects(fn()=>$guard->check($local,'paper',21),'plugin_java_incompatible');rejects(fn()=>$guard->check($local,'folia',21),'plugin_platform_incompatible');
        file_put_contents($local,zipBytes(['plugin.yml'=>"main: fixture.Main\nfolia-supported: true\n",'fixture/Main.class'=>"\xca\xfe\xba\xbe".pack('nn',0,65)]));check($guard->check($local,'folia',21)===21);
        file_put_contents($local,zipBytes(['plugin.yml'=>"main: fixture.Main\n",'fixture/Main.class'=>"\xca\xfe\xba\xbe".pack('nn',65535,69)]));rejects(fn()=>$guard->check($local,'paper',25),'plugin_java_preview');
        file_put_contents($local,zipBytes(['plugin.yml'=>"main: ../escape\n"]));rejects(fn()=>$guard->check($local,'paper',21),'invalid_plugin_descriptor');
    }finally{unlink($local);}
});
$actionFixture = static function()use($runtimeFixture,$owner,$http,$download):array{
    [$server,$node]=$runtimeFixture();
    IByte\MinecraftManager\Models\Setting::updateOrCreate(['key'=>'runtime-'.$server->id],['value'=>['software'=>'FABRIC','version'=>'1.21.1','loader'=>'fabric']]);
    $hits=[];
    foreach(['click-a','click-b'] as $project){
        modFixture($http,$download,$project,$project.'-1',$project.'.jar',$project.'-bytes');
        $http->responses['modrinth/v2/project/'.$project.'/version']=[['id'=>$project.'-1','name'=>$project.' release','version_type'=>'release','game_versions'=>['1.21.1'],'loaders'=>['fabric']]];
        $hits[]=['project_id'=>$project,'title'=>$project,'description'=>'Action fixture','server_side'=>'required'];
    }
    $http->responses['modrinth/v2/search']=['hits'=>$hits,'total_hits'=>2];
    Illuminate\Support\Facades\Cache::setDefaultDriver('array');
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('server'));Filament\Facades\Filament::setTenant($server);
    $page=Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Filament\Server\MinecraftManager::class)->call('switchArea','packages')->assertSet('error',null);
    return [$server,$node,$page];
};
installTest('one-click Install dispatches and executes the normal installer without a review screen',function()use($actionFixture){
    [$server,$node,$page]=$actionFixture();
    $page->call('inspectProject','click-a')->set('selectedRelease','click-a-1')->call('installSelectedRelease')->assertSet('error',null)->assertSet('projectOpen',false)->assertDontSee('Installation review');
    $op=Operation::where('server_slot',$server->id)->firstOrFail();check($op->status==='queued'&&$op->selection['intent']==='install');
    app()->call([new CheckReview($op->id,true),'handle']);check($op->fresh()->status==='succeeded',(string)$op->fresh()->error_code);
    check($node->data['mods/click-a.jar']==='click-a-bytes'&&$node->data['world/level.dat']==='keep-world'&&$op->fresh()->server_slot===null);
});
installTest('Add to queue retains two mods across reload and one Install executes their batch',function()use($actionFixture,$owner){
    [$server,$node,$page]=$actionFixture();
    foreach(['click-a','click-b'] as $project){$page->call('inspectProject',$project)->set('selectedRelease',$project.'-1')->call('addSelectedRelease')->assertSet('error',null)->assertSet('projectOpen',false);}
    check(count($page->get('installQueue'))===2&&!Operation::where('server_slot',$server->id)->exists()&&!isset($node->data['mods/click-a.jar']));
    // Pending choices are not expiring review IDs. Recreate their metadata when starting.
    Operation::where('server_id',$server->id)->where('status','planned')->update(['expires_at'=>now()->subMinute()]);
    $page=Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Filament\Server\MinecraftManager::class);
    check(count($page->get('installQueue'))===2);
    $page->call('installQueued')->assertSet('error',null)->assertSet('installQueue',[]);
    $op=Operation::where('server_slot',$server->id)->firstOrFail();check(count($op->selection['items'])===2);
    app()->call([new CheckReview($op->id,true),'handle']);check($op->fresh()->status==='succeeded',(string)$op->fresh()->error_code);
    check($node->data['mods/click-a.jar']==='click-a-bytes'&&$node->data['mods/click-b.jar']==='click-b-bytes'&&$node->data['world/level.dat']==='keep-world');
    check(Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Filament\Server\MinecraftManager::class)->get('installQueue')===[]);
});
installTest('direct Install still rejects a running server before any file mutation',function()use($actionFixture){
    [$server,$node,$page]=$actionFixture();$before=$node->data;$node->running=true;
    $page->call('inspectProject','click-a')->set('selectedRelease','click-a-1')->call('installSelectedRelease')->assertSet('error',null);
    $op=Operation::where('server_slot',$server->id)->firstOrFail();app()->call([new CheckReview($op->id,true),'handle']);
    check($op->fresh()->status==='blocked'&&$op->fresh()->error_code==='server_must_be_stopped'&&$op->fresh()->server_slot===null&&$node->data===$before);
});
foreach(['download','initial verification','verification'] as $phase){installTest('active cancellation during '.$phase.' holds the slot until original files are restored',function()use($batchFixture,$owner,$phase){
    [$server,$node,$reviews,$ids]=$batchFixture();$node->data=['mods/batch-a.jar'=>'old-a','world/level.dat'=>'world'];$before=$node->data;
    $op=$reviews->createBatch($owner,$server,$ids);$reviews->queue($owner,$server,$op->id,true);
    $cancel=function()use($reviews,$owner,$server,$op,$node,$phase){
        $node->onPull=$node->onInitialVerify=$node->onVerify=null;$reviews->cancel($owner,$server,$op->id);
        check($op->fresh()->status==='cancelling'&&$op->fresh()->server_slot===$server->id);
        rejects(fn()=>$reviews->releaseInstall($owner,$server,$op->id),'recovery_not_available');
    };
    if($phase==='download'){$node->onPull=$cancel;}elseif($phase==='initial verification'){$node->onInitialVerify=$cancel;}else{$node->onVerify=$cancel;}
    app()->call([new CheckReview($op->id,true),'handle']);$op->refresh();
    check($op->status==='cancelled'&&$op->server_slot===null&&!array_diff_assoc($node->data,$before)&&!array_diff_assoc($before,$node->data),$op->status.' '.($op->error_code??'').' '.json_encode(array_diff_assoc($node->data,$before)));
});}
installTest('old blocked installation can keep files and unlock without deleting recovery copies',function()use($workspaceFixture,$owner){
    [$server,$node,$op,$workspace]=$workspaceFixture();$node->data=['mods/unknown.jar'=>'old','world/level.dat'=>'world'];
    $workspace->reserve(['mods/unknown.jar'=>hash('sha256','installed')]);$node->data['mods/unknown.jar']='operator-edit';
    foreach(['mods/unknown.jar','mods/unrelated.jar'] as $path){Illuminate\Support\Facades\DB::table('imm_inventory')->insert(['server_id'=>$server->id,'path'=>$path,'metadata'=>'{}','created_at'=>now(),'updated_at'=>now()]);}
    $op->update(['status'=>'needs-reconciliation','server_slot'=>$server->id,'updated_at'=>now()->subHours(11)]);$before=$node->data;
    app(ReviewService::class)->releaseInstall($owner,$server,$op->id);$op->refresh();
    check($op->status==='cancelled'&&$op->step==='kept-files-and-unlocked'&&$op->server_slot===null&&$node->data===$before);
    check(is_file($workspace->directory().'/'.hash('sha256','mods/unknown.jar').'.bin'));
    check(!Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','mods/unknown.jar')->exists());
    check(Illuminate\Support\Facades\DB::table('imm_inventory')->where('server_id',$server->id)->where('path','mods/unrelated.jar')->exists());
    rejects(fn()=>app(ReviewService::class)->releaseInstall($owner,$server,$op->id),'recovery_not_available');
    $next=app(ReviewService::class)->create($owner,$server,['kind'=>'package',...$op->selection['items'][0]]);
    app(ReviewService::class)->queue($owner,$server,$next->id,true);app()->call([new CheckReview($next->id,true),'handle']);
    check($next->fresh()->status==='succeeded'&&$next->fresh()->server_slot===null&&$node->data['mods/unknown.jar']==='operator-edit'&&$node->data['world/level.dat']==='world');
    // Test-only private backup cleanup; the production unlock deliberately retains it.
    $node->data['mods/unknown.jar']='installed';$workspace->rollback();$workspace->cleanup(true);
});
installTest('unlock refuses a live worker, recent legacy install and a running server',function()use($workspaceFixture,$owner){
    [$server,$node,$op,$workspace]=$workspaceFixture();$op->update(['status'=>'needs-reconciliation','server_slot'=>$server->id]);$reviews=app(ReviewService::class);
    rejects(fn()=>$reviews->releaseInstall($owner,$server,$op->id),'recovery_worker_busy');
    $plan=$op->plan;$plan['worker_lock_version']=1;$op->update(['plan'=>$plan]);
    $lock=Illuminate\Support\Facades\Cache::lock('minecraft-manager-worker:'.$op->id,1860);check($lock->get());
    try{rejects(fn()=>$reviews->releaseInstall($owner,$server,$op->id),'recovery_worker_busy');}finally{$lock->release();}
    $node->running=true;rejects(fn()=>$reviews->releaseInstall($owner,$server,$op->id),'server_must_be_stopped');check($op->fresh()->server_slot===$server->id);
    $node->running=false;$reviews->releaseInstall($owner,$server,$op->id);check($op->fresh()->server_slot===null);
});
installTest('worker timeout while cancelling retains recovery when a journal exists',function()use($workspaceFixture){
    [$server,$node,$op,$workspace]=$workspaceFixture();$workspace->reserve(['mods/new.jar'=>hash('sha256','new')]);$op->update(['status'=>'cancelling','server_slot'=>$server->id]);
    (new CheckReview($op->id,true))->failed(new RuntimeException('timeout'));check($op->fresh()->status==='needs-reconciliation'&&$op->fresh()->server_slot===$server->id);
    $op->update(['status'=>'cancelled','server_slot'=>null]);$workspace->cleanup(true);
});
installTest('native status component unlocks an old failure and stops displaying active verification',function()use($workspaceFixture,$owner){
    [$server,$node,$op,$workspace]=$workspaceFixture();$op->update(['status'=>'needs-reconciliation','server_slot'=>$server->id,'error_code'=>'installation_failed','updated_at'=>now()->subHours(11)]);
    $feed=Livewire\Livewire::actingAs($owner)->test(IByte\MinecraftManager\Livewire\ActivityFeed::class,['serverId'=>$server->id,'serverUuid'=>$server->uuid]);
    $feed->assertSee('Keep files and unlock')->assertDontSee('wire:poll.2s',false)->assertDontSee('<progress',false);
    $feed->call('releaseInstall',$op->id)->assertSet('error',null)->assertSee('Installations unlocked. Current files were kept.');
    check($op->fresh()->server_slot===null&&$op->fresh()->error_code===null);
});
installTest('an unrelated actor cannot release another servers blocked operation',function()use($workspaceFixture){
    [$server,$node,$op,$workspace]=$workspaceFixture();$op->update(['status'=>'needs-reconciliation','server_slot'=>$server->id,'updated_at'=>now()->subHours(11)]);$denied=false;
    try{app(ReviewService::class)->releaseInstall(User::factory()->create(),$server,$op->id);}catch(Symfony\Component\HttpKernel\Exception\HttpException $error){$denied=$error->getStatusCode()===403;}
    check($denied&&$op->fresh()->server_slot===$server->id);$op->update(['status'=>'cancelled','server_slot'=>null]);
});
file_put_contents($root.'/docs/evidence/installs.json', json_encode(['passed' => $passed, 'scope' => 'Real native Panel DB, permissions, queue dispatch, Livewire Install/queue actions and startup services; deterministic providers/artifacts, in-memory node, native HTTP fake for binary transfer; no live Wings or game startup'], JSON_PRETTY_PRINT)."\n");
echo "RESULT $passed install contract tests passed; no live Wings/game startup.\n";
