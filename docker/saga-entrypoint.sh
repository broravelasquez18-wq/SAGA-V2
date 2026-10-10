#!/bin/sh
set -eu

# Si la migracion falla, no servir una version incompatible con la BD.
php /var/www/html/migrate_auditoria.php --apply
exec docker-php-entrypoint "$@"
