#!/usr/bin/env bash
#
# verify-env.sh — checks that the store (finderskeep.online, or $DOMAIN) is correctly set up.
# Called at the end of setup-apache.sh; can be run on its own at any time:
#
#     sudo ./verify-env.sh
#
# Checks: Apache + MariaDB running, PHP version/extensions (CLI and inside
# Apache), VirtualHost enabled, HTTP responses through the vhost, sensitive
# files not served, clean URLs routed, database connection with the .env credentials (as the
# Apache user), writable image directories, and hostname resolution.
#
# Exit code: 0 if every check passes, 1 otherwise. Warnings do not fail.
#
set -uo pipefail

DOMAIN="${DOMAIN:-finderskeep.online}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
APP_ROOT="$(cd -- "${APP_ROOT:-$SCRIPT_DIR}" && pwd -P)"
DOC_ROOT="${APP_ROOT}/public"
ENV_FILE="${APP_ROOT}/.env"
APACHE_USER="${APACHE_USER:-www-data}"
REQUIRED_EXTS=(pdo_mysql curl gd dom json zip)
WRITABLE_DIRS=(public/assets/images/generated public/assets/images/products public/assets/images/branding
               public/themes var/cache var/log var/tmp)

if [[ -t 1 ]]; then
    C_GREEN=$'\033[1;32m'; C_YELLOW=$'\033[1;33m'; C_RED=$'\033[1;31m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else
    C_GREEN=""; C_YELLOW=""; C_RED=""; C_BOLD=""; C_RESET=""
fi
PASS=0; FAIL=0; WARN=0
pass() { printf '  %s[PASS]%s %s\n' "$C_GREEN"  "$C_RESET" "$*"; PASS=$((PASS + 1)); }
fail() { printf '  %s[FAIL]%s %s\n' "$C_RED"    "$C_RESET" "$*"; FAIL=$((FAIL + 1)); }
warn() { printf '  %s[WARN]%s %s\n' "$C_YELLOW" "$C_RESET" "$*"; WARN=$((WARN + 1)); }
group() { printf '\n%s%s%s\n' "$C_BOLD" "$*" "$C_RESET"; }

as_user() {
    local u=$1; shift
    if command -v runuser >/dev/null 2>&1; then runuser -u "$u" -- "$@"; else sudo -u "$u" -- "$@"; fi
}

TMP_BODY="$(mktemp)"; PROBE_FILE=""
cleanup() { rm -f -- "$TMP_BODY"; [[ -z "$PROBE_FILE" ]] || rm -f -- "$PROBE_FILE"; }
trap cleanup EXIT

http_get() {  # http_get PATH -> status code; body in $TMP_BODY
    local c
    c="$(curl -s -o "$TMP_BODY" -w '%{http_code}' --max-time 15 \
              -H "Host: ${DOMAIN}" "http://127.0.0.1${1}" 2>/dev/null)" || true
    printf '%s' "${c:-000}"
}

[[ $EUID -eq 0 ]] || { echo "Run as root: sudo $0" >&2; exit 1; }

# ------------------------------------------------------------------ services
group "Services"
if pgrep -x apache2 >/dev/null 2>&1; then pass "apache2 is running"
else fail "apache2 is not running  (sudo service apache2 start)"; fi

if mysqladmin ping >/dev/null 2>&1; then pass "MariaDB is running"
else fail "MariaDB is not running  (sudo service mariadb start)"; fi

# ----------------------------------------------------------------------- PHP
group "PHP (CLI)"
if command -v php >/dev/null 2>&1; then
    ver="$(php -r 'echo PHP_VERSION;')"
    if php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then pass "PHP ${ver} (>= 8.1)"
    else fail "PHP ${ver} is older than 8.1"; fi
    mods="$(php -m 2>/dev/null)"
    for e in "${REQUIRED_EXTS[@]}"; do
        if grep -qix "$e" <<<"$mods"; then pass "extension ${e}"; else fail "extension ${e} missing"; fi
    done
else
    fail "php CLI not installed"
fi

# -------------------------------------------------------------------- Apache
group "Apache"
vhosts="$(apache2ctl -S 2>/dev/null || true)"
if grep -Eq "(namevhost|alias) ${DOMAIN//./\\.}( |$)" <<<"$vhosts"; then pass "VirtualHost for ${DOMAIN} is enabled"
else fail "no enabled VirtualHost for ${DOMAIN}"; fi
amods="$(apache2ctl -M 2>/dev/null || true)"
for m in alias dir headers rewrite; do
    if grep -q "${m}_module" <<<"$amods"; then pass "module ${m}"; else fail "module ${m} not enabled  (a2enmod ${m})"; fi
done
if grep -Eq 'php[0-9._]*_module|proxy_fcgi_module' <<<"$amods"; then pass "PHP handler loaded"
else fail "Apache has no PHP handler  (apt-get install libapache2-mod-php)"; fi

# PHP as Apache runs it (may differ from the CLI).
PROBE_NAME="__verify_$$_${RANDOM}.php"
PROBE_FILE="${DOC_ROOT}/${PROBE_NAME}"
cat > "$PROBE_FILE" <<'PHP'
<?php
header('Content-Type: text/plain');
echo 'VERSION=', PHP_VERSION, "\n";
foreach (get_loaded_extensions() as $e) { echo 'EXT=', strtolower($e), "\n"; }
PHP
chmod 0644 "$PROBE_FILE" 2>/dev/null || true
code="$(http_get "/${PROBE_NAME}")"
rm -f -- "$PROBE_FILE"; PROBE_FILE=""
if [[ "$code" == "200" ]] && grep -q '^VERSION=' "$TMP_BODY"; then
    pass "Apache executes PHP $(sed -n 's/^VERSION=//p' "$TMP_BODY")"
    for e in "${REQUIRED_EXTS[@]}"; do
        grep -qx "EXT=${e}" "$TMP_BODY" || fail "extension ${e} not loaded inside Apache"
    done
elif [[ "$code" == "200" ]]; then
    fail "Apache returns PHP source instead of executing it"
else
    fail "PHP probe through the vhost returned HTTP ${code}"
fi

# ---------------------------------------------------------------------- HTTP
group "HTTP (Host: ${DOMAIN} -> 127.0.0.1)"
check_page() {  # check_page PATH [expected=200]
    local path=$1 want=${2:-200} c
    c="$(http_get "$path")"
    if [[ "$c" == "$want" ]] && ! grep -q '<?php' "$TMP_BODY"; then
        pass "GET ${path} -> ${c}"
    else
        fail "GET ${path} -> ${c} (expected ${want})"
        tail -n 3 "/var/log/apache2/${DOMAIN}_error.log" 2>/dev/null | sed 's/^/           /'
    fi
}
check_page /
check_page /cart
check_page /admin/login
check_page /no-such-page 404
check_page /cart.php 301          # old URLs redirect to the clean ones

for path in /.env /../.env /.git/config /composer.json /themes/default/layout/header.html.twig; do
    c="$(http_get "$path")"
    if [[ "$c" == "200" ]]; then fail "GET ${path} is publicly readable (HTTP 200)"
    elif [[ "$c" == "000" ]]; then fail "GET ${path} -> no response"
    else pass "GET ${path} blocked (${c})"; fi
done

# ------------------------------------------------------------------ database
group "Database (.env credentials, as ${APACHE_USER})"
if [[ ! -f "$ENV_FILE" ]]; then
    fail ".env not found at ${ENV_FILE}"
elif ! as_user "$APACHE_USER" test -r "$ENV_FILE"; then
    fail "${APACHE_USER} cannot read ${ENV_FILE}"
else
    pass ".env present and readable by ${APACHE_USER}"
    db_out="$(cd "$APP_ROOT" && as_user "$APACHE_USER" php -r '
        require "vendor/autoload.php";
        try {
            $container = App\Kernel::boot(getcwd())->container();
            $pdo = $container->get(App\Infrastructure\Database::class)->pdo();
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $need = ["categories","products","product_variants","orders","order_items","login_attempts","page_views"];
            $missing = array_diff($need, $tables);
            echo "TABLES=", count($tables), "\n";
            echo "MISSING=", implode(",", $missing), "\n";
            echo "PRODUCTS=", $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(), "\n";
        } catch (Throwable $e) { echo "ERROR=", $e->getMessage(), "\n"; exit(1); }
        $hash = (string) $container->get(App\Support\Config::class)->get("ADMIN_PASSWORD_HASH", "");
        echo str_starts_with($hash, "$2") ? "ADMIN=ok\n" : "ADMIN=missing\n";
    ' 2>&1)"
    if grep -q '^TABLES=' <<<"$db_out"; then
        pass "PDO connected to $(sed -n 's/^DB_NAME=//p' "$ENV_FILE" | tr -d '\r"'"'"'') ($(sed -n 's/^TABLES=//p' <<<"$db_out") tables, $(sed -n 's/^PRODUCTS=//p' <<<"$db_out") products)"
        missing="$(sed -n 's/^MISSING=//p' <<<"$db_out")"
        if [[ -z "$missing" ]]; then pass "all core tables present"; else fail "missing tables: ${missing}"; fi
        if grep -q '^ADMIN=ok' <<<"$db_out"; then pass "ADMIN_PASSWORD_HASH set"
        else fail "ADMIN_PASSWORD_HASH not set in .env (admin login impossible)"; fi
    else
        fail "database connection failed: $(sed -n 's/^ERROR=//p' <<<"$db_out" | head -c 300)"
        grep -v '^ERROR=' <<<"$db_out" | head -n 3 | sed 's/^/           /'
    fi
fi

# ------------------------------------------------------------- file system
group "File system"
for d in "${WRITABLE_DIRS[@]}"; do
    if as_user "$APACHE_USER" test -w "${APP_ROOT}/${d}"; then pass "${APACHE_USER} can write ${d}"
    else fail "${APACHE_USER} cannot write ${d}"; fi
done

# ------------------------------------------------------------- resolution
group "Name resolution"
resolved="$(getent hosts "$DOMAIN" | awk '{print $1; exit}')"
if [[ "$resolved" == "127.0.0.1" || "$resolved" == "::1" ]]; then
    pass "${DOMAIN} resolves to ${resolved}"
    c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://${DOMAIN}/" 2>/dev/null)" || true
    if [[ "$c" == "200" ]]; then pass "http://${DOMAIN}/ -> 200"; else fail "http://${DOMAIN}/ -> ${c}"; fi
elif [[ -n "$resolved" ]]; then
    warn "${DOMAIN} resolves to ${resolved} (public DNS), not this machine"
else
    warn "${DOMAIN} does not resolve; add '127.0.0.1 ${DOMAIN} www.${DOMAIN}' to the hosts file for local testing"
fi

# ------------------------------------------------------------------ summary
printf '\n%sResult:%s %d passed, %d failed, %d warnings\n' "$C_BOLD" "$C_RESET" "$PASS" "$FAIL" "$WARN"
(( FAIL == 0 ))
