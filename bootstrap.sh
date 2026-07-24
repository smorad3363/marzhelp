#!/usr/bin/env bash

set -Eeuo pipefail

readonly MARZHELP_RAW_BASE="${MARZHELP_RAW_BASE:-https://raw.githubusercontent.com/smorad3363/marzhelp/production}"
readonly MARZHELP_DIRECTORY="${MARZHELP_DIRECTORY:-/var/www/html/marzhelp}"

if [[ "$(id -u)" -ne 0 ]]; then
    printf 'Please run this command as root.\n' >&2
    exit 1
fi

temporary_script="$(mktemp)"
cleanup() {
    rm -f "$temporary_script"
}
trap cleanup EXIT

if [[ -f "${MARZHELP_DIRECTORY}/config.php" ]]; then
    printf '[MarzHelp] Existing installation detected; starting safe update.\n'
    curl -fsSL "${MARZHELP_RAW_BASE}/update.sh" -o "$temporary_script"
    chmod 700 "$temporary_script"
    bash "$temporary_script"
else
    printf '[MarzHelp] No existing installation detected; starting full installation.\n'
    curl -fsSL "${MARZHELP_RAW_BASE}/install.sh" -o "$temporary_script"
    chmod 700 "$temporary_script"
    bash "$temporary_script" --full
fi
