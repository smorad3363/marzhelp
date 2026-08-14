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
grep -q 'requireCanonicalSchema' "${PROJECT_ROOT}/table.php" \
    || fail "canonical schema verification is missing"
grep -q 'MARZHELP_SOURCE_ID' "${PROJECT_ROOT}/table.php" \
    || fail "Marzban compatibility marker is missing"
grep -q 'MARZHELP_REF' "${PROJECT_ROOT}/bootstrap.sh" \
    || fail "version selection is missing from bootstrap"
grep -q 'install compatibility tests passed' "${PROJECT_ROOT}/tests/install_compatibility_test.sh" \
    || fail "installer compatibility smoke test is missing"

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

if grep -Eiq 'CREATE[[:space:]]+(TABLE|TRIGGER|EVENT)|ALTER[[:space:]]+TABLE' \
    "${PROJECT_ROOT}/table.php" "${PROJECT_ROOT}/bot.php" "${PROJECT_ROOT}/crons/cron.php"; then
    fail "MarzHelp runtime still performs schema DDL"
fi

if grep -Eiq 'UPDATE[[:space:]]+users[[:space:]]+SET' "${PROJECT_ROOT}/bot.php"; then
    fail "Telegram bulk operations still bypass the Marzban policy API"
fi

grep -q 'MARZHELP_BRANCH:-v2' "${PROJECT_ROOT}/install.sh" \
    || fail "installer is not pinned to v2"

preflight_line=$(grep -n '^verify_compatible_marzban$' "${PROJECT_ROOT}/install.sh" | head -n1 | cut -d: -f1)
config_line=$(grep -n '^check_marzhelp_config$' "${PROJECT_ROOT}/install.sh" | tail -n1 | cut -d: -f1)
if [ -z "$preflight_line" ] || [ -z "$config_line" ] || [ "$preflight_line" -ge "$config_line" ]; then
    fail "compatibility preflight does not run before local configuration changes"
fi

printf 'source regression tests passed\n'
