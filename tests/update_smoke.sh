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
git -C "$target_seed" add release.txt table.php
git -C "$target_seed" commit --quiet -m "target release"
git -C "$target_seed" branch -M production
git -C "$target_seed" remote add origin "$target_remote"
git -C "$target_seed" push --quiet -u origin production

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
        *) exit 1 ;;
    esac
elif [[ "${1:-}" == "-l" ]]; then
    exit 0
elif [[ "${1:-}" == */table.php ]]; then
    : > "$TEST_TABLE_MARKER"
else
    exit 1
fi
MOCKPHP
cat > "${mock_bin}/mysqldump" <<'MOCKDUMP'
#!/usr/bin/env bash
printf '%s\n' '-- mock database dump'
MOCKDUMP
chmod +x "${mock_bin}/php" "${mock_bin}/mysqldump"

PATH="${mock_bin}:${PATH}" \
TEST_TABLE_MARKER="$table_marker" \
MARZHELP_REPOSITORY="$target_remote" \
MARZHELP_BRANCH="production" \
MARZHELP_DIRECTORY="$installed_directory" \
MARZHELP_BACKUP_DIRECTORY="$backup_directory" \
bash "${PROJECT_ROOT}/update.sh"

[[ -f "${installed_directory}/release.txt" ]]
[[ ! -f "${installed_directory}/old-release.txt" ]]
[[ -f "$table_marker" ]]
[[ "$(git hash-object "${installed_directory}/config.php")" == "$config_hash_before" ]]
[[ "$(git -C "$installed_directory" remote get-url origin)" == *"/target.git" ]]
find "$backup_directory" -name marzhelp.sql -type f -size +0c | grep -q .
find "$backup_directory" -name marzban.sql -type f -size +0c | grep -q .

printf 'update smoke test passed\n'
