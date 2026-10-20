<?php
putenv('DB_DATABASE=imm-acceptance.sqlite');putenv('APP_URL=http://127.0.0.1:18096');require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/evidence.php';
if(is_file('/opt/imm/fixture.json')){
    $fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);
    if(IByte\MinecraftManager\Models\Operation::whereIn('server_id',array_column($fixture['cases'],'id'))->whereNotNull('server_slot')->exists()){throw new RuntimeException('An acceptance installation is still active');}
}
$failure='/opt/imm/cobblemon-installation.json';
if(is_file($failure)&&!is_file(acceptanceEvidencePath('cobblemon-prefix-limit-failure.json'))){$data=json_decode(file_get_contents($failure),true);if(($data['error']??null)==='unsafe_path'){copy($failure,acceptanceEvidencePath('cobblemon-prefix-limit-failure.json'));}}
$workspace='/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican Extensions/iByte-MinecraftManager';$version=json_decode(file_get_contents($workspace.'/plugin/plugin.json'),true)['version'];$zip=$workspace.'/.build/ibyte-minecraft-manager-v'.$version.'.zip';
app(App\Services\Helpers\PluginService::class)->downloadPluginFromFile(new Illuminate\Http\UploadedFile($zip,basename($zip),'application/zip',null,true));
App\Models\Plugin::refreshRows();echo 'Native RC.1 imported exact candidate: ',App\Models\Plugin::findOrFail('ibyte-minecraft-manager')->version,"\n";
