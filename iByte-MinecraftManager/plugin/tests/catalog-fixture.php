<?php

$fixtures = [
    'mcjars/api/v2/types' => ['types' => ['recommended' => ['VANILLA' => ['name' => 'Vanilla'], 'PAPER' => ['name' => 'Paper'], 'FABRIC' => ['name' => 'Fabric']]]],
    'mcjars/api/v3/builds/types/PAPER/versions' => ['versions' => ['page' => 1, 'total' => 1, 'data' => [['id' => '26.1', 'type' => 'RELEASE']]]],
    'mcjars/api/v3/builds/types/PAPER/versions/26.1' => ['builds' => ['page' => 1, 'total' => 1, 'data' => [['uuid' => 'paper-browser-build', 'name' => 'Paper Browser Build', 'experimental' => false]]]],
    'mcjars/api/v3/builds/paper-browser-build' => ['build' => ['uuid' => 'paper-browser-build', 'type' => 'PAPER', 'version_id' => '26.1', 'name' => 'Paper Browser Build', 'experimental' => false,
        'installation' => [[['type' => 'download', 'url' => 'https://fill-data.papermc.io/browser.jar', 'file' => 'server.jar', 'size' => 4]]]], 'version' => ['java' => 25, 'type' => 'RELEASE']],
    'curseforge/v1/mods/search' => ['data' => [['id' => 1230, 'name' => 'CurseForge Browser Item', 'summary' => 'Full CurseForge catalog fixture']], 'pagination' => ['totalCount' => 1]],
    'curseforge/v1/mods/1230/files' => ['data'=>[['id'=>1231,'displayName'=>'CurseForge Browser Release','releaseType'=>1,'gameVersions'=>['26.1','Fabric']]]],
    'modrinth/v2/search' => ['hits' => [['project_id' => 'fixtureproject', 'title' => 'Fixture Browser Mod',
        'description' => '<script>window.__fixtureInjection=true</script>Safe catalog description', 'server_side' => 'required',
        'icon_url'=>'https://cdn.modrinth.com/data/P7dR8mSH/icon.png','author'=>'Fixture Author','downloads'=>1250000],
        ['project_id'=>'fixturesecond','title'=>'Fixture Companion Mod','description'=>'A second server mod for batch installation','server_side'=>'required',
        'icon_url'=>'https://cdn.modrinth.com/data/P7dR8mSH/icon.png','author'=>'Fixture Author','downloads'=>75000]], 'total_hits' => 2],
    'modrinth/v2/project/fixturesecond' => ['id'=>'fixturesecond','server_side'=>'required','project_type'=>'mod'],
    'modrinth/v2/project/fixturesecond/version' => [['id'=>'fixturesecondrelease','name'=>'Fixture Companion Release','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']]],
    'modrinth/v2/version/fixturesecondrelease' => ['id'=>'fixturesecondrelease','project_id'=>'fixturesecond','name'=>'Fixture Companion Release','version_number'=>'1.0','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric'],'dependencies'=>[],
        'files'=>[['filename'=>'fixture-companion.jar','size'=>4,'primary'=>true,'hashes'=>['sha512'=>hash('sha512','test')]]]],
    'modrinth/v2/project/fixtureproject' => ['id' => 'fixtureproject', 'server_side' => 'required', 'project_type' => 'mod'],
    'modrinth/v2/project/fixtureproject/version' => [['id'=>'fixtureother','name'=>'Fixture Other Release','version_type'=>'release','game_versions'=>['26.2'],'loaders'=>['forge']], ['id' => 'fixturerelease', 'name' => 'Fixture Release 1', 'version_type' => 'release','game_versions'=>['26.1'],'loaders'=>['fabric']]],
    'modrinth/v2/version/fixturerelease' => ['id' => 'fixturerelease', 'project_id' => 'fixtureproject', 'name' => 'Fixture Release 1',
        'version_number' => '1.0', 'version_type' => 'release', 'game_versions' => ['26.1'], 'loaders' => ['fabric'], 'dependencies' => [],
        'files' => [['filename' => 'fixture-browser.jar', 'size' => 4, 'primary' => true, 'hashes' => ['sha512' => hash('sha512', 'test')]]]],
];
$base = $fixtures['modrinth/v2/search'];
$fixtures['modrinth/v2/search'] = static function (array $query) use ($base): array {
    if (($query['query'] ?? '') === 'pagination-browser') {
        $offset=(int)($query['offset'] ?? 0); $count=min(20,45-$offset);$hits=[];
        for($i=0;$i<$count;++$i){$hits[]=['project_id'=>'pagination-'.($offset+$i+1),'title'=>'Pagination Mod '.($offset+$i+1),'description'=>'Native numbered catalog fixture','server_side'=>'required'];}
        return ['hits'=>$hits,'total_hits'=>45];
    }
    if (($query['query'] ?? '') === 'client-browser') { return ['hits'=>[['project_id'=>'fixtureclient','title'=>'Client Browser Mod','description'=>'Client-only display branch','server_side'=>'unsupported']],'total_hits'=>1]; }
    if (($query['query'] ?? '') === 'history-browser') { return ['hits'=>[['project_id'=>'fixturehistory','title'=>'Release History Mod','description'=>'Release history fixture','server_side'=>'required']],'total_hits'=>1]; }
    return $base;
};
for($i=1;$i<=45;++$i){$fixtures['modrinth/v2/project/pagination-'.$i.'/version']=[];}
$fixtures['modrinth/v2/project/fixtureclient/version']=[['id'=>'client1','name'=>'Client release','version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']]];
$fixtures['modrinth/v2/project/fixturehistory/version']=array_map(fn($n)=>['id'=>'history-'.$n,'name'=>'History Release '.$n,'version_type'=>'release','game_versions'=>['26.1'],'loaders'=>['fabric']],range(1,61));
return $fixtures;
