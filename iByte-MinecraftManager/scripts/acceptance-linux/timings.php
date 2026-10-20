<?php
declare(strict_types=1);
if(PHP_OS_FAMILY!=='Linux'||getenv('DB_DATABASE')!=='imm-acceptance.sqlite'){exit(1);}
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fixture=json_decode(file_get_contents('/opt/imm/fixture.json'),true);$id=$fixture['cases']['cobblemon']['id'];$op=IByte\MinecraftManager\Models\Operation::where('server_id',$id)->latest()->firstOrFail();
$measure=function(callable $call,int $count=10):array{$start=microtime(true);for($i=0;$i<$count;$i++){$call();}return ['calls'=>$count,'ms_per_call'=>round((microtime(true)-$start)*1000/$count,3)];};
$results=['journal_bytes'=>strlen(json_encode($op->plan['journal']??[])),'status'=>$op->status];
$results['ownership_projection']=$measure(fn()=>IByte\MinecraftManager\Models\Operation::select(['id','server_slot','status'])->findOrFail($op->id));
$results['ownership_exists']=$measure(fn()=>IByte\MinecraftManager\Models\Operation::where('server_slot',$id)->whereKey($op->id)->whereIn('status',['queued','preflight','downloading','verifying','applying'])->exists());
$results['status_only']=$measure(fn()=>IByte\MinecraftManager\Models\Operation::select(['id','status'])->findOrFail($op->id));
$server=App\Models\Server::findOrFail($id);$node=new IByte\MinecraftManager\Operations\NodeFiles($server);
$results['stopped_check']=$measure(fn()=>$node->assertStopped(),5);
$path=array_key_first(array_filter($op->plan['installed_files']??[],static fn($item,$path)=>str_starts_with($path,'config/'),ARRAY_FILTER_USE_BOTH));
if($path&&$node->exists($path)){$results['small_file']=$path;$results['file_exists']=$measure(fn()=>$node->exists($path),5);$results['file_hash']=$measure(fn()=>$node->hash($path),5);}
$hot=$op->plan['progress']['file']??null;if($hot&&$node->exists($hot)){$results['large_directory_file']=$hot;$results['large_directory_exists']=$measure(fn()=>$node->exists($hot),5);$results['large_directory_hash']=$measure(fn()=>$node->hash($hot),5);}
if(in_array($op->status,['downloading','verifying','applying'],true)){$results['full_authorize']=$measure(fn()=>app(IByte\MinecraftManager\Operations\InstallExecutor::class)->authorize($op));}
$results['query_plan']=Illuminate\Support\Facades\DB::select('EXPLAIN QUERY PLAN SELECT id, server_slot, status FROM imm_operations WHERE id = ?',[$op->id]);
$results['exists_query_plan']=Illuminate\Support\Facades\DB::select('EXPLAIN QUERY PLAN SELECT 1 FROM imm_operations WHERE server_slot = ? AND id = ? AND status IN (\'applying\',\'verifying\')',[$id,$op->id]);
echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
