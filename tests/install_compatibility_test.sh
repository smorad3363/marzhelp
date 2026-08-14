#!/usr/bin/env bash

set -Eeuo pipefail

readonly PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly TEMP_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEMP_ROOT"' EXIT

mkdir -p "$TEMP_ROOT/bin"
cat > "$TEMP_ROOT/bin/mysql" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "${MOCK_COMPATIBILITY:-}"
EOF
chmod +x "$TEMP_ROOT/bin/mysql"

export MARZHELP_LIB_ONLY=1
# shellcheck source=../install.sh
source "$PROJECT_ROOT/install.sh"
export MARZBAN_ROOT_PASSWORD=test-only
export PATH="$TEMP_ROOT/bin:$PATH"

MOCK_COMPATIBILITY='smorad3363-marzban|1|9' verify_compatible_marzban

if (MOCK_COMPATIBILITY='upstream-marzban|0|0' verify_compatible_marzban) \
    >"$TEMP_ROOT/incompatible.log" 2>&1; then
    echo 'incompatible Marzban was accepted' >&2
    exit 1
fi
grep -q 'Required: smorad3363/Marzban v4, schema 1 with 9 canonical tables' \
    "$TEMP_ROOT/incompatible.log"

printf 'install compatibility tests passed\n'
