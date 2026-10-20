<?php
declare(strict_types=1);
// Test-only observation. Uses production remote transfers without replacing them.
final class TransferProbe
{
    public static array $requests=[];
    public static int $progress=0;
    public static bool $fractional=false;
    public static array $destinations=[];
    public static int $recoveryUploads=0;
    public static float $pullSeconds=0;
    public static function start(Illuminate\Foundation\Application $app):void
    {
        Illuminate\Support\Facades\Http::globalRequestMiddleware(static function(Psr\Http\Message\RequestInterface $request){
            if(preg_match('~/api/servers/[^/]+/(.+)~',$request->getUri()->getPath(),$match)){
                $key=$request->getMethod().' '.$match[1]; self::$requests[$key]=(self::$requests[$key]??0)+1;
                // Test-only WAN round-trip simulation, identical for old and new ZIPs.
                $delay=(int)(getenv('IMM_TEST_NODE_LATENCY_MS')?:0);
                if($delay>0&&$delay<=100){usleep($delay*1000);}
            }
            return $request;
        });
        $app->bind(IByte\MinecraftManager\Operations\NodeFiles::class,fn($app,$params)=>new class($params['server']) extends IByte\MinecraftManager\Operations\NodeFiles {
            public function pullMany(array $downloads,callable $progress):void
            {
                foreach($downloads as $item){
                    if(str_contains($item['path'],'.minecraft-manager')){throw new RuntimeException('A manager staging directory is forbidden');}
                    TransferProbe::$destinations[]=dirname($item['path']);
                }
                $start=microtime(true);
                try { parent::pullMany($downloads,static function(array $state)use($progress){
                    ++TransferProbe::$progress;
                    if(($state['fraction']??0)>0&&($state['fraction']??1)<1){TransferProbe::$fractional=true;}
                    $progress($state);
                }); } finally { TransferProbe::$pullSeconds+=microtime(true)-$start; }
            }
            public function mkdir(string $path):void
            {
                if(str_starts_with($path,'.minecraft-manager')){throw new RuntimeException('A manager directory must never be created');}
                parent::mkdir($path);
            }
            public function upload(string $path,string $local):void
            {
                $op=IByte\MinecraftManager\Models\Operation::where('server_slot',$this->server->id)->where('status','rolling-back')->first();
                if(!$op||hash_file('sha256',$local)!==($op->plan['journal'][$path]['old']??null)){throw new RuntimeException('Panel provider payload upload forbidden in acceptance');}
                ++TransferProbe::$recoveryUploads;
                parent::upload($path,$local);
            }
        });
        $app->instance(IByte\MinecraftManager\Support\ArtifactDownloader::class,new class extends IByte\MinecraftManager\Support\ArtifactDownloader {
            public function download(string $url,string $destination,?int $size,array $hashes=[]):array{throw new RuntimeException('Panel provider payload download forbidden in direct-node acceptance');}
        });
    }
    public static function result():array
    {
        return ['requests'=>self::$requests,'wings_pulls'=>self::$requests['POST files/pull']??0,
            'panel_uploads'=>self::$requests['POST files/write']??0,'wings_extractions'=>self::$requests['POST files/decompress']??0,
            'progress_samples'=>self::$progress,'fractional_progress_observed'=>self::$fractional,
            'download_directories'=>array_values(array_unique(self::$destinations)),'recovery_uploads'=>self::$recoveryUploads,'direct_pull_seconds'=>round(self::$pullSeconds,3)];
    }
}
