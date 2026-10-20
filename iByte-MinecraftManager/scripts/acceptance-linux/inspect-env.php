<?php
require '/opt/imm-panel/vendor/autoload.php';$app=require '/opt/imm-panel/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['db'=>config('database.connections.sqlite.database'),'getenv'=>getenv('DB_DATABASE'),'env'=>$_ENV['DB_DATABASE']??null,'server'=>$_SERVER['DB_DATABASE']??null,'heartbeat'=>IByte\MinecraftManager\Models\Setting::find('install-worker')?->value]),"\n";
