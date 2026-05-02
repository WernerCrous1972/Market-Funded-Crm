#!/bin/bash
set -e

echo "🚀 Deploying Market Funded CRM..."

cd /var/www/market-funded-crm

# Prevent phantom permission diffs from blocking git pull
git config core.fileMode false

git pull origin main

composer install --no-dev --optimize-autoloader --no-interaction

npm ci && npm run build

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo -n supervisorctl restart all

echo "✅ Deploy complete!"
