<?php
declare(strict_types=1);

if (getenv('WSL_DISTRO_NAME') !== 'iBytePrometheusAcceptance') throw new RuntimeException('Disposable distribution required');
chdir('/opt/ibyte-prometheus/panel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never { fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n"); exit(1); });
$service = app(App\Services\Helpers\PluginService::class);
$id = 'ibyte-prometheus-exporter';
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
switch ($argv[1] ?? '') {
    case 'seed-nodes':
        $owner = App\Models\User::factory()->create();
        $owner->assignRole(App\Models\Role::firstOrCreate(['name' => App\Models\Role::ROOT_ADMIN, 'guard_name' => 'web']));
        $egg = App\Models\Egg::factory()->create(['config_startup' => '{"done":"READY"}', 'config_stop' => '^C']);
        $nodes = [];
        for ($i = 1; $i <= 2; $i++) {
            $node = App\Models\Node::factory()->create(['name' => 'Exporter fixture '.$i, 'scheme' => 'https',
                'fqdn' => 'node'.$i.'.fixture.test', 'daemon_listen' => 18440 + $i, 'daemon_connect' => 18440 + $i,
                'daemon_sftp' => 12020 + $i, 'daemon_base' => '/opt/ibyte-prometheus/node'.$i.'/volumes']);
            $server = App\Models\Server::factory()->create(['node_id' => $node->id, 'owner_id' => $owner->id,
                'egg_id' => $egg->id, 'status' => null, 'memory' => $i === 1 ? 128 : 0,
                'cpu' => $i === 1 ? 100 : 0, 'disk' => $i === 1 ? 256 : 0,
                'startup' => 'sleep 86400', 'image' => 'alpine:3.22.2']);
            $config = $node->getConfiguration();
            $base = '/opt/ibyte-prometheus/node'.$i;
            mkdir($base.'/volumes/'.$server->uuid, 0755, true);
            $config['remote'] = 'http://127.0.0.1:8090';
            $config['api']['ssl']['cert'] = '/opt/ibyte-prometheus/tls.crt';
            $config['api']['ssl']['key'] = '/opt/ibyte-prometheus/tls.key';
            $config['system'] += ['root_directory' => $base, 'log_directory' => $base.'/logs',
                'archive_directory' => $base.'/archives', 'backup_directory' => $base.'/backups',
                'tmp_directory' => $base.'/tmp', 'enable_log_rotate' => false];
            $config['docker'] = ['network' => ['name' => 'ibyte_exporter_fixture', 'network_mode' => 'ibyte_exporter_fixture', 'IPv6' => false]];
            file_put_contents($base.'/config.yml', Symfony\Component\Yaml\Yaml::dump($config, 8, 2, Symfony\Component\Yaml\Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
            chmod($base.'/config.yml', 0600);
            $nodes[] = ['node_id' => $node->id, 'uuid' => $server->uuid, 'base' => $base];
        }
        file_put_contents('/opt/ibyte-prometheus/nodes.json', json_encode($nodes));
        auth()->login($owner);
        $page = new IByte\PrometheusExporter\Admin\ExporterPage;
        $page->mount();
        $page->createKey();
        check(strlen((string) $page->newKey) === 64, 'Root created a 64-character scrape key');
        check(IByte\PrometheusExporter\Support\Settings::read()['token_hash'] !== '', 'Key hash persisted');
        file_put_contents('/opt/ibyte-prometheus/scrape-token', $page->newKey);
        chmod('/opt/ibyte-prometheus/scrape-token', 0600);
        $page->createKey();
        check(IByte\PrometheusExporter\Support\Settings::read()['token_hash'] !== hash('sha256', $page->newKey),
            'Rotating immediately revokes the old key');
        $page->newKey = '';
        try { $page->mount(); $page->createKey(); } catch (RuntimeException) {}
        check(strlen((string) $page->newKey) === 64, 'Re-created a fresh key after rotation');
        file_put_contents('/opt/ibyte-prometheus/scrape-token', $page->newKey);
        $before = IByte\PrometheusExporter\Support\Settings::read();
        IByte\PrometheusExporter\Support\Settings::write(array_merge($before,
            ['token_hash' => hash('sha256', trim((string) file_get_contents('/opt/ibyte-prometheus/scrape-token')))]));
        break;
    case 'install':
        $file = new Illuminate\Http\UploadedFile($argv[2], 'plugin.zip', 'application/zip', null, true);
        check($service->downloadPluginFromFile($file) === $id, 'Native ZIP import');
        App\Models\Plugin::refreshRows();
        $service->installPlugin(App\Models\Plugin::findOrFail($id));
        check(App\Models\Plugin::findOrFail($id)->status === App\Enums\PluginStatus::Enabled, 'Native install/enabled');
        break;
    case 'check':
        check(class_exists(IByte\PrometheusExporter\Admin\ExporterPage::class), 'Plugin autoload');
        check(Illuminate\Support\Facades\Route::has('ibyte-prometheus-exporter.metrics'), 'Native metrics route');
        check(!IByte\PrometheusExporter\Admin\ExporterPage::canAccess(), 'Anonymous admin access rejected');
        $page = new IByte\PrometheusExporter\Admin\ExporterPage;
        foreach (['createKey', 'revokeKey'] as $action) {
            try { $page->$action(); throw new RuntimeException('Unauthorized action accepted'); }
            catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
                check($error->getStatusCode() === 403, "Unauthorized $action rejected");
            }
        }
        // Use a temporary token hash whose raw secret is known only to this test run,
        // then restore the previous hash so the real scrape token keeps working.
        $settings = IByte\PrometheusExporter\Support\Settings::read();
        $previousHash = $settings['token_hash'];
        $settings['token_hash'] = hash('sha256', 'fixture-scrape-token');
        IByte\PrometheusExporter\Support\Settings::write($settings);
        $request = Illuminate\Http\Request::create('https://panel.fixture.test/ibyte-prometheus-exporter/metrics');
        $request->headers->set('Authorization', 'Bearer wrong-token');
        try {
            (new IByte\PrometheusExporter\Http\Metrics)($request);
            throw new RuntimeException('Unauthorized metrics accepted');
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
            check($error->getStatusCode() === 401, 'Wrong token rejected');
        }
        $request = Illuminate\Http\Request::create('https://panel.fixture.test/ibyte-prometheus-exporter/metrics');
        $request->headers->set('Authorization', 'Bearer fixture-scrape-token');
        $response = (new IByte\PrometheusExporter\Http\Metrics)($request);
        check($response->getStatusCode() === 200, 'Authenticated cached exposition');
        $settings = IByte\PrometheusExporter\Support\Settings::read();
        $settings['token_hash'] = $previousHash;
        IByte\PrometheusExporter\Support\Settings::write($settings);
        file_put_contents('/opt/ibyte-prometheus/metrics.txt', $response->getContent());
        $lock = Illuminate\Support\Facades\Cache::lock('ibyte-prometheus:collector', 120);
        check($lock->get(), 'Acquire collector lock');
        try { check(Illuminate\Support\Facades\Artisan::call('ibyte:prometheus-collect') === 0, 'Duplicate collector exits safely'); }
        finally { $lock->release(); }
        break;
    case 'collect':
        Illuminate\Support\Facades\Artisan::call('ibyte:prometheus-collect');
        // HTTPS fixture nodes must collect; HTTP factory-stray nodes (2 and 4) are
        // skipped by design. Assert both fixture nodes succeeded.
        $collected = 0;
        foreach (App\Models\Node::query()->orderBy('id')->get() as $node) {
            $snapshot = Illuminate\Support\Facades\Cache::get("ibyte-prometheus:node:".$node->id, []);
            if ($node->scheme !== 'https') {
                check(($snapshot['success'] ?? null) === false, "HTTP node {$node->id} skipped (insecure)");
                continue;
            }
            check($snapshot['success'] ?? false, "Stock Wings node {$node->id} collection");
            check(count($snapshot['servers']) >= 1, "Node {$node->id} authoritative server inventory");
            check(is_array($snapshot['backups']), "Node {$node->id} backup metrics counted");
            $collected++;
            echo json_encode(['node' => $node->id, 'duration' => $snapshot['duration'],
                'backups' => $snapshot['backups'], 'server' => $snapshot['servers'][0]])."\n";
        }
        check($collected >= 2, 'Two HTTPS fixture nodes collected');
        $request = Illuminate\Http\Request::create('https://panel.fixture.test/ibyte-prometheus-exporter/metrics');
        $request->headers->set('Authorization', 'Bearer '.trim((string) file_get_contents('/opt/ibyte-prometheus/scrape-token')));
        $response = (new IByte\PrometheusExporter\Http\Metrics)($request);
        file_put_contents('/opt/ibyte-prometheus/metrics.txt', $response->getContent());
        $body = $response->getContent();
        foreach (['ibyte_pelican_server_cpu_usage_ratio{', 'ibyte_pelican_server_info{',
            'ibyte_pelican_node_backups_stored{', 'ibyte_pelican_node_backups_bytes{',
            'ibyte_pelican_server_network_rx_bytes{', 'ibyte_pelican_server_network_tx_bytes{',
            'ibyte_pelican_server_disk_read_bytes{', 'ibyte_pelican_server_disk_write_bytes{',
            'ibyte_pelican_server_uptime_seconds{', 'ibyte_pelican_server_wings_memory_limit_bytes{',
            'ibyte_pelican_node_memory_capacity_bytes{', 'ibyte_pelican_node_disk_capacity_bytes{',
            'ibyte_pelican_node_allocations{', 'ibyte_pelican_node_allocations_used{',
            'ibyte_pelican_node_servers_status{', 'ibyte_pelican_panel_users ',
            'ibyte_pelican_panel_root_admins ', 'ibyte_pelican_panel_servers ',
            'ibyte_pelican_panel_servers_status{'] as $needle) {
            check(str_contains($body, $needle), "Exposition contains $needle");
        }
        // Info series must carry the readable name and owner username.
        check(str_contains($body, 'name="Brennan"') && str_contains($body, 'owner="'),
            'Info series carries name and owner labels');
        break;
    case 'start-workloads':
        foreach (json_decode(file_get_contents('/opt/ibyte-prometheus/nodes.json'), true) as $row) {
            $server = App\Models\Server::where('uuid', $row['uuid'])->firstOrFail();
            $server->update(['image' => 'ibyte-exporter-workload:fixture', 'startup' => '/entrypoint.sh']);
            $node = $server->node;
            $client = Illuminate\Support\Facades\Http::withToken($node->daemon_token)->withoutRedirecting();
            $client->post($node->getConnectionAddress().'/api/servers/'.$server->uuid.'/sync')->throw();
            $client->post($node->getConnectionAddress().'/api/servers/'.$server->uuid.'/power', ['action' => 'start'])->throw();
        }
        echo "Started two disposable synthetic workloads through stock Wings.\n";
        break;
    case 'disable':
        $service->disablePlugin($id);
        check(App\Models\Plugin::findOrFail($id)->status === App\Enums\PluginStatus::Disabled, 'Native disable');
        break;
    case 'disabled-check':
        check(!Illuminate\Support\Facades\Route::has('ibyte-prometheus-exporter.metrics'), 'Disabled plugin has no metrics route');
        break;
    case 'enable': $service->enablePlugin($id); break;
    case 'uninstall':
        $service->uninstallPlugin(App\Models\Plugin::findOrFail($id));
        check(App\Models\Plugin::findOrFail($id)->status === App\Enums\PluginStatus::NotInstalled, 'Native uninstall');
        check(is_file(storage_path('app/ibyte-prometheus-exporter/settings.json')), 'Uninstall preserves settings');
        break;
    default: throw new RuntimeException('Unknown fixture operation');
}
