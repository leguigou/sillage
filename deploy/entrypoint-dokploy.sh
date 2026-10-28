#!/bin/sh
# Point d'entrée Dokploy.
#   app    : relais SMTP optionnel, droits du stockage, migrations, puis Apache
#   worker : relais SMTP optionnel, puis livraison des notifications en boucle
# Le relais SMTP est facultatif : sans SMTP_HOST, mail() échoue et l'application
# affiche « L'envoi de l'e-mail est indisponible ».
set -e
role="${1:-app}"

if [ -n "${SMTP_HOST:-}" ]; then
    umask 077
    {
        echo "defaults"
        echo "auth on"
        echo "tls on"
        echo "tls_trust_file /etc/ssl/certs/ca-certificates.crt"
        echo "tls_starttls ${SMTP_STARTTLS:-on}"
        echo "logfile -"
        echo "account default"
        echo "host ${SMTP_HOST}"
        echo "port ${SMTP_PORT:-587}"
        echo "user ${SMTP_USER}"
        echo "password ${SMTP_PASSWORD}"
        echo "from ${MAIL_FROM}"
    } > /etc/msmtprc
    chown www-data:www-data /etc/msmtprc
    chmod 600 /etc/msmtprc
fi

if [ "$role" = "worker" ]; then
    sleep 20
    exec sh -c 'while true; do php /var/www/html/bin/console.php notifications:send; sleep 60; done'
fi

chown -R www-data:www-data /var/www/html/storage
php /var/www/html/bin/console.php migrate
exec apache2-foreground
