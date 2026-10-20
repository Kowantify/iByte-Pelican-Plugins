#!/bin/bash
# Sync the current project plugin sources into the disposable fixture Panel install.
# Run inside the acceptance WSL distribution only.
set -euo pipefail
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required' >&2; exit 1; }
project=/mnt/c/Users/kakow/OneDrive/Desktop/Production/Code/Pelican\ Extensions/iByte-PrometheusExporter
target=/opt/ibyte-prometheus/panel/plugins/ibyte-prometheus-exporter
mkdir -p "$target"
cp -a "$project/panel-plugin/src/." "$target/src/"
cp -a "$project/panel-plugin/resources/." "$target/resources/"
cp "$project/panel-plugin/plugin.json" "$target/plugin.json"
cd /opt/ibyte-prometheus/panel
php artisan view:clear >/dev/null
php artisan cache:clear >/dev/null 2>&1 || true
# Refresh the plugin row so the panel re-reads the manifest.
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$row = App\Models\Plugin::find("ibyte-prometheus-exporter");
$row->version = json_decode(file_get_contents("/opt/ibyte-prometheus/panel/plugins/ibyte-prometheus-exporter/plugin.json"), true)["version"];
$row->save();
echo "plugin row version now: ", $row->version, PHP_EOL;
'
echo 'Fixture plugin sources synced.'