#!/usr/bin/env bash

set -Eeuo pipefail

readonly PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() {
    printf 'source regression failed: %s\n' "$1" >&2
    exit 1
}

grep -q 'secret_token=' "${PROJECT_ROOT}/install.sh" \
    || fail "installer does not register an authenticated webhook"
grep -q 'marzhelpValidateWebhookSecret' "${PROJECT_ROOT}/webhook.php" \
    || fail "webhook secret validation is missing"
grep -q 'marzhelpCanManageAdmin' "${PROJECT_ROOT}/bot.php" \
    || fail "admin ownership validation is missing"
grep -q 'marzhelp_deleted_users' "${PROJECT_ROOT}/table.php" \
    || fail "deleted-user ledger migration is missing"
grep -q 'marzhelp_admin_enforcement' "${PROJECT_ROOT}/table.php" \
    || fail "static enforcement schema is missing"
grep -q 'MARZHELP_REF' "${PROJECT_ROOT}/bootstrap.sh" \
    || fail "version selection is missing from bootstrap"

if grep -q 'NOPASSWD' "${PROJECT_ROOT}/install.sh"; then
    fail "installer still grants passwordless sudo"
fi

if grep -q 'chown www-data:www-data /usr/local/bin/marzban' "${PROJECT_ROOT}/install.sh"; then
    fail "installer still transfers ownership of the Marzban executable"
fi

if grep -q 'TIMESTAMPDIFF(MINUTE, NOW(), online_at)' \
    "${PROJECT_ROOT}/bot.php" "${PROJECT_ROOT}/crons/cron.php"; then
    fail "reversed online-user time calculation returned"
fi

if grep -q 'SUM(user_deletions' \
    "${PROJECT_ROOT}/bot.php" "${PROJECT_ROOT}/crons/cron.php"; then
    fail "runtime traffic queries still use the legacy deletion table"
fi

printf 'source regression tests passed\n'
