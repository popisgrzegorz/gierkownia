#!/usr/bin/env bash
# Aktualizacja wdrozonej Gierkowni: kod, zaleznosci, migracje, assety.
# Uruchom jako root na serwerze:   bash /var/www/gierkownia/deploy/update.sh
set -euo pipefail

APP_DIR=/var/www/gierkownia
cd "$APP_DIR"

# --ff-only: serwer nigdy nie robi merge'a ani nie gubi lokalnych zmian po cichu.
git pull --ff-only

COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:slownik:seed
php bin/console asset-map:compile
php bin/console cache:clear

# PHP-FPM dziala jako www-data: musi czytac .env.local i pisac do var/.
install -d var/media
chown -R www-data:www-data var public/assets .env.local
chmod 600 .env.local
systemctl reload php8.3-fpm
echo "Zaktualizowano do $(git log --oneline -1)"
