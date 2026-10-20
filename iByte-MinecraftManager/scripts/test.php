<?php

declare(strict_types=1);

require dirname(__DIR__) . '/plugin/tests/bootstrap.php';

use IByte\MinecraftManager\Catalog\CurseForge;
use IByte\MinecraftManager\Catalog\McJars;
use IByte\MinecraftManager\Catalog\Modrinth;
use IByte\MinecraftManager\Modpacks\ArchiveInspector;
use IByte\MinecraftManager\Modpacks\ManagedDiff;
use IByte\MinecraftManager\Modpacks\Mrpack;
use IByte\MinecraftManager\Operations\PlanBuilder;
use IByte\MinecraftManager\Packages\DependencyResolver;
use IByte\MinecraftManager\Support\Failure;
use IByte\MinecraftManager\Support\Input;
use IByte\MinecraftManager\Support\Integrity;
use IByte\MinecraftManager\Support\UrlPolicy;

$tests = [];
$tests['generated operation prefixes preserve valid deep content paths without relaxing provider paths'] = function (): void {
    $content='config/'.str_repeat('nested/',14).'file.json';
    $stage='.minecraft-manager/staging/12345678-1234-1234-1234-123456789abc/remote/expanded/2/'.$content;
    check(Input::path($content)===$content && Input::nodePath($stage)===$stage);
    rejects(fn()=>Input::path($stage),'unsafe_path');
    rejects(fn()=>Input::nodePath($stage.'/../escape'),'unsafe_path');
    rejects(fn()=>Input::nodePath('config/'.str_repeat('nested/',16).'file.json'),'unsafe_path');
    rejects(fn()=>Input::nodePath(str_replace('12345678-1234-1234-1234-123456789abc','invalid',$stage)),'unsafe_path');
};
$tests['future and snapshot version identifiers retained'] = function (): void {
    foreach (['26.1', '26.1-pre-1', '26.1-rc-1', '25w40a', 'b1.7.3', '1.14 Pre-Release 5', '3.4.0-SNAPSHOT'] as $id) { check(Input::id($id) === $id); }
};
$tests['URL identifiers and traversal rejected'] = function (): void {
    foreach (['https://evil.test/a', '../x', '', '.', "foo\0bar"] as $id) { rejects(fn () => Input::id($id), 'invalid_identifier'); }
};
foreach (['../x', '/root', 'C:/x', '\\\\host\\x', 'mods/../x', 'mods//a.jar', 'mods/a.jar ', 'NUL.txt', 'mods/a:stream', "a\0b", str_repeat('x/', 17) . 'a'] as $index => $path) {
    $tests['unsafe path ' . $index] = fn () => rejects(fn () => Input::path($path), 'unsafe_path');
}
$tests['exact HTTPS host allowlist'] = function (): void {
    check(UrlPolicy::host('https://api.modrinth.com/v2/search', ['api.modrinth.com']) === 'api.modrinth.com');
    foreach (['http://api.modrinth.com/x', 'https://user:pass@api.modrinth.com/x', 'https://api.modrinth.com:22/x', 'https://api.modrinth.com/#x'] as $url) {
        rejects(fn () => UrlPolicy::host($url, ['api.modrinth.com']), 'unsafe_url');
    }
    rejects(fn () => UrlPolicy::host('https://api.modrinth.com.evil.test/x', ['api.modrinth.com']), 'unapproved_host');
};
$tests['private reserved metadata and transition IPs rejected'] = function (): void {
    foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '192.0.2.1', '198.18.0.1', '224.0.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::'] as $ip) { check(!UrlPolicy::publicIp($ip), $ip); }
    check(UrlPolicy::publicIp('1.1.1.1')); check(UrlPolicy::publicIp('2606:4700:4700::1111'));
};
$tests['mixed DNS answers cannot rebind transport'] = function (): void {
    rejects(fn () => UrlPolicy::pinnedAddress(['1.1.1.1', '127.0.0.1']), 'unsafe_address');
    rejects(fn () => UrlPolicy::pinnedAddress([]), 'dns_failed');
    check(UrlPolicy::pinnedAddress(['1.1.1.1', '8.8.8.8']) === '1.1.1.1');
};
$tests['integrity checks strongest supplied checksum and exact size'] = function (): void {
    check(Integrity::verify('test', ['sha512' => hash('sha512', 'test'), 'sha1' => str_repeat('0', 40)], 4) === 'sha512');
    rejects(fn () => Integrity::verify('test', ['sha512' => str_repeat('0', 128)], 4), 'hash_mismatch');
    rejects(fn () => Integrity::verify('test', ['sha512' => hash('sha512', 'test')], 3), 'size_mismatch');
    rejects(fn () => Integrity::verify('test', ['sha512' => 'bad', 'sha1' => hash('sha1', 'test')], 4), 'invalid_hash');
    rejects(fn () => Integrity::verify('test', ['sha1' => hash('sha1', 'test')], 4), 'weak_integrity_policy');
    check(Integrity::verify('test', ['sha1' => hash('sha1', 'test')], 4, true) === 'sha1');
};
$tests['full MCJars discovery retains new unknown types'] = function (): void {
    $http = new FixtureTransport(['mcjars/api/v2/types' => ['types' => ['recommended' => ['VANILLA' => ['name' => 'Vanilla']], 'future' => ['NEW_PLATFORM' => ['name' => 'New']]]]]);
    $types = (new McJars($http))->types();
    check(array_column($types, 'id') === ['VANILLA', 'NEW_PLATFORM']);
    check(!$types[1]['installable']);
};
$tests['MCJars pagination and provider order retained'] = function (): void {
    $http = new FixtureTransport(['mcjars/api/v3/builds/types/FABRIC/versions' => ['versions' => ['page' => 2, 'total' => 21, 'data' => [['id' => '26.1'], ['id' => '25w40a']]]]]);
    $data = (new McJars($http))->versions('FABRIC', 2);
    check($data['total'] === 21 && $data['items'][1]['id'] === '25w40a'); check($http->calls[0][2]['page'] === 2);
};
$build = ['uuid' => 'build-123', 'type' => 'FABRIC', 'version_id' => '26.1', 'project_version_id' => null,
    'name' => 'Fabric', 'experimental' => false, 'installation' => [[['type' => 'download', 'url' => 'https://example.test/a', 'file' => 'server.jar', 'size' => 10]]]];
$tests['exact build mismatch and latest aliases cannot fall back'] = function () use ($build): void {
    $mc = new McJars(new FixtureTransport(['mcjars/api/v3/builds/build-123' => ['build' => $build, 'version' => ['java' => 25, 'type' => 'RELEASE']]]));
    check($mc->exact('FABRIC', '26.1', 'build-123')['java'] === 25);
    rejects(fn () => $mc->exact('FABRIC', '26.2', 'build-123'), 'exact_release_unavailable');
    rejects(fn () => $mc->exact('FABRIC', '26.1', 'latest'), 'exact_selection_required');
};
$tests['unknown MCJars recipe stays gated'] = function () use ($build): void {
    $build['installation'] = [[['type' => 'execute', 'command' => 'evil']]];
    $mc = new McJars(new FixtureTransport(['mcjars/api/v3/builds/build-123' => ['build' => $build, 'version' => ['java' => 25, 'type' => 'RELEASE']]]));
    check($mc->exact('FABRIC', '26.1', 'build-123')['reason'] === 'unsupported_recipe');
};
$tests['modrinth exact IDs bind project and release'] = function (): void {
    $http = new FixtureTransport(['modrinth/v2/project/project' => ['id' => 'project', 'server_side' => 'required', 'project_type' => 'mod'],
        'modrinth/v2/version/version' => ['id' => 'version', 'project_id' => 'other', 'files' => []]]);
    rejects(fn () => (new Modrinth($http))->exact('project', 'version'), 'exact_release_unavailable');
};
$tests['Modrinth legacy mod project can publish plugin releases'] = function (): void {
    $http = new FixtureTransport(['modrinth/v2/project/p' => ['id' => 'p', 'project_type' => 'mod', 'server_side' => 'required'],
        'modrinth/v2/version/v' => ['id' => 'v', 'project_id' => 'p', 'version_type' => 'release', 'game_versions' => ['26.1'], 'loaders' => ['paper', 'spigot'], 'dependencies' => [],
            'files' => [['filename' => 'plugin.jar', 'size' => 4, 'hashes' => ['sha512' => hash('sha512', 'test')], 'primary' => true]]]]);
    $release = (new Modrinth($http))->exact('p', 'v');
    check($release['project_type'] === 'plugin' && $release['project_types'] === ['plugin']);
    check((new PlanBuilder)->build($release, ['kind' => 'package', 'type' => 'plugin', 'version' => '26.1', 'loader' => 'paper'])['executable']);
};
$tests['Modrinth release pages preserve access to later exact versions'] = function (): void {
    $versions = array_map(fn ($n) => ['id' => 'v'.$n, 'name' => 'Version '.$n, 'version_type' => 'release', 'loaders' => ['paper'], 'game_versions' => ['26.1']], range(1, 100));
    $adapter = new Modrinth(new FixtureTransport(['modrinth/v2/project/p/version' => $versions]));
    $page = $adapter->releases('p', ['version' => '', 'loader' => '', 'experimental' => true, 'release_page' => 2]);
    check(count($page) === 50 && $page[0]['id'] === 'v51' && $page[49]['id'] === 'v100');
};
$tests['CurseForge missing API URL resolves only the exact public CDN file with publisher integrity'] = function (): void {
    $http = new FixtureTransport(['curseforge/v1/mods/123' => ['data' => ['id' => 123, 'allowModDistribution' => false]],
        'curseforge/v1/mods/123/files/5052580' => ['data' => ['id'=>5052580,'modId'=>123,'fileName'=>'a file+1.jar','fileLength'=>4,
            'downloadUrl'=>null,'hashes'=>[['algo'=>1,'value'=>hash('sha1','test')]]]]]);
    $adapter=new CurseForge($http);
    $artifact=$adapter->artifact('123','5052580');
    check($artifact['url']==='https://edge.forgecdn.net/files/5052/580/a%20file%2B1.jar');
    check($artifact['hashes']['sha1']===hash('sha1','test') && $artifact['size']===4 && $artifact['source_resolution']==='curseforge-cdn');
    $http->responses['curseforge/v1/mods/123/files/5052580']['data']['hashes']=[];
    rejects(fn ()=>$adapter->artifact('123','5052580'),'strong_integrity_required');
    $http->responses['curseforge/v1/mods/123/files/5052580']['data']['modId']=999;
    rejects(fn ()=>$adapter->artifact('123','5052580'),'exact_release_unavailable');
};
$tests['provider outages and permission errors retain distinct codes'] = function (): void {
    foreach (['rate_limited', 'provider_unavailable', 'provider_forbidden'] as $reason) {
        $http = new FixtureTransport(['modrinth/v2/project/a' => new Failure($reason)]);
        rejects(fn () => (new Modrinth($http))->exact('a', 'b'), $reason);
    }
};
$node = static fn (string $id, array $deps = [], string $file = '') => ['id' => $id, 'project' => 'p-' . $id, 'environment' => 'required', 'dependencies' => $deps,
    'files' => $file ? [['primary' => true, 'name' => $file]] : []];
$dep = static fn (string $id, string $type = 'required') => ['version_id' => $id, 'dependency_type' => $type];
$tests['nested dependencies ordered before parents and optional excluded'] = function () use ($node, $dep): void {
    $graph = ['a' => $node('a', [$dep('b'), $dep('c', 'optional')]), 'b' => $node('b', [$dep('d')]), 'd' => $node('d')];
    $result = (new DependencyResolver)->resolve('a', fn ($id) => $graph[$id]);
    check(array_column($result, 'id') === ['d', 'b', 'a']);
};
$tests['dependency cycle rejected'] = function () use ($node, $dep): void {
    $graph = ['a' => $node('a', [$dep('b')]), 'b' => $node('b', [$dep('a')])];
    rejects(fn () => (new DependencyResolver)->resolve('a', fn ($id) => $graph[$id]), 'dependency_cycle');
};
$tests['dependency versions conflict'] = function () use ($node, $dep): void {
    $graph = ['a' => $node('a', [$dep('b'), $dep('c')]), 'b' => $node('b'), 'c' => $node('c')]; $graph['c']['project'] = 'p-b';
    rejects(fn () => (new DependencyResolver)->resolve('a', fn ($id) => $graph[$id]), 'dependency_conflict');
};
$tests['case colliding dependency filenames rejected'] = function () use ($node, $dep): void {
    $graph = ['a' => $node('a', [$dep('b')], 'Mod.jar'), 'b' => $node('b', [], 'mod.jar')];
    rejects(fn () => (new DependencyResolver)->resolve('a', fn ($id) => $graph[$id]), 'filename_collision');
};
$tests['incompatible and client-only dependencies rejected'] = function () use ($node, $dep): void {
    $graph = ['a' => $node('a', [$dep('b'), $dep('b', 'incompatible')]), 'b' => $node('b')];
    rejects(fn () => (new DependencyResolver)->resolve('a', fn ($id) => $graph[$id]), 'dependency_incompatible');
    $graph['a']['dependencies'] = [$dep('b')]; $graph['b']['environment'] = 'unsupported';
    rejects(fn () => (new DependencyResolver)->resolve('a', fn ($id) => $graph[$id]), 'dependency_environment_unknown');
};
$tests['unresolved required dependency never silently skipped'] = function () use ($node): void {
    rejects(fn () => (new DependencyResolver)->resolve('a', fn () => $node('a', [['project_id' => 'b', 'dependency_type' => 'required']])), 'dependency_exact_release_required');
};
$manifest = ['formatVersion' => 1, 'game' => 'minecraft', 'versionId' => 'pack-1', 'dependencies' => ['minecraft' => '26.1', 'fabric-loader' => '0.18.0'],
    'files' => [['path' => 'mods/a.jar', 'fileSize' => 4, 'hashes' => ['sha512' => hash('sha512', 'test')], 'downloads' => ['https://cdn.example/a.jar']]]];
$tests['mrpack server environment and exact runtime'] = function () use ($manifest): void {
    $manifest['files'][] = [...$manifest['files'][0], 'path' => 'mods/client.jar', 'env' => ['server' => 'unsupported']];
    $pack = (new Mrpack)->parse($manifest); check(count($pack['files']) === 1 && $pack['dependencies']['minecraft'] === '26.1');
};
$tests['mrpack unknown loader and collision rejected'] = function () use ($manifest): void {
    $manifest['dependencies']['custom-runtime'] = '1';
    rejects(fn () => (new Mrpack)->parse($manifest), 'pack_runtime_unknown');
    unset($manifest['dependencies']['custom-runtime']); $manifest['files'][] = [...$manifest['files'][0], 'path' => 'mods/A.jar'];
    rejects(fn () => (new Mrpack)->parse($manifest), 'filename_collision');
};
$tests['mrpack server overrides supersede common and exclude client overrides'] = function (): void {
    $result = (new Mrpack)->overrides(['overrides/config/a.toml', 'server-overrides/config/a.toml', 'client-overrides/config/client.toml']);
    check($result === ['config/a.toml' => 'server-overrides/config/a.toml']);
};
$tests['managed diff preserves edits additions and arbitrary world folder'] = function (): void {
    $diff = (new ManagedDiff)->compare(['mods/a.jar' => 'a', 'config/x.toml' => 'old'], ['mods/b.jar' => 'b', 'config/x.toml' => 'new'],
        ['mods/a.jar' => 'a', 'mods/manual.jar' => 'm', 'config/x.toml' => 'user edit', 'survival/level.dat' => 'world']);
    check(count($diff['changes']) === 2 && count($diff['conflicts']) === 1);
    check(!in_array('survival/level.dat', array_column($diff['changes'], 'path')));
};
$tests['supported runtime plans can install without a Wings addon'] = function (): void {
    $release = ['id' => 'v', 'title' => 'x', 'version' => '26.1', 'channel' => 'release', 'environment' => 'server', 'files' => [], 'recipe_shape' => ['download']];
    $plan = (new PlanBuilder)->build($release, ['kind' => 'software']);
    check($plan['executable'] && $plan['blockers'] === []);
    $release['recipe_shape'] = ['unknown'];
    check(!(new PlanBuilder)->build($release, ['kind' => 'software'])['executable']);
};

$dir = dirname(__DIR__) . '/.build/archive-tests';
if (!is_dir($dir)) { mkdir($dir, 0770, true); }
$makeZip = static function (array $entries, bool $link = false) use ($dir): string {
    $path = $dir . '/' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive; check($zip->open($path, ZipArchive::CREATE) === true);
    foreach ($entries as $name => $content) { $zip->addFromString($name, $content); }
    if ($link) { $zip->setExternalAttributesIndex(0, ZipArchive::OPSYS_UNIX, 0120777 << 16); }
    $zip->close(); return $path;
};
$tests['safe archive inspected without extraction'] = function () use ($makeZip): void {
    $file = $makeZip(['mods/a.jar' => 'abc', 'config/a.toml' => 'key=value']);
    check(count((new ArchiveInspector)->inspect($file)) === 2); unlink($file);
};
foreach (['../escape', '/absolute', 'C:/escape', 'config/../escape'] as $index => $name) {
    $tests['archive traversal ' . $index] = function () use ($makeZip, $name): void {
        $file = $makeZip([$name => 'x']); try { rejects(fn () => (new ArchiveInspector)->inspect($file), 'unsafe_path'); } finally { unlink($file); }
    };
}
$tests['archive symlinks bombs case duplicates and path collisions rejected'] = function () use ($makeZip): void {
    foreach ([
        [$makeZip(['link' => '/etc/passwd'], true), 'archive_special_file'],
        [$makeZip(['a' => str_repeat('x', 500000)]), 'archive_expansion_limit'],
        [$makeZip(['mods/A.jar' => 'a', 'mods/a.jar' => 'b']), 'archive_duplicate'],
        [$makeZip(['mods' => 'a', 'mods/a.jar' => 'b']), 'archive_path_collision'],
    ] as [$file, $reason]) { try { rejects(fn () => (new ArchiveInspector)->inspect($file), $reason); } finally { unlink($file); } }
};

$passed = 0;
$tests['catalog sorting maps to provider popularity downloads and update indices'] = function (): void {
    foreach (['popular'=>['follows',2], 'downloads'=>['downloads',6], 'updated'=>['updated',3]] as $sort=>$expected) {
        $http = new FixtureTransport(['modrinth/v2/search'=>['hits'=>[],'total_hits'=>0], 'curseforge/v1/mods/search'=>['data'=>[],'pagination'=>['totalCount'=>0]]]);
        $context=['type'=>'mod','query'=>'test','page'=>2,'version'=>'','loader'=>'','sort'=>$sort];
        (new Modrinth($http))->search($context); (new CurseForge($http))->search($context);
        check($http->calls[0][2]['index']===$expected[0] && $http->calls[0][2]['offset']===20);
        check($http->calls[1][2]['sortField']===$expected[1] && $http->calls[1][2]['sortOrder']==='desc');
    }
};
$tests['unknown catalog sort values cannot reach provider query parameters'] = function (): void {
    $catalog = new IByte\MinecraftManager\Catalog\Catalog(new FixtureTransport([]),new IByte\MinecraftManager\Support\Settings);
    rejects(fn ()=>$catalog->context(['sort'=>'arbitrary']),'invalid_selection');
};
$tests['provider thumbnails accept only known HTTPS image CDNs'] = function (): void {
    check(Input::image('https://cdn.modrinth.com/data/a/icon.png') !== null);
    check(Input::image('https://mediafilez.forgecdn.net/avatars/a.png') !== null);
    foreach (['javascript:alert(1)', 'http://cdn.modrinth.com/a.png', 'https://evil.example/a', 'https://cdn.modrinth.com.evil.example/a', 'https://key@cdn.modrinth.com/a', 'https://cdn.modrinth.com:8443/a'] as $url) { check(Input::image($url) === null); }
};
$tests['release digests ignore JSON object order and retain list order and changed checksums'] = function (): void {
    $digest=IByte\MinecraftManager\Operations\PlanBuilder::digest(...);
    $first=['files'=>[['hashes'=>['sha1'=>'a','sha512'=>'b'],'name'=>'mod.jar']],'steps'=>['download','unzip']];
    $reordered=['steps'=>['download','unzip'],'files'=>[['name'=>'mod.jar','hashes'=>['sha512'=>'b','sha1'=>'a']]]];
    check($digest($first)===$digest($reordered));
    $reordered['steps']=['unzip','download']; check($digest($first)!==$digest($reordered));
    $reordered=$first; $reordered['files'][0]['hashes']['sha512']='changed'; check($digest($first)!==$digest($reordered));
};
$tests['plugin platform compatibility is directional and excludes unmarked Folia and separate proxies'] = function (): void {
    $accept=IByte\MinecraftManager\Support\Compatibility::accepts(...);
    check($accept('paper',['spigot'],'plugin')&&$accept('paper',['bukkit'],'plugin')&&$accept('purpur',['paper'],'plugin'));
    check(!$accept('spigot',['paper'],'plugin')&&!$accept('bukkit',['spigot'],'plugin')&&!$accept('folia',['paper','bukkit'],'plugin'));
    check($accept('folia',['folia'],'plugin')&&!$accept('velocity',['bungeecord'],'plugin')&&$accept('waterfall',['bungeecord'],'plugin'));
    check(!$accept('forge',['neoforge'],'mod')&&!$accept('fabric',['quilt'],'mod'));
};
$tests['Paper plugin search uses OR platforms and exact version filters through release pagination'] = function (): void {
    $http=new FixtureTransport(['modrinth/v2/search'=>['hits'=>[],'total_hits'=>0], 'modrinth/v2/project/p/version'=>[
        ['id'=>'bukkit-v','name'=>'Bukkit','version_type'=>'release','game_versions'=>['1.21.1'],'loaders'=>['bukkit']],
        ['id'=>'paper-v','name'=>'Paper','version_type'=>'release','game_versions'=>['1.21.1'],'loaders'=>['paper']],
        ['id'=>'folia-v','name'=>'Folia','version_type'=>'release','game_versions'=>['1.21.1'],'loaders'=>['folia']],
        ['id'=>'old-v','name'=>'Other version','version_type'=>'release','game_versions'=>['1.20.1'],'loaders'=>['spigot']]]]);
    $context=(new IByte\MinecraftManager\Catalog\Catalog($http,new IByte\MinecraftManager\Support\Settings))->context(['type'=>'plugin','loader'=>'paper','version'=>'1.21.1']);
    $provider=new IByte\MinecraftManager\Catalog\Modrinth($http);$provider->search($context);$versions=$provider->releases('p',$context);
    check(json_decode($http->calls[0][2]['facets'],true)===[['project_type:plugin'],['versions:1.21.1'],['categories:paper','categories:spigot','categories:bukkit']]);
    check(json_decode($http->calls[1][2]['loaders'],true)===['paper','spigot','bukkit']&&array_column($versions,'id')===['bukkit-v','paper-v']);
};
$tests['CurseForge Bukkit filtering uses game version without a mod-loader enum and refuses unverified Folia filtering'] = function (): void {
    $http=new FixtureTransport(['curseforge/v1/mods/search'=>['data'=>[],'pagination'=>['totalCount'=>0]]]);
    $catalog=new IByte\MinecraftManager\Catalog\Catalog($http,new IByte\MinecraftManager\Support\Settings);$provider=new IByte\MinecraftManager\Catalog\CurseForge($http);
    $provider->search($catalog->context(['type'=>'plugin','loader'=>'paper','version'=>'1.21.1']));
    check($http->calls[0][2]['classId']===5&&$http->calls[0][2]['gameVersion']==='1.21.1'&&!isset($http->calls[0][2]['modLoaderType']));
    rejects(fn()=>$provider->search($catalog->context(['type'=>'plugin','loader'=>'folia','version'=>'1.21.1'])),'provider_platform_filter_unavailable');
    $provider->search($catalog->context(['type'=>'plugin']));check(!isset($http->calls[1][2]['gameVersion']));
};
$tests['review compatibility permits Bukkit on Paper while rejecting reverse and cross-version installs'] = function (): void {
    $release=['provider'=>'modrinth','project_type'=>'plugin','version'=>'1','channel'=>'release','environment'=>'server','loaders'=>['bukkit'],'game_versions'=>['1.21.1'],'files'=>[]];
    $context=['kind'=>'package','type'=>'plugin','version'=>'1.21.1','loader'=>'paper','runtime_context'=>['version'=>'1.21.1','loader'=>'paper','content_type'=>'plugin']];
    $builder=new IByte\MinecraftManager\Operations\PlanBuilder;check($builder->build($release,$context)['executable']);
    $release['loaders']=['paper'];$context['loader']=$context['runtime_context']['loader']='spigot';check(!$builder->build($release,$context)['executable']);
    $release['loaders']=['bukkit'];$context['runtime_context']['version']='1.20.1';check(!$builder->build($release,$context)['executable']);
};
foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS ', $name, PHP_EOL; $passed++; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); exit(1); }
}
echo "RESULT $passed passed; 0 failed. Deterministic unit/contract fixtures; no node execution.\n";
