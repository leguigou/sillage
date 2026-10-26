#!/usr/bin/env bash
set -euo pipefail
release="${1:?Commit requis}"
[[ "$release" =~ ^[0-9a-f]{40}$ ]] || { echo 'Commit invalide'; exit 1; }
base=/var/www/sillage
path="$base/releases/$release"
test -f "$base/shared/.env"
test -d "$base/shared/storage"
test -f "$path/bin/console.php"
# Les secrets et pièces jointes sont partagés entre les versions, jamais dans Git.
if [ ! -L "$path/.env" ]; then ln -s "$base/shared/.env" "$path/.env"; fi
if [ ! -L "$path/storage" ]; then ln -s "$base/shared/storage" "$path/storage"; fi
cd "$path"
# Une sauvegarde complète est créée avant toute migration de schéma.
php bin/backup.php "$base/shared/backups"
php bin/console.php migrate
php bin/console.php health
ln -s "$path" "$base/next"
mv -Tf "$base/next" "$base/current"
echo "Sillage $release activé. Les versions précédentes sont conservées."
