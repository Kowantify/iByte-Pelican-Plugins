<?php

declare(strict_types=1);

// Test-only provider, loaded solely in the copied scheduler database. Never packaged.
if (getenv('IMM_TEST_SCHEDULER') !== '1' || getenv('DB_DATABASE') !== 'imm-scheduler.sqlite') {
    throw new RuntimeException('Scheduler fixture requires its isolated environment.');
}

require_once dirname(__DIR__).'/plugin/tests/bootstrap.php';

final class SchedulerDownload extends IByte\MinecraftManager\Support\ArtifactDownloader
{
    public function download(string $url, string $destination, ?int $size, array $hashes = []): array
    {
        $bytes = 'scheduler-test-artifact';
        check($url === 'https://cdn.modrinth.com/scheduler.jar' && $size === strlen($bytes));
        foreach ($hashes as $algorithm => $hash) { check(hash($algorithm, $bytes) === $hash); }
        file_put_contents($destination, $bytes);
        return ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
}

final class SchedulerNode extends IByte\MinecraftManager\Operations\NodeFiles
{
    private function path(string $path): string
    {
        return dirname(__DIR__).'/.build/scheduler-node/'.IByte\MinecraftManager\Support\Input::path($path);
    }
    public function assertStopped(): void {}
    public function mkdir(string $path): void { $target = $this->path($path); if (!is_dir($target)) { mkdir($target, 0700, true); } }
    public function exists(string $path): bool { return file_exists($this->path($path)); }
    public function hashOrNull(string $path): ?string { return is_file($this->path($path)) ? hash_file('sha256',$this->path($path)) : null; }
    public function entries(array $paths, ?callable $guard = null, ?callable $found = null): array
    {
        $entries=[];foreach($paths as $path){$file=$this->path($path);if(file_exists($file)){$entries[$path]=['name'=>basename($path),'file'=>is_file($file),'directory'=>is_dir($file),'symlink'=>is_link($file)];}}return $entries;
    }
    public function assertRegular(string $path): void { check(is_file($this->path($path)) && !is_link($this->path($path))); }
    public function removeEmptyDirectory(string $path): void { $target=$this->path($path); if(is_dir($target)&&count(scandir($target))===2){rmdir($target);} }
    public function pruneEmptyTree(string $path, int $depth = 0): void
    {
        $target=$this->path($path);if($depth>32||!is_dir($target)||is_link($target)){return;}
        foreach(new FilesystemIterator($target) as $file){if($file->isDir()&&!$file->isLink()){$this->pruneEmptyTree($path.'/'.$file->getFilename(),$depth+1);}}
        $this->removeEmptyDirectory($path);
    }
    public function upload(string $path, string $local): void { $this->mkdir(dirname($path)); copy($local, $this->path($path)); }
    public function pullMany(array $downloads, callable $progress): void
    {
        foreach ($downloads as $index=>$download) {
            $this->mkdir(dirname($download['path']));
            app(SchedulerDownload::class)->download($download['url'],$this->path($download['path']),$download['size'],$download['hashes']);
            $progress(['phase'=>'download','files'=>$index+1,'total'=>count($downloads),'file'=>$download['path'],'fraction'=>($index+1)/count($downloads)]);
        }
    }
    public function verifiedRead(string $path, ?int $size, array $hashes = [], ?string $destination = null): array
    {
        $local=$this->path($path); check($size===null || filesize($local)===$size);
        foreach($hashes as $algorithm=>$hash) { check(hash_file($algorithm,$local)===$hash); }
        if($destination) { copy($local,$destination); }
        return ['size'=>filesize($local),'sha256'=>hash_file('sha256',$local)];
    }
    public function verifiedReadMany(array $reads, callable $completed, callable $heartbeat): void
    {
        foreach($reads as $path=>$item){
            $heartbeat();
            try{$completed($path,$this->verifiedRead($path,$item['size']??null,$item['hashes']??[],$item['destination']??null));}
            finally{if(isset($item['destination'])&&is_file($item['destination'])){unlink($item['destination']);}}
        }
    }
    public function hash(string $path, int $limit = 536870912): string { return hash_file('sha256', $this->path($path)); }
    public function move(string $from, string $to): void { $this->mkdir(dirname($to)); check(!file_exists($this->path($to))); rename($this->path($from), $this->path($to)); }
    public function moveMany(array $moves, ?callable $progress = null): void { foreach($moves as $move){$this->move($move['from'],$move['to']);} }
    public function removeMany(array $paths): void { foreach($paths as $path){$this->remove($path);} }
    public function verifyMany(array $journal, callable $progress): void
    {
        $done=0;foreach($journal as $path=>$item){if($item['new']===null?$this->exists($path):$this->hash($path)!==$item['new']){throw new IByte\MinecraftManager\Support\Failure('node_hash_mismatch');}$progress(++$done,count($journal),$path);}
    }
    public function remove(string $path): void
    {
        $target=$this->path($path);
        if(is_dir($target)) {
            $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($iterator as $item) { $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname()); }
            rmdir($target);
        } else { unlink($target); }
    }
}

final class SchedulerFixtureProvider extends Illuminate\Support\ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $bytes = 'scheduler-test-artifact';
            $this->app->instance(IByte\MinecraftManager\Catalog\JsonTransport::class, new FixtureTransport([
                'modrinth/v2/project/scheduler' => ['id' => 'scheduler', 'project_type' => 'mod', 'server_side' => 'required'],
                'modrinth/v2/version/scheduler-v1' => ['id' => 'scheduler-v1', 'project_id' => 'scheduler', 'name' => 'Scheduler test',
                    'version_number' => '1', 'version_type' => 'release', 'game_versions' => ['1.21.1'], 'loaders' => ['fabric'], 'dependencies' => [],
                    'files' => [['filename' => 'scheduler-test.jar', 'url' => 'https://cdn.modrinth.com/scheduler.jar', 'primary' => true,
                        'size' => strlen($bytes), 'hashes' => ['sha512' => hash('sha512', $bytes)]]]],
            ]));
            $this->app->bind(IByte\MinecraftManager\Support\ArtifactDownloader::class, SchedulerDownload::class);
            $this->app->bind(IByte\MinecraftManager\Operations\NodeFiles::class, fn ($app, $parameters) => new SchedulerNode($parameters['server']));
        });
    }
}

return SchedulerFixtureProvider::class;
