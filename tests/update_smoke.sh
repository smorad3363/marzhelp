#!/usr/bin/env bash

set -Eeuo pipefail

readonly PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"

cleanup() {
    case "$test_root" in
        /tmp/*) rm -rf -- "$test_root" ;;
    esac
}
trap cleanup EXIT

target_remote="${test_root}/target.git"
target_seed="${test_root}/target-seed"
installed_directory="${test_root}/installed"
backup_directory="${test_root}/backups"
mock_bin="${test_root}/bin"
table_marker="${test_root}/table-ran"

git init --bare --quiet "$target_remote"
git init --quiet "$target_seed"
git -C "$target_seed" config user.name "MarzHelp Test"
git -C "$target_seed" config user.email "test@example.invalid"
printf 'new release\n' > "${target_seed}/release.txt"
printf '<?php exit(0);\n' > "${target_seed}/table.php"
cp "${PROJECT_ROOT}/update.sh" "${target_seed}/update.sh"
chmod +x "${target_seed}/update.sh"
git -C "$target_seed" add release.txt table.php update.sh
git -C "$target_seed" commit --quiet -m "target release"
git -C "$target_seed" branch -M main
git -C "$target_seed" remote add origin "$target_remote"
git -C "$target_seed" push --quiet -u origin main

git init --quiet "$installed_directory"
git -C "$installed_directory" config user.name "MarzHelp Test"
git -C "$installed_directory" config user.email "test@example.invalid"
printf 'old release\n' > "${installed_directory}/old-release.txt"
git -C "$installed_directory" add old-release.txt
git -C "$installed_directory" commit --quiet -m "installed release"
git -C "$installed_directory" remote add origin "https://example.invalid/old/marzhelp.git"

cat > "${installed_directory}/config.php" <<'PHP'
<?php
$botDbHost = '127.0.0.1';
$botDbUser = 'root';
$botDbPass = 'secret';
$botDbName = 'marzhelp';
$vpnDbHost = '127.0.0.1';
$vpnDbUser = 'root';
$vpnDbPass = 'secret';
$vpnDbName = 'marzban';
PHP
config_hash_before="$(git hash-object "${installed_directory}/config.php")"

mkdir -p "$mock_bin"
cat > "${mock_bin}/php" <<'MOCKPHP'
#!/usr/bin/env bash
set -e
if [[ "${1:-}" == "-r" ]]; then
    case "${4:-}" in
        botDbHost|vpnDbHost) printf '127.0.0.1' ;;
        botDbUser|vpnDbUser) printf 'root' ;;
        botDbPass|vpnDbPass) printf 'secret' ;;
        botDbName) printf 'marzhelp' ;;
        vpnDbName) printf 'marzban' ;;
        botToken) printf 'test-token' ;;
        botdomain) printf 'example.invalid' ;;
        migrationDbUser|migrationDbPass) ;;
        *)
            case "${2:-}" in
                *webhookSecret*|*migrationDbUser*|*migrationDbPass*) ;;
                *) exit 1 ;;
            esac
            ;;
    esac
elif [[ "${1:-}" == "-l" ]]; then
    exit 0
elif [[ "${1:-}" == */table.php ]]; then
    : > "$TEST_TABLE_MARKER"
elif [[ "${1:-}" == */app/scrub_config.php ]]; then
    sed -i 's/secret//g' "${2}"
else
    exit 1
fi
MOCKPHP
cat > "${mock_bin}/mysqldump" <<'MOCKDUMP'
#!/usr/bin/env bash
printf '%s\n' '-- mock database dump'
MOCKDUMP
cat > "${mock_bin}/mysql" <<'MOCKMYSQL'
#!/usr/bin/env bash
if [[ " $* " == *" -e "* ]]; then
    printf 'smorad3363-marzban|1|9\n'
else
    cat >/dev/null
fi
MOCKMYSQL
cat > "${mock_bin}/curl" <<'MOCKCURL'
#!/usr/bin/env bash
printf '%s\n' '{"ok":true}'
MOCKCURL
cat > "${mock_bin}/crontab" <<'MOCKCRON'
#!/usr/bin/env bash
if [[ "${1:-}" == "-l" ]]; then
    exit 0
fi
cat >/dev/null
MOCKCRON
chmod +x \
    "${mock_bin}/php" \
    "${mock_bin}/mysqldump" \
    "${mock_bin}/mysql" \
    "${mock_bin}/curl" \
    "${mock_bin}/crontab"

PATH="${mock_bin}:${PATH}" \
TEST_TABLE_MARKER="$table_marker" \
MARZHELP_ALLOW_UNPRIVILEGED=1 \
MARZHELP_REPOSITORY="$target_remote" \
MARZHELP_BRANCH="main" \
MARZHELP_DIRECTORY="$installed_directory" \
MARZHELP_BACKUP_DIRECTORY="$backup_directory" \
bash "${PROJECT_ROOT}/update.sh"

[[ -f "${installed_directory}/release.txt" ]]
[[ ! -f "${installed_directory}/old-release.txt" ]]
[[ -f "$table_marker" ]]
[[ "$(git hash-object "${installed_directory}/config.php")" != "$config_hash_before" ]]
grep -q "botDbPass = '';" "${installed_directory}/config.php"
grep -q "vpnDbPass = '';" "${installed_directory}/config.php"
[[ -f "${installed_directory}/config.local.php" ]]
[[ "$(git -C "$installed_directory" remote get-url origin)" == *"/target.git" ]]
[[ -x "${installed_directory}/update.sh" ]]
find "$backup_directory" -name marzhelp.sql -type f -size +0c | grep -q .
find "$backup_directory" -name marzban.sql -type f -size +0c | grep -q .

printf 'update smoke test passed\n'
