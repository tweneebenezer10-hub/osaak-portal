#!/bin/sh
set -eu

port="${PORT:-10000}"
case "$port" in
    ''|*[!0-9]*) echo "PORT must be a numeric TCP port." >&2; exit 1 ;;
esac
if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "PORT must be between 1 and 65535." >&2
    exit 1
fi

sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s#<VirtualHost .*:80>#<VirtualHost *:${port}>#" /etc/apache2/sites-available/000-default.conf

mkdir -p \
    /var/www/html/storage/branding \
    /var/www/html/storage/logs \
    /var/www/html/storage/profile-pictures \
    /var/www/html/storage/school-logos \
    /var/www/html/storage/sessions \
    /var/www/html/storage/student-lifecycle \
    /var/www/html/storage/student-photos \
    /var/www/html/storage/support
chown -R www-data:www-data /var/www/html/storage
chmod -R u+rwX,g+rwX /var/www/html/storage

exec apache2-foreground
