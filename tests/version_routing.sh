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

mock_bin="${test_root}/bin"
installation="${test_root}/installation"
result_file="${test_root}/result"
curl_log="${test_root}/curl.log"
mkdir -p "$mock_bin" "$installation"

cat > "${mock_bin}/id" <<'MOCKID'
#!/usr/bin/env bash
if [[ "${1:-}" == "-u" ]]; then
    printf '0\n'
else
    /usr/bin/id "$@"
fi
MOCKID

cat > "${mock_bin}/curl" <<'MOCKCURL'
#!/usr/bin/env bash
destination=''
url=''
while (($#)); do
    case "$1" in
        -o)
            destination="$2"
            shift 2
            ;;
        http://*|https://*)
            url="$1"
            shift
            ;;
        *)
            shift
            ;;
    esac
done
printf '%s\n' "$url" > "$TEST_CURL_LOG"
cat > "$destination" <<'DOWNLOADED'
#!/usr/bin/env bash
printf '%s|%s\n' "${MARZHELP_REF:-}" "$*" > "$TEST_RESULT_FILE"
DOWNLOADED
MOCKCURL

chmod +x "${mock_bin}/id" "${mock_bin}/curl"

PATH="${mock_bin}:${PATH}" \
TEST_RESULT_FILE="$result_file" \
TEST_CURL_LOG="$curl_log" \
MARZHELP_DIRECTORY="$installation" \
bash "${PROJECT_ROOT}/bootstrap.sh" --version v1.1

grep -q '/v1.1/install.sh$' "$curl_log"
grep -q '^v1.1|--full$' "$result_file"

: > "${installation}/config.php"

PATH="${mock_bin}:${PATH}" \
TEST_RESULT_FILE="$result_file" \
TEST_CURL_LOG="$curl_log" \
MARZHELP_DIRECTORY="$installation" \
bash "${PROJECT_ROOT}/bootstrap.sh" production

grep -q '/production/update.sh$' "$curl_log"
grep -q '^production|$' "$result_file"

if PATH="${mock_bin}:${PATH}" \
    MARZHELP_DIRECTORY="$installation" \
    bash "${PROJECT_ROOT}/bootstrap.sh" '../invalid'; then
    printf 'invalid version was accepted\n' >&2
    exit 1
fi

printf 'version routing tests passed\n'
