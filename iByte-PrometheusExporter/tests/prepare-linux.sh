#!/bin/bash
set -euo pipefail
[[ ${WSL_DISTRO_NAME:-} = iBytePrometheusAcceptance ]] || { echo 'Dedicated disposable distribution required'; exit 1; }
project=$(cd "$(dirname "$0")/.." && pwd)
fixture=/opt/ibyte-prometheus
mkdir -p "$fixture/panel"
cd "$fixture/panel"
# Official release archive verified against the release asset SHA-256 before use.
tar -xzf "$project/.tools/panel.tar.gz"
if [ ! -f "$fixture/composer.phar" ]; then
  curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o "$fixture/composer.phar"
  curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256sum -o "$fixture/composer.sha256"
  (cd "$fixture" && sha256sum -c composer.sha256)
fi
mkdir -p storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs plugins
find bootstrap/cache -type f ! -name .gitignore -delete
cat > .env <<'EOF'
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.fixture.test:8443
APP_INSTALLED=true
APP_KEY=
DB_CONNECTION=sqlite
DB_DATABASE=/opt/ibyte-prometheus/panel/database/database.sqlite
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
EOF
touch database/database.sqlite
COMPOSER_ALLOW_SUPERUSER=1 php "$fixture/composer.phar" install --no-dev --no-interaction --prefer-dist --no-scripts
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force
if ! docker info >/dev/null 2>&1; then
  nohup dockerd >"$fixture/docker.log" 2>&1 </dev/null &
fi
echo 'Disposable Panel prepared.'
