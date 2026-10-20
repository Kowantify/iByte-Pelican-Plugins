<?php

declare(strict_types=1);

// Disposable test data only. No real node is configured or contacted.
$root = dirname(__DIR__);
require $root . '/.research/panel/vendor/autoload.php';
$app = require $root . '/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$owner = App\Models\User::factory()->create();
$admin = App\Models\User::factory()->create();
$admin->assignRole(App\Models\Role::findOrCreate(App\Models\Role::ROOT_ADMIN, 'web'));
$egg = App\Models\Egg::factory()->create(['name' => 'Disposable Minecraft fixture', 'docker_images' => ['Test image' => 'approved/image:test']]);
$node = App\Models\Node::factory()->create(['fqdn' => '127.0.0.1', 'daemon_connect' => 9, 'daemon_listen' => 9]);
$server = App\Models\Server::factory()->withNode($node)->create(['owner_id' => $owner->id, 'egg_id' => $egg->id, 'name' => 'Disposable Minecraft', 'image' => 'custom/current-runtime:latest']);
app(IByte\MinecraftManager\Software\ProfileWizard::class)->save(['egg_id' => $egg->id, 'enabled' => true]);
app(IByte\MinecraftManager\Support\Settings::class)->save(['enabled' => true]);
$catalog = app(IByte\MinecraftManager\Catalog\Catalog::class);
foreach (['modrinth', 'curseforge'] as $provider) {
    foreach (['mod', 'plugin', 'modpack'] as $type) {
        foreach (['popular','downloads','updated'] as $sort) {
        $context = $catalog->context(['type' => $type,'sort'=>$sort]);
        $credential = $provider === 'curseforge' ? hash('sha256', app(IByte\MinecraftManager\Support\Settings::class)->credential($provider) ?? '') : '';
        Illuminate\Support\Facades\Cache::forget('imm.catalog.v9.'.hash('sha256', json_encode([$provider, $context, $credential], JSON_THROW_ON_ERROR)));
        }
    }
}
file_put_contents($root . '/.build/browser-fixture.json', json_encode([
    'owner' => $owner->id, 'admin' => $admin->id, 'server' => $server->uuid_short, 'egg' => $egg->id,
], JSON_THROW_ON_ERROR));
echo "Created isolated browser actors/server; dummy Wings endpoint is loopback port 9.\n";
