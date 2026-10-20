<?php

declare(strict_types=1);

// Read-only provider investigation. Artifacts stay in .build; no node contacted.
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$http = app(IByte\MinecraftManager\Catalog\JsonTransport::class);
$work = $root.'/.build/reported-provider-artifacts';
if (!is_dir($work)) { mkdir($work, 0700, true); }
$result = ['scope' => 'Read-only live exact metadata/artifact inspection; no node contact or execution'];
$body = $http->get('modrinth', '/v2/version/d5ddUdiB');
$result['modrinth'] = ['project' => $body['project_id'], 'release' => $body['id'], 'title' => $body['name'], 'dependencies' => $body['dependencies'], 'files' => array_column($body['files'], 'filename')];
echo 'Observed exact FerriteCore release with ', count($body['files']), ' artifact(s).', "\n";
$key = getenv('IMM_TEST_CF_KEY');
if ($key) {
    $settings = app(IByte\MinecraftManager\Support\Settings::class);
    $previous = $settings->credential('curseforge');
    $previousPolicy = $settings->values();
    try {
        $settings->replaceCredential('curseforge', $key); unset($key); putenv('IMM_TEST_CF_KEY');
        $settings->save(['providers'=>['mcjars','modrinth','curseforge']]);
        $list = $http->get('curseforge', '/v1/mods/434113/files', ['gameVersion' => '1.20.1', 'pageSize' => 50])['data'];
        $file = collect($list)->first(fn ($file) => str_contains($file['displayName'], 'V1.4')) ?? throw new RuntimeException('Reported release not found');
        $result['curseforge'] = ['project' => '434113', 'release' => $file['id'], 'title' => $file['displayName'], 'server_pack_id' => $file['serverPackFileId'] ?? null];
        $artifact = app(IByte\MinecraftManager\Catalog\Catalog::class)->provider('curseforge')->artifact('434113', (string) $file['id']);
        $archive = $work.'/odyssey.zip';
        if (!is_file($archive)) { app(IByte\MinecraftManager\Support\ArtifactDownloader::class)->download($artifact['url'], $archive, $artifact['size'], $artifact['hashes']); }
        $zip = new ZipArchive; $zip->open($archive, ZipArchive::RDONLY);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR); $zip->close();
        $result['curseforge']['manifest'] = $manifest;
        $nonJars = []; $checked = 0;
        if (in_array('--prepare', $argv, true)) {
            $settings->save(['providers'=>['mcjars','modrinth','curseforge']]);
            $release=app(IByte\MinecraftManager\Catalog\Catalog::class)->exact('curseforge','434113',(string)$file['id']);
            try {
                $prepared=app(IByte\MinecraftManager\Operations\InstallPreparer::class)->prepare($release,
                    ['kind'=>'pack','provider'=>'curseforge','project'=>'434113','release'=>(string)$file['id'],'type'=>'modpack'],
                    new IByte\MinecraftManager\Models\Profile,$work);
                $result['curseforge']['prepared']=['files'=>count($prepared['files']),'skipped'=>$prepared['skipped'],'runtime'=>$prepared['runtime']];
                echo 'Prepared reported Odyssey release: ',count($prepared['files']),' files; ',count($prepared['skipped'])," client assets skipped.\n";
            } catch (IByte\MinecraftManager\Support\Failure $failure) {
                $result['curseforge']['preparation_blocker']=['code'=>$failure->reason,'context'=>$failure->context];
                echo 'Preparation blocked: ',$failure->reason,"\n";
            }
        } else {
        foreach ($manifest['files'] as $entry) {
            $meta = $http->get('curseforge', '/v1/mods/'.$entry['projectID'].'/files/'.$entry['fileID'])['data'];
            if (!str_ends_with(strtolower($meta['fileName']), '.jar')) {
                $project = $http->get('curseforge', '/v1/mods/'.$entry['projectID'])['data'];
                $nonJars[] = ['project' => $entry['projectID'], 'release' => $entry['fileID'], 'name' => $meta['fileName'], 'class_id' => $project['classId'], 'required' => $entry['required'] ?? true];
                echo 'Observed non-JAR manifest item: ', $meta['fileName'], ' (class ', $project['classId'], ')', "\n";
            }
            if (++$checked % 50 === 0) { echo "Inspected $checked manifest entries.\n"; }
        }
        $result['curseforge']['non_jar_files'] = $nonJars;
        }
    } finally { $settings->replaceCredential('curseforge', $previous); $settings->save($previousPolicy); }
} else { echo "CurseForge inspection skipped: credential absent.\n"; }
file_put_contents($root.'/docs/evidence/'.(in_array('--prepare',$argv,true)?'reported-pack-preparation':'reported-provider-failures').'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
