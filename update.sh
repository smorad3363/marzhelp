#!/usr/bin/env bash

set -Eeuo pipefail

readonly MARZHELP_REPOSITORY="${MARZHELP_REPOSITORY:-https://github.com/smorad3363/marzhelp.git}"
readonly MARZHELP_BRANCH="${MARZHELP_BRANCH:-production}"
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
        $name = $argv[2];
        if (!isset($$name)) {
            fwrite(STDERR, "Missing configuration value: {$name}\n");
            exit(1);
        }
        echo $$name;
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

for required_command in git php mysqldump tar find stat; do
    require_command "$required_command"
done

[[ -d "${MARZHELP_DIRECTORY}/.git" ]] || fail "MarzHelp Git checkout was not found at ${MARZHELP_DIRECTORY}"
[[ -f "$CONFIG_FILE" ]] || fail "Existing configuration was not found at ${CONFIG_FILE}"

application_owner_id="$(stat -c '%u' "$MARZHELP_DIRECTORY")"
mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
if [[ "$(id -u)" -eq 0 ]]; then
    chown "$application_owner_id" "$BACKUP_ROOT"
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

bot_client_file="${backup_directory}/.bot-db.cnf"
vpn_client_file="${backup_directory}/.vpn-db.cnf"
cleanup_sensitive_files() {
    rm -f "$bot_client_file" "$vpn_client_file"
}
trap cleanup_sensitive_files EXIT

write_mysql_client_file "$bot_client_file" "$bot_db_host" "$bot_db_user" "$bot_db_pass"
write_mysql_client_file "$vpn_client_file" "$vpn_db_host" "$vpn_db_user" "$vpn_db_pass"

log "Creating database backups."
mysqldump \
    --defaults-extra-file="$bot_client_file" \
    --single-transaction \
    --skip-lock-tables \
    "$bot_db_name" > "${backup_directory}/marzhelp.sql"
mysqldump \
    --defaults-extra-file="$vpn_client_file" \
    --single-transaction \
    --skip-lock-tables \
    "$vpn_db_name" > "${backup_directory}/marzban.sql"
rm -f "$bot_client_file" "$vpn_client_file"
trap - EXIT

git_config=(-c "safe.directory=${MARZHELP_DIRECTORY}" -C "$MARZHELP_DIRECTORY")
if git "${git_config[@]}" remote get-url origin >/dev/null 2>&1; then
    git "${git_config[@]}" remote set-url origin "$MARZHELP_REPOSITORY"
else
    git "${git_config[@]}" remote add origin "$MARZHELP_REPOSITORY"
fi

log "Fetching ${MARZHELP_BRANCH} from ${MARZHELP_REPOSITORY}."
git "${git_config[@]}" fetch --prune origin "$MARZHELP_BRANCH"

if git "${git_config[@]}" ls-tree -r --name-only "origin/${MARZHELP_BRANCH}" | grep -qx 'config.php'; then
    fail "The target release tracks config.php; refusing to overwrite server secrets."
fi

code_changed=0
rollback_code() {
    local exit_code=$?
    cleanup_sensitive_files
    if [[ $exit_code -ne 0 && $code_changed -eq 1 ]]; then
        log "Update failed; restoring application code to ${old_commit}."
        git "${git_config[@]}" reset --hard "$old_commit" >/dev/null 2>&1 || true
        cp "${backup_directory}/config.php" "$CONFIG_FILE" || true
    fi
    exit "$exit_code"
}
trap rollback_code EXIT

code_changed=1
git "${git_config[@]}" reset --hard "origin/${MARZHELP_BRANCH}"

log "Checking PHP syntax."
while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null
done < <(find "$MARZHELP_DIRECTORY" -name '*.php' -type f -print0)

log "Applying database migrations and cron configuration."
php "${MARZHELP_DIRECTORY}/table.php"

if [[ "$(id -u)" -eq 0 ]]; then
    chown -R "$application_owner_id" "$BACKUP_ROOT"
fi

trap - EXIT
log "Update completed successfully. Backup: ${backup_directory}"
