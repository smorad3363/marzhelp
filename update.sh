#!/usr/bin/env bash

set -Eeuo pipefail

readonly MARZHELP_REPOSITORY="${MARZHELP_REPOSITORY:-https://github.com/smorad3363/marzhelp.git}"
readonly MARZHELP_REF="${MARZHELP_REF:-${MARZHELP_BRANCH:-main}}"
readonly MARZHELP_DIRECTORY="${MARZHELP_DIRECTORY:-/var/www/html/marzhelp}"
readonly CONFIG_FILE="${MARZHELP_DIRECTORY}/config.php"
readonly BACKUP_ROOT="${MARZHELP_BACKUP_DIRECTORY:-/var/backups/marzhelp}"

log() {
    printf '[MarzHelp] %s\n' "$1"
}

fail() {
    printf '[MarzHelp] ERROR: %s\n' "$1" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

php_config_value() {
    local variable_name="$1"
    php -r '
        require $argv[1];
        $local = dirname($argv[1]) . "/config.local.php";
        if (is_file($local)) {
            require $local;
        }
        $name = $argv[2];
        if (!isset($$name)) {
            fwrite(STDERR, "Missing configuration value: {$name}\n");
            exit(1);
        }
        echo $$name;
    ' "$CONFIG_FILE" "$variable_name"
}

php_config_value_or_empty() {
    local variable_name="$1"
    php -r '
        require $argv[1];
        $local = dirname($argv[1]) . "/config.local.php";
        if (is_file($local)) {
            require $local;
        }
        $name = $argv[2];
        echo isset($$name) ? $$name : "";
    ' "$CONFIG_FILE" "$variable_name"
}

write_mysql_client_file() {
    local destination="$1"
    local host="$2"
    local user="$3"
    local password="$4"

    umask 077
    {
        printf '[client]\n'
        printf 'host=%s\n' "$host"
        printf 'user=%s\n' "$user"
        printf 'password=%s\n' "$password"
    } > "$destination"
}

for required_command in git php mysqldump tar find stat openssl curl; do
    require_command "$required_command"
done

if [[ "$(id -u)" -ne 0 && "${MARZHELP_ALLOW_UNPRIVILEGED:-0}" != "1" ]]; then
    fail "Run the updater as root so permissions and credentials can be migrated safely."
fi

[[ -d "${MARZHELP_DIRECTORY}/.git" ]] || fail "MarzHelp Git checkout was not found at ${MARZHELP_DIRECTORY}"
[[ -f "$CONFIG_FILE" ]] || fail "Existing configuration was not found at ${CONFIG_FILE}"

application_owner_id="$(stat -c '%u' "$MARZHELP_DIRECTORY")"
application_group_id="$(stat -c '%g' "$MARZHELP_DIRECTORY")"
mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
if [[ "$(id -u)" -eq 0 ]]; then
    chown "${application_owner_id}:${application_group_id}" "$BACKUP_ROOT"
fi
release_id="$(date -u +%Y%m%dT%H%M%SZ)"
backup_directory="${BACKUP_ROOT}/${release_id}"
mkdir -p "$backup_directory"
chmod 700 "$backup_directory"

old_commit="$(git -c safe.directory="$MARZHELP_DIRECTORY" -C "$MARZHELP_DIRECTORY" rev-parse HEAD)"
printf '%s\n' "$old_commit" > "${backup_directory}/previous-commit.txt"
git -c safe.directory="$MARZHELP_DIRECTORY" -C "$MARZHELP_DIRECTORY" status --short \
    > "${backup_directory}/previous-status.txt"
cp "$CONFIG_FILE" "${backup_directory}/config.php"
if [[ -f "${MARZHELP_DIRECTORY}/config.local.php" ]]; then
    cp "${MARZHELP_DIRECTORY}/config.local.php" "${backup_directory}/config.local.php"
fi

log "Creating an application backup."
tar \
    --exclude='.git' \
    -czf "${backup_directory}/application.tar.gz" \
    -C "$MARZHELP_DIRECTORY" .

bot_db_host="$(php_config_value botDbHost)"
bot_db_user="$(php_config_value botDbUser)"
bot_db_pass="$(php_config_value botDbPass)"
bot_db_name="$(php_config_value botDbName)"
vpn_db_host="$(php_config_value vpnDbHost)"
vpn_db_user="$(php_config_value vpnDbUser)"
vpn_db_pass="$(php_config_value vpnDbPass)"
vpn_db_name="$(php_config_value vpnDbName)"
migration_db_user="$(php_config_value_or_empty migrationDbUser)"
migration_db_pass="$(php_config_value_or_empty migrationDbPass)"

if [[ -n "$migration_db_user" && -n "$migration_db_pass" ]]; then
    bot_dump_user="$migration_db_user"
    bot_dump_pass="$migration_db_pass"
    vpn_dump_user="$migration_db_user"
    vpn_dump_pass="$migration_db_pass"
else
    bot_dump_user="$bot_db_user"
    bot_dump_pass="$bot_db_pass"
    vpn_dump_user="$vpn_db_user"
    vpn_dump_pass="$vpn_db_pass"
fi

bot_client_file="${backup_directory}/.bot-db.cnf"
vpn_client_file="${backup_directory}/.vpn-db.cnf"
cleanup_sensitive_files() {
    rm -f "$bot_client_file" "$vpn_client_file"
}
trap cleanup_sensitive_files EXIT

write_mysql_client_file "$bot_client_file" "$bot_db_host" "$bot_dump_user" "$bot_dump_pass"
write_mysql_client_file "$vpn_client_file" "$vpn_db_host" "$vpn_dump_user" "$vpn_dump_pass"

log "Creating database backups."
mysqldump \
    --defaults-extra-file="$bot_client_file" \
    --single-transaction \
    --skip-lock-tables \
    --no-tablespaces \
    "$bot_db_name" > "${backup_directory}/marzhelp.sql"
mysqldump \
    --defaults-extra-file="$vpn_client_file" \
    --single-transaction \
    --skip-lock-tables \
    --no-tablespaces \
    "$vpn_db_name" > "${backup_directory}/marzban.sql"
rm -f "$bot_client_file" "$vpn_client_file"
trap - EXIT

git_config=(-c "safe.directory=${MARZHELP_DIRECTORY}" -C "$MARZHELP_DIRECTORY")
if git "${git_config[@]}" remote get-url origin >/dev/null 2>&1; then
    git "${git_config[@]}" remote set-url origin "$MARZHELP_REPOSITORY"
else
    git "${git_config[@]}" remote add origin "$MARZHELP_REPOSITORY"
fi

log "Fetching ${MARZHELP_REF} from ${MARZHELP_REPOSITORY}."
git "${git_config[@]}" fetch --prune origin "$MARZHELP_REF"
target_commit="$(git "${git_config[@]}" rev-parse FETCH_HEAD)"

if git "${git_config[@]}" ls-tree -r --name-only "$target_commit" | grep -qx 'config.php'; then
    fail "The target release tracks config.php; refusing to overwrite server secrets."
fi

code_changed=0
restore_ownership() {
    if [[ "$(id -u)" -eq 0 ]]; then
        chown -R root:www-data "$MARZHELP_DIRECTORY"
        find "$MARZHELP_DIRECTORY" -type d -exec chmod 750 {} \;
        find "$MARZHELP_DIRECTORY" -type f -exec chmod 640 {} \;
        chmod 750 \
            "${MARZHELP_DIRECTORY}/bootstrap.sh" \
            "${MARZHELP_DIRECTORY}/install.sh" \
            "${MARZHELP_DIRECTORY}/update.sh"
        chmod 640 "$CONFIG_FILE"
        if [[ -f "${MARZHELP_DIRECTORY}/config.local.php" ]]; then
            chmod 640 "${MARZHELP_DIRECTORY}/config.local.php"
        fi
        chown -R root:root "$BACKUP_ROOT"
        chmod 700 "$BACKUP_ROOT"
    fi
}

rollback_code() {
    local exit_code=$?
    cleanup_sensitive_files
    if [[ $exit_code -ne 0 && $code_changed -eq 1 ]]; then
        log "Update failed; restoring application code to ${old_commit}."
        git "${git_config[@]}" reset --hard "$old_commit" >/dev/null 2>&1 || true
        cp "${backup_directory}/config.php" "$CONFIG_FILE" || true
    fi
    restore_ownership
    exit "$exit_code"
}
trap rollback_code EXIT

code_changed=1
git "${git_config[@]}" reset --hard "$target_commit"

webhook_secret="$(php -r '
    require $argv[1];
    $local = dirname($argv[1]) . "/config.local.php";
    if (is_file($local)) {
        require $local;
    }
    echo isset($webhookSecret) ? $webhookSecret : "";
' "$CONFIG_FILE")"
if [[ ${#webhook_secret} -lt 32 ]]; then
    webhook_secret="$(openssl rand -hex 32)"
fi

effective_bot_user="$(php_config_value botDbUser)"
effective_bot_pass="$(php_config_value botDbPass)"
effective_vpn_user="$(php_config_value vpnDbUser)"
effective_vpn_pass="$(php_config_value vpnDbPass)"
migration_user="$(php -r '
    require $argv[1];
    $local = dirname($argv[1]) . "/config.local.php";
    if (is_file($local)) {
        require $local;
    }
    echo isset($migrationDbUser) ? $migrationDbUser : "";
' "$CONFIG_FILE")"
migration_pass="$(php -r '
    require $argv[1];
    $local = dirname($argv[1]) . "/config.local.php";
    if (is_file($local)) {
        require $local;
    }
    echo isset($migrationDbPass) ? $migrationDbPass : "";
' "$CONFIG_FILE")"

if [[ "$effective_vpn_user" == "root" || "$effective_bot_user" == "root" ]]; then
    require_command mysql
    effective_bot_user="marzhelp_app"
    effective_vpn_user="marzhelp_app"
    effective_bot_pass="$(openssl rand -hex 24)"
    effective_vpn_pass="$effective_bot_pass"
    migration_user="marzhelp_migrate"
    migration_pass="$(openssl rand -hex 24)"

    migration_client_file="${backup_directory}/.migration-db.cnf"
    write_mysql_client_file \
        "$migration_client_file" \
        "$vpn_db_host" \
        "$vpn_db_user" \
        "$vpn_db_pass"

    mysql --defaults-extra-file="$migration_client_file" <<SQL
CREATE USER IF NOT EXISTS '${effective_vpn_user}'@'localhost' IDENTIFIED BY '${effective_vpn_pass}';
ALTER USER '${effective_vpn_user}'@'localhost' IDENTIFIED BY '${effective_vpn_pass}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${bot_db_name}\`.* TO '${effective_vpn_user}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${vpn_db_name}\`.* TO '${effective_vpn_user}'@'localhost';

CREATE USER IF NOT EXISTS '${migration_user}'@'localhost' IDENTIFIED BY '${migration_pass}';
ALTER USER '${migration_user}'@'localhost' IDENTIFIED BY '${migration_pass}';
GRANT ALL PRIVILEGES ON \`${bot_db_name}\`.* TO '${migration_user}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, TRIGGER, EVENT, REFERENCES
  ON \`${vpn_db_name}\`.* TO '${migration_user}'@'localhost';
SET GLOBAL event_scheduler = ON;
FLUSH PRIVILEGES;
SQL
    rm -f "$migration_client_file"
fi

cat > "${MARZHELP_DIRECTORY}/config.local.php" <<PHP
<?php
\$webhookSecret = '${webhook_secret}';
\$allowSystemCommands = false;
\$storagePath = '/var/lib/marzhelp';
\$botDbHost = 'localhost';
\$botDbUser = '${effective_bot_user}';
\$botDbPass = '${effective_bot_pass}';
\$vpnDbHost = 'localhost';
\$vpnDbUser = '${effective_vpn_user}';
\$vpnDbPass = '${effective_vpn_pass}';
\$migrationDbUser = '${migration_user}';
\$migrationDbPass = '${migration_pass}';
PHP

if [[ "$(id -u)" -eq 0 ]]; then
    install -d -o www-data -g www-data -m 750 /var/lib/marzhelp
    install -d -o root -g root -m 755 /etc/mysql/conf.d
    cat > /etc/mysql/conf.d/marzhelp.cnf <<'EOF'
[mysqld]
event_scheduler=ON
EOF
    chmod 644 /etc/mysql/conf.d/marzhelp.cnf
    rm -f /etc/sudoers.d/marzhelp
    if [[ -f /usr/local/bin/marzban ]]; then
        chown root:root /usr/local/bin/marzban
        chmod 755 /usr/local/bin/marzban
    fi
fi

log "Checking PHP syntax."
while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null
done < <(find "$MARZHELP_DIRECTORY" -name '*.php' -type f -print0)

log "Applying database migrations and cron configuration."
php "${MARZHELP_DIRECTORY}/table.php"

bot_token="$(php_config_value botToken)"
bot_domain="$(php_config_value botdomain)"
webhook_response="$(
    curl -fsS -X POST "https://api.telegram.org/bot${bot_token}/setWebhook" \
        --data-urlencode "url=https://${bot_domain}:88/marzhelp/webhook.php" \
        --data-urlencode "max_connections=40" \
        --data-urlencode "secret_token=${webhook_secret}"
)"
[[ "$webhook_response" == *'"ok":true'* ]] \
    || fail "Telegram rejected the authenticated webhook configuration."

if [[ "$(id -u)" -eq 0 ]] \
   && command -v nginx >/dev/null 2>&1 \
   && [[ -f "/etc/letsencrypt/live/${bot_domain}/fullchain.pem" ]]; then
    php_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    nginx_site="/etc/nginx/sites-available/marzhelp"
    nginx_site_backup="${backup_directory}/nginx-marzhelp.conf"
    if [[ -f "$nginx_site" ]]; then
        cp "$nginx_site" "$nginx_site_backup"
    fi

    cat > "$nginx_site" <<NGINX
server {
    listen 88 ssl;
    listen [::]:88 ssl;
    server_name ${bot_domain};
    root /var/www/html;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/${bot_domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${bot_domain}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    location /marzhelp/ {
        try_files \$uri \$uri/ =404;
    }

    location ~ ^/marzhelp/(?:config(?:\\.local)?\\.php|.*\\.(?:json|log|txt|sql))\$ {
        deny all;
    }

    location ~ ^/marzhelp/.*\\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php${php_version}-fpm.sock;
    }
}
NGINX
    ln -sfn "$nginx_site" /etc/nginx/sites-enabled/marzhelp
    if nginx -t; then
        systemctl reload nginx
    else
        if [[ -f "$nginx_site_backup" ]]; then
            cp "$nginx_site_backup" "$nginx_site"
        else
            rm -f "$nginx_site" /etc/nginx/sites-enabled/marzhelp
        fi
        fail "The isolated Nginx site failed validation; the previous configuration was restored."
    fi

    if command -v ufw >/dev/null 2>&1; then
        ufw allow 88/tcp >/dev/null
    fi
fi

php "${MARZHELP_DIRECTORY}/app/scrub_config.php" "$CONFIG_FILE"

restore_ownership

trap - EXIT
log "Update completed successfully. Backup: ${backup_directory}"
