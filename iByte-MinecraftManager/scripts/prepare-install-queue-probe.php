<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/.research/panel/vendor/autoload.php';
require $root.'/plugin/tests/bootstrap.php';
$app = require $root.'/.research/panel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::factory()->create(); $owner = App\Models\User::factory()->create();
$egg = App\Models\Egg::factory()->create();
$server = App\Models\Server::factory()->create(['owner_id' => $owner->id, 'egg_id' => $egg->id, 'image' => 'fixture/image:java_21']);
IByte\MinecraftManager\Models\Profile::create(['egg_id' => $egg->id, 'name' => 'Queue fixture', 'software' => 'FABRIC', 'loader' => 'fabric',
    'content_type' => 'mod', 'jar_path' => 'server.jar', 'images' => [$server->image], 'enabled' => true]);
$sub = App\Models\Subuser::create(['server_id' => $server->id, 'user_id' => $user->id, 'permissions' => ['minecraft-manager.browse', 'minecraft-manager.plan', 'minecraft-manager.packages',
    'file.read', 'file.read-content', 'file.create', 'file.update', 'file.delete']]);
$app->instance(IByte\MinecraftManager\Catalog\JsonTransport::class, new FixtureTransport(require $root.'/plugin/tests/catalog-fixture.php'));
$service = app(IByte\MinecraftManager\Operations\ReviewService::class);
$op = $service->create($user, $server, ['kind' => 'package', 'provider' => 'modrinth', 'project' => 'fixtureproject', 'release' => 'fixturerelease', 'version' => '26.1', 'type' => 'mod']);
$service->queue($user, $server, $op->id, true);
$sub->update(['permissions' => ['minecraft-manager.browse', 'minecraft-manager.plan']]);
file_put_contents($root.'/.build/install-queue-probe.json', json_encode(['operation' => $op->id]));
echo "PASS dedicated install queue dispatched with native permissions, then install permission revoked\n";
