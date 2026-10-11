#!/usr/bin/env bash
# Instalacja Gierkowni w czystym kontenerze LXC (Ubuntu 24.04), bez Dockera.
# Uruchom jako root w kontenerze:   bash deploy/install-lxc.sh
# Idempotentny: ponowne uruchomienie nie nadpisuje sekretow ani .env.local.
#
# Zmienne (opcjonalne):
#   REPO_URL      adres repozytorium (domyslnie fork na GitHubie)
#   BRANCH        galaz do wdrozenia (domyslnie main)
#   PUBLIC_HOST   adres, pod ktorym telefony widza gre (domyslnie IP kontenera)
#   MERCURE_VER   wersja huba; >=1.0 wymaga claimu "iss", ktorego
#                 symfony/mercure-bundle 0.4 nie wysyla - stad pin na 0.x
set -euo pipefail

REPO_URL=${REPO_URL:-https://github.com/popisgrzegorz/gierkownia.git}
BRANCH=${BRANCH:-main}
MERCURE_VER=${MERCURE_VER:-v0.24.2}
APP_DIR=/var/www/gierkownia
PUBLIC_HOST=${PUBLIC_HOST:-$(hostname -I | awk '{print $1}')}
HERE=$(cd "$(dirname "$0")" && pwd)

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# hunspell-pl: bez niego "Ostatnia litera" traktuje kazde slowo jako niepewne.
apt-get install -y -qq nginx php8.3-fpm php8.3-cli php8.3-intl php8.3-mbstring \
    php8.3-xml php8.3-pgsql php8.3-curl php8.3-zip php8.3-gd \
    redis-server postgresql git unzip curl composer hunspell hunspell-pl

# Redis w nieuprzywilejowanym LXC nie wstaje z domyslnym PrivateUsers.
install -d /etc/systemd/system/redis-server.service.d
printf '[Service]\nPrivateUsers=no\n' > /etc/systemd/system/redis-server.service.d/lxc.conf
systemctl daemon-reload
systemctl reset-failed redis-server 2>/dev/null || true
systemctl enable --now redis-server

# Kod.
if [ ! -d "$APP_DIR/.git" ]; then
    git clone -q -b "$BRANCH" "$REPO_URL" "$APP_DIR"
fi

cd "$APP_DIR"

# Sekrety i konfiguracja - tylko za pierwszym razem.
if [ ! -f .env.local ]; then
    PGPW=$(openssl rand -hex 12)
    JWT=$(openssl rand -hex 24)
    sudo -u postgres psql -qc "CREATE ROLE gierki LOGIN PASSWORD '$PGPW'" 2>/dev/null \
        || sudo -u postgres psql -qc "ALTER ROLE gierki PASSWORD '$PGPW'"
    sudo -u postgres psql -qtc "SELECT 1 FROM pg_database WHERE datname='gierki'" | grep -q 1 \
        || sudo -u postgres psql -qc "CREATE DATABASE gierki OWNER gierki"
    cat > .env.local <<ENV
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 16)
ADMIN_PASSWORD=$(openssl rand -hex 5)
HOST_PASSWORD=$(openssl rand -hex 4)
PUBLIC_URL=http://$PUBLIC_HOST
APP_BIND=127.0.0.1
MERCURE_BIND=127.0.0.1
MERCURE_PUBLIC_PORT=0
TRUSTED_PROXIES=
REDIS_PORT=6379
POSTGRES_PORT=5432
POSTGRES_PASSWORD=$PGPW
MERCURE_JWT_SECRET=$JWT
ENV
    echo "Hasla: grep -E 'ADMIN_PASSWORD|HOST_PASSWORD' $APP_DIR/.env.local"
fi
JWT=$(grep '^MERCURE_JWT_SECRET=' .env.local | cut -d= -f2-)

# Hub Mercure.
if [ ! -x /opt/mercure/mercure ] || ! /opt/mercure/mercure version | grep -q "${MERCURE_VER#v}"; then
    install -d /opt/mercure
    curl -fsSL "https://github.com/dunglas/mercure/releases/download/$MERCURE_VER/mercure_Linux_x86_64.tar.gz" \
        | tar -xz -C /opt/mercure
fi
sed "s/__JWT__/$JWT/g" "$HERE/mercure.service.tpl" > /etc/systemd/system/mercure.service
systemctl daemon-reload
systemctl enable --now mercure
systemctl restart mercure

# nginx i PHP.
sed -i 's/^upload_max_filesize.*/upload_max_filesize = 64M/;s/^post_max_size.*/post_max_size = 72M/' \
    /etc/php/8.3/fpm/php.ini
install -m 644 "$HERE/nginx.conf" /etc/nginx/sites-available/gierkownia
ln -sf /etc/nginx/sites-available/gierkownia /etc/nginx/sites-enabled/gierkownia
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable php8.3-fpm nginx postgresql >/dev/null
systemctl restart php8.3-fpm nginx

bash "$HERE/update.sh"
echo "Gotowe: http://$PUBLIC_HOST/"
