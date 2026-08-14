#!/usr/bin/env bash

set -Eeuo pipefail

requested_ref="${MARZHELP_REF:-}"
if [[ -z "$requested_ref" ]]; then
    case "${1:-}" in
        -v|--version)
            [[ -n "${2:-}" ]] || {
                printf 'Usage: bootstrap.sh [--version] <tag-or-branch>\n' >&2
                exit 1
            }
            requested_ref="$2"
            shift 2
            ;;
        -h|--help)
            printf 'Usage: bootstrap.sh [--version] <tag-or-branch>\n'
            printf 'Default version: v2\n'
            exit 0
            ;;
        '')
            requested_ref="v2"
            ;;
        *)
            requested_ref="$1"
            shift
            ;;
    esac
fi

if [[ ! "$requested_ref" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ ]] \
   || [[ "$requested_ref" == *".."* ]] \
   || [[ "$requested_ref" == *"//"* ]] \
   || [[ "$requested_ref" == */ ]] \
   || [[ "$requested_ref" == *.lock ]]; then
    printf 'Invalid MarzHelp version: %s\n' "$requested_ref" >&2
    exit 1
fi

readonly MARZHELP_REF="$requested_ref"
readonly MARZHELP_RAW_BASE="${MARZHELP_RAW_BASE:-https://raw.githubusercontent.com/smorad3363/marzhelp/${MARZHELP_REF}}"
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
    printf '[MarzHelp] Existing installation detected; switching to %s.\n' "$MARZHELP_REF"
    curl -fsSL "${MARZHELP_RAW_BASE}/update.sh" -o "$temporary_script"
    chmod 700 "$temporary_script"
    env MARZHELP_REF="$MARZHELP_REF" bash "$temporary_script"
else
    printf '[MarzHelp] No existing installation detected; installing %s.\n' "$MARZHELP_REF"
    curl -fsSL "${MARZHELP_RAW_BASE}/install.sh" -o "$temporary_script"
    chmod 700 "$temporary_script"
    env MARZHELP_REF="$MARZHELP_REF" bash "$temporary_script" --full
fi
