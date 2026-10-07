#!/usr/bin/env bash
#
# setup-apache.sh — full server bootstrap for the store at finderskeep.online
# (override with DOMAIN=other.example to set up a different domain)
#
# Run from a fresh `git clone` on Debian/Ubuntu:
#
#     sudo ./setup-apache.sh
#
# Works on plain Debian/Ubuntu and on WSL (with or without systemd), whether
# the clone lives on the Linux filesystem (/var/www, ~/...) or on the Windows
# drive (/mnt/c/...).
#
# Steps (each one is safe to re-run):
#   1. Packages   — installs anything missing: Apache, mod_php, PHP extensions
#                   (pdo_mysql, curl, gd, dom, zip), MariaDB, Composer, curl.
#   2. .env       — keeps an existing .env, or asks for values and writes one.
#                   Known values (DB host/port, APP_URL, model names) get defaults.
#   3. Database   — creates the database and a dedicated user with access to it
#                   only, imports database/schema.sql if the DB is empty, then
#                   applies any database/migrations/*.sql not applied yet
#                   (tracked in the `_setup_migrations` table).
#   4. Composer   — `composer install --no-dev` as the repository owner, plus
#                   writable image directories for Apache.
#   5. Apache     — enables alias/dir/headers/rewrite, writes and enables the
#                   $DOMAIN VirtualHost (skipped if it already exists),
#                   configtest, reload. A failure here rolls back the vhost.
#   6. Hosts      — maps $DOMAIN to 127.0.0.1 in /etc/hosts (and, on WSL,
#                   in the Windows hosts file when writable, so the Windows
#                   browser reaches it too). Skip with ADD_HOSTS=0.
#   7. Verify     — runs ./verify-env.sh (services, PHP, HTTP, DB connection).
#
# Non-interactive use: export any of the .env keys (plus ADMIN_PASSWORD and,
# if needed, DB_ROOT_PASS) and run with NONINTERACTIVE=1, e.g.
#   sudo NONINTERACTIVE=1 DB_PASS=... ADMIN_PASSWORD=... ./setup-apache.sh
#
# Other overrides: DOMAIN, APP_ROOT, APACHE_USER, ASSUME_YES=1 (auto-accept
# package installation), ADD_HOSTS=0 (leave hosts files alone).
#
set -Eeuo pipefail

# ---------------------------------------------------------------- config ----
DOMAIN="${DOMAIN:-finderskeep.online}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
APP_ROOT="${APP_ROOT:-$SCRIPT_DIR}"
APP_ROOT="$(cd -- "$APP_ROOT" && pwd -P)"
DOC_ROOT="${APP_ROOT}/public"
ENV_FILE="${APP_ROOT}/.env"
SCHEMA_FILE="${APP_ROOT}/database/schema.sql"
APACHE_USER="${APACHE_USER:-www-data}"
ASSUME_YES="${ASSUME_YES:-0}"
ADD_HOSTS="${ADD_HOSTS:-1}"
MIGRATIONS_DIR="${APP_ROOT}/database/migrations"
VERIFY_SCRIPT="${APP_ROOT}/verify-env.sh"
# Directories the app writes to at runtime (relative to the project root):
# images, uploaded themes, and var/ (Twig cache, logs, temporary uploads).
WRITABLE_DIRS=(public/assets/images/generated public/assets/images/products public/assets/images/branding public/assets/images/categories
               public/themes var var/cache var/log var/tmp)

SITES_AVAILABLE="/etc/apache2/sites-available"
SITE_NAME="${DOMAIN}.conf"
VHOST_FILE="${SITES_AVAILABLE}/${SITE_NAME}"

VERIFY_TIMEOUT=10
VERIFY_RETRIES=5

# Keys the app reads (see .env.example and src/Support/Config.php), in .env.example order.
KNOWN_KEYS=(DB_HOST DB_PORT DB_NAME DB_USER DB_PASS APP_URL
            STORE_NAME STORE_EMAIL STORE_CURRENCY THEME
            ADMIN_USERNAME ADMIN_PASSWORD_HASH
            OXAPAY_MERCHANT_KEY OXAPAY_SANDBOX
            GEMINI_API_KEY GEMINI_MODEL GEMINI_IMAGE_MODEL)

INTERACTIVE=1
if [[ "${NONINTERACTIVE:-0}" == "1" ]] || ! [[ -t 0 ]]; then INTERACTIVE=0; fi

# Runtime state
VHOST_CREATED=0
PROBE_FILE=""
TMP_BODY=""
TMP_FILES=()
DB_ADMIN_CNF=""
MYSQL_BIN=""
DB_PASS_GENERATED=0
IS_WSL=0
ON_WINFS=0          # repo on a Windows drive (drvfs / 9p): no real chown/chmod
EXTRA_ENV_LINES=()
declare -A PRESET=()

# --------------------------------------------------------------- logging ----
if [[ -t 1 ]]; then
    C_BLUE=$'\033[1;34m'; C_GREEN=$'\033[1;32m'; C_YELLOW=$'\033[1;33m'
    C_RED=$'\033[1;31m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else
    C_BLUE=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_BOLD=""; C_RESET=""
fi
log()     { printf '%s[INFO]%s  %s\n' "$C_BLUE"   "$C_RESET" "$*"; }
ok()      { printf '%s[ OK ]%s  %s\n' "$C_GREEN"  "$C_RESET" "$*"; }
warn()    { printf '%s[WARN]%s  %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()     { printf '%s[FAIL]%s  %s\n' "$C_RED"    "$C_RESET" "$*" >&2; exit 1; }
section() { printf '\n%s==> %s%s\n' "$C_BOLD" "$*" "$C_RESET"; }

# ------------------------------------------------------------ utilities ----
trim() {
    local s=$1
    s="${s#"${s%%[![:space:]]*}"}"
    s="${s%"${s##*[![:space:]]}"}"
    printf '%s' "$s"
}

is_known_key() {
    local k
    for k in "${KNOWN_KEYS[@]}"; do
        if [[ "$k" == "$1" ]]; then return 0; fi
    done
    return 1
}

gen_password() {
    local p
    p="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    printf '%s' "${p:0:24}"
}

make_tmp() {
    local f
    f="$(mktemp)"
    chmod 600 "$f"
    TMP_FILES+=("$f")
    printf '%s' "$f"
}

# confirm "Question" [Y|N]  — default answer used when non-interactive
confirm() {
    local def="${2:-Y}" hint reply
    if (( ! INTERACTIVE )); then [[ "$def" == "Y" ]]; return; fi
    if [[ "$def" == "Y" ]]; then hint="Y/n"; else hint="y/N"; fi
    read -r -p "  $1 [$hint]: " reply </dev/tty
    reply="${reply:-$def}"
    [[ "$reply" =~ ^[Yy] ]]
}

# ask VAR "Prompt" [fallback]
#   Skips if VAR was preset in the environment. Default shown = current value
#   of VAR (e.g. from an existing .env) or the fallback.
ask() {
    local __var=$1 __prompt=$2 __fallback=${3-} __def __reply
    if [[ -n "${PRESET[$__var]:-}" ]]; then return 0; fi
    __def="${!__var:-$__fallback}"
    if (( ! INTERACTIVE )); then
        [[ -n "$__def" ]] || die "$__var is required. Export it for non-interactive runs."
        printf -v "$__var" '%s' "$__def"
        return 0
    fi
    while :; do
        read -r -p "  ${__prompt}${__def:+ [$__def]}: " __reply </dev/tty
        __reply="$(trim "${__reply:-$__def}")"
        if [[ -n "$__reply" ]]; then break; fi
        warn "A value is required."
    done
    printf -v "$__var" '%s' "$__reply"
}

# ask_optional VAR "Prompt" [fallback] — may be left empty
ask_optional() {
    local __var=$1 __prompt=$2 __fallback=${3-} __def __reply
    if [[ -n "${PRESET[$__var]:-}" ]]; then return 0; fi
    __def="${!__var:-$__fallback}"
    if (( ! INTERACTIVE )); then printf -v "$__var" '%s' "$__def"; return 0; fi
    read -r -p "  ${__prompt}${__def:+ [$__def]} (optional): " __reply </dev/tty
    printf -v "$__var" '%s' "$(trim "${__reply:-$__def}")"
}

# ask_secret VAR "Prompt" MODE   MODE: required|optional|confirm
#   Hidden input. If VAR already has a value, blank input keeps it.
ask_secret() {
    local __var=$1 __prompt=$2 __mode=${3:-required} __cur __a __b __hint=""
    if [[ -n "${PRESET[$__var]:-}" ]]; then return 0; fi
    __cur="${!__var:-}"
    if (( ! INTERACTIVE )); then
        if [[ -z "$__cur" && "$__mode" != "optional" ]]; then
            die "$__var is required. Export it for non-interactive runs."
        fi
        return 0
    fi
    if [[ -n "$__cur" ]]; then __hint=" (blank = keep current)"
    elif [[ "$__mode" == "optional" ]]; then __hint=" (optional)"; fi
    while :; do
        read -r -s -p "  ${__prompt}${__hint}: " __a </dev/tty; echo
        if [[ -z "$__a" ]]; then
            if [[ -n "$__cur" || "$__mode" == "optional" ]]; then return 0; fi
            warn "A value is required."; continue
        fi
        if [[ "$__mode" == "confirm" ]]; then
            read -r -s -p "  Repeat to confirm: " __b </dev/tty; echo
            if [[ "$__a" != "$__b" ]]; then warn "Values do not match, try again."; continue; fi
        fi
        break
    done
    printf -v "$__var" '%s' "$__a"
}

# Parse .env the same way src/Support/Config.php does. Never `source` it.
load_env_file() {
    local line key val
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="$(trim "${line%$'\r'}")"
        if [[ -z "$line" || "${line:0:1}" == "#" || "$line" != *=* ]]; then continue; fi
        key="$(trim "${line%%=*}")"
        val="$(trim "${line#*=}")"
        if (( ${#val} >= 2 )) && [[ ( "${val:0:1}" == '"' && "${val: -1}" == '"' ) ||
                                    ( "${val:0:1}" == "'" && "${val: -1}" == "'" ) ]]; then
            val="${val:1:${#val}-2}"
        fi
        if is_known_key "$key"; then
            if [[ -z "${PRESET[$key]:-}" ]]; then printf -v "$key" '%s' "$val"; fi
        else
            EXTRA_ENV_LINES+=("$line")
        fi
    done < "$ENV_FILE"
}

# Escape for a MySQL option-file quoted value / SQL string literal.
cnf_escape() { local s=${1//\\/\\\\}; s=${s//\"/\\\"}; printf '%s' "$s"; }
sql_escape() { local s=${1//\\/\\\\}; s=${s//\'/\\\'}; printf '%s' "$s"; }

is_systemd() { command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; }

reload_apache() {
    if is_systemd; then
        if systemctl is-active --quiet apache2; then systemctl reload apache2; else systemctl start apache2; fi
    else
        if service apache2 status >/dev/null 2>&1; then service apache2 reload; else service apache2 start; fi
    fi
}

as_user() {  # as_user USER cmd...
    local u=$1; shift
    if command -v runuser >/dev/null 2>&1; then runuser -u "$u" -- "$@"; else sudo -u "$u" -- "$@"; fi
}

http_get() {  # http_get <path> -> prints status; body in $TMP_BODY
    local code
    code="$(curl -s -o "$TMP_BODY" -w '%{http_code}' --max-time "$VERIFY_TIMEOUT" \
                 -H "Host: ${DOMAIN}" "http://127.0.0.1${1}" 2>/dev/null)" || true
    printf '%s' "${code:-000}"
}

cleanup() {
    local rc=$? f
    if [[ -n "$PROBE_FILE" && -f "$PROBE_FILE" ]]; then rm -f -- "$PROBE_FILE"; fi
    if [[ -n "$TMP_BODY" && -f "$TMP_BODY" ]]; then rm -f -- "$TMP_BODY"; fi
    for f in "${TMP_FILES[@]}"; do rm -f -- "$f"; done
    if (( rc != 0 && VHOST_CREATED == 1 )); then
        warn "Rolling back: disabling and removing ${VHOST_FILE}"
        a2dissite -q "$SITE_NAME" >/dev/null 2>&1 || true
        rm -f -- "$VHOST_FILE"
        if apache2ctl configtest >/dev/null 2>&1; then reload_apache >/dev/null 2>&1 || true; fi
        warn "Rollback complete. Fix the problem above and re-run the script."
    fi
    exit "$rc"
}
trap cleanup EXIT

# ============================================================ pre-flight ====
[[ $EUID -eq 0 ]] || die "Must run as root. Try: sudo $0"
[[ -f "$DOC_ROOT/index.php" ]] || die "$DOC_ROOT/index.php not found — is APP_ROOT ($APP_ROOT) the store repository?"
[[ -f "$SCHEMA_FILE" ]]        || die "Schema not found: $SCHEMA_FILE"

for k in "${KNOWN_KEYS[@]}" ADMIN_PASSWORD DB_ROOT_PASS; do
    if [[ -n "${!k:-}" ]]; then PRESET[$k]=1; fi
done

if grep -qiE 'microsoft|wsl' /proc/sys/kernel/osrelease 2>/dev/null; then IS_WSL=1; fi
repo_fstype="$(findmnt -no FSTYPE -T "$APP_ROOT" 2>/dev/null || stat -f -c %T "$APP_ROOT" 2>/dev/null || true)"
case "$repo_fstype" in
    9p|v9fs|drvfs|fuse.drvfs) ON_WINFS=1 ;;
esac
# Git on Windows may have checked files out with CRLF line endings, which
# breaks shell scripts and .env parsing on Linux. Catch it early.
if grep -q $'\r' "$SCHEMA_FILE" 2>/dev/null || grep -q $'\r' "$VERIFY_SCRIPT" 2>/dev/null; then
    warn "Files use Windows (CRLF) line endings. If anything misbehaves, re-clone with:"
    warn "  git config --global core.autocrlf input"
fi

REPO_OWNER="$(stat -c %U "$APP_ROOT")"
log "Project: $APP_ROOT (owner: $REPO_OWNER, filesystem: ${repo_fstype:-unknown})"
if (( IS_WSL )); then
    log "WSL detected$( (( ON_WINFS )) && echo ' — repository is on the Windows drive' )."
    is_systemd || log "systemd is not running; services are managed with 'service'."
fi
(( INTERACTIVE )) || log "Running non-interactively."

# ============================================================ 1. packages ===
section "1/7  System packages"

# (capture output first: `cmd | grep -q` can SIGPIPE `cmd` and fail under pipefail)
php_has_ext() {
    local mods
    command -v php >/dev/null 2>&1 || return 1
    mods="$(php -m 2>/dev/null)" || return 1
    grep -qix "$1" <<<"$mods"
}
apache_has_php() {
    local mods
    command -v apache2ctl >/dev/null 2>&1 || return 1
    mods="$(apache2ctl -M 2>/dev/null)" || true
    if grep -Eq 'php[0-9._]*_module' <<<"$mods"; then return 0; fi
    grep -q proxy_fcgi_module <<<"$mods" \
        && compgen -G "/etc/apache2/conf-enabled/php*-fpm.conf" >/dev/null
}
have_db_server() {
    command -v mariadbd >/dev/null 2>&1 || command -v mysqld >/dev/null 2>&1 \
        || [[ -x /usr/sbin/mariadbd || -x /usr/sbin/mysqld ]]
}
pkg_available() {
    local pol
    pol="$(apt-cache policy "$1" 2>/dev/null)" || return 1
    grep -Eq 'Candidate: [^(]' <<<"$pol"
}
start_service() {  # start_service name [alt-name]
    local n
    for n in "$@"; do
        if is_systemd; then
            systemctl enable --now "$n" >/dev/null 2>&1 && return 0
        else
            service "$n" start >/dev/null 2>&1 && return 0
        fi
    done
    return 1
}

# PHP extension -> Debian package suffix. Apache's mod_php and the CLI must be
# the same PHP version, so when PHP is already installed pin the versioned
# packages (php8.3-gd, libapache2-mod-php8.3, ...) to the CLI's version.
declare -A EXT_PKG=([pdo_mysql]=mysql [curl]=curl [gd]=gd [dom]=xml [zip]=zip)
REQUIRED_EXTS=(pdo_mysql curl gd dom zip)
PHP_PREFIX="php"
if command -v php >/dev/null 2>&1; then
    PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    PHP_PREFIX="php${PHP_VER}"
fi

PKGS=()
command -v apache2ctl >/dev/null 2>&1 || PKGS+=(apache2)
command -v curl       >/dev/null 2>&1 || PKGS+=(curl)
command -v php        >/dev/null 2>&1 || PKGS+=(php-cli)
for ext in "${REQUIRED_EXTS[@]}"; do
    php_has_ext "$ext" || PKGS+=("${PHP_PREFIX}-${EXT_PKG[$ext]}")
done
if ! apache_has_php; then
    if [[ "$PHP_PREFIX" == "php" ]]; then PKGS+=(libapache2-mod-php); else PKGS+=("libapache2-mod-${PHP_PREFIX}"); fi
fi
have_db_server                        || PKGS+=(mariadb-server)
command -v composer   >/dev/null 2>&1 || PKGS+=(composer)
command -v git        >/dev/null 2>&1 || PKGS+=(git)   # Composer fetches Twig from GitHub

if (( ${#PKGS[@]} )); then
    log "Missing packages: ${PKGS[*]}"
    command -v apt-get >/dev/null 2>&1 || die "apt-get not found; install these manually: ${PKGS[*]}"
    if [[ "$ASSUME_YES" == "1" ]] || confirm "Install them now with apt-get?" Y; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq || warn "apt-get update reported errors; continuing."
        # A PHP installed from a third-party repo may have no matching versioned
        # packages here. Fall back to the distribution's PHP for Apache then,
        # with its own extensions (the CLI keeps its version).
        RESOLVED=(); DISTRO_PHP_FALLBACK=0
        for pkg in "${PKGS[@]}"; do
            if [[ "$pkg" == "$PHP_PREFIX"-* || "$pkg" == "libapache2-mod-$PHP_PREFIX" ]] \
                    && [[ "$PHP_PREFIX" != "php" ]] && ! pkg_available "$pkg"; then
                warn "$pkg is not available from the configured apt sources."
                RESOLVED+=("${pkg/$PHP_PREFIX/php}"); DISTRO_PHP_FALLBACK=1
            else
                RESOLVED+=("$pkg")
            fi
        done
        if (( DISTRO_PHP_FALLBACK )); then
            RESOLVED+=(php-mysql php-curl php-gd php-xml php-zip)
            warn "Using the distribution's PHP packages instead (Apache may run a different PHP version than the CLI)."
        fi
        mapfile -t PKGS < <(printf '%s\n' "${RESOLVED[@]}" | awk '!seen[$0]++')
        log "Installing: ${PKGS[*]}"
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${PKGS[@]}"
        ok "Packages installed."
    else
        die "Required packages missing: ${PKGS[*]}"
    fi
else
    ok "All required packages present."
fi

php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' \
    || die "PHP 8.1+ required (found $(php -r 'echo PHP_VERSION;'))."
for ext in "${REQUIRED_EXTS[@]}"; do
    php_has_ext "$ext" || die "PHP extension '$ext' is still missing after installation."
done
ok "PHP $(php -r 'echo PHP_VERSION;') with ${REQUIRED_EXTS[*]}."
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
apache_php_load="$(compgen -G '/etc/apache2/mods-enabled/php*.load' | head -n1 || true)"
if [[ -n "$apache_php_load" && "$apache_php_load" != *"php${PHP_VER}.load" ]]; then
    warn "Apache loads $(basename "$apache_php_load" .load) but the CLI is PHP ${PHP_VER}."
    warn "  Extensions installed for the CLI may be missing in Apache (verify-env.sh checks this)."
    if [[ -e "/etc/apache2/mods-available/php${PHP_VER}.load" ]]; then
        warn "  To align them: a2dismod $(basename "$apache_php_load" .load) && a2enmod php${PHP_VER}"
    fi
fi

MYSQL_BIN="$(command -v mariadb || command -v mysql)" || die "MariaDB/MySQL client not found."
id "$APACHE_USER" >/dev/null 2>&1 || die "Apache user '$APACHE_USER' does not exist (set APACHE_USER=...)."
APACHE_GROUP="$(id -gn "$APACHE_USER")"

# Make sure the database server is running (WSL does not start services on
# boot unless systemd is enabled).
if ! mysqladmin ping >/dev/null 2>&1; then
    log "Starting database server"
    start_service mariadb mysql || true
    for _ in {1..20}; do
        if mysqladmin ping >/dev/null 2>&1; then break; fi
        sleep 1
    done
fi
mysqladmin ping >/dev/null 2>&1 || die "The MariaDB server is not running and could not be started.
       Try: service mariadb start   (logs: /var/log/mysql/error.log)"
ok "MariaDB is running."

# Apache must be able to read the code.
winfs_help() {
    cat >&2 <<EOF
       The repository is on the Windows drive, where Linux permissions come from
       the mount options. Add this to /etc/wsl.conf inside WSL:

           [automount]
           options = "metadata,umask=022"

       then run  wsl --shutdown  in PowerShell, reopen WSL and re-run this script.
       (Or clone the repository on the Linux side, e.g. under /var/www.)
EOF
}
if ! as_user "$APACHE_USER" test -r "$DOC_ROOT/index.php"; then
    if (( ON_WINFS )); then
        warn "User '$APACHE_USER' cannot read $DOC_ROOT/index.php."
        winfs_help; exit 1
    fi
    if ! as_user "$APACHE_USER" test -x "$(dirname -- "$APP_ROOT")"; then
        die "User '$APACHE_USER' cannot reach $APP_ROOT (a parent directory is not traversable,
       common under /root or /home/<user>). Inspect with:  namei -l '$APP_ROOT'
       Move the repo under /var/www, or grant execute on each parent directory."
    fi
    warn "User '$APACHE_USER' cannot read files inside the repository."
    if confirm "Give group '$APACHE_GROUP' read access to $APP_ROOT (chgrp -R + chmod -R g+rX)?" Y; then
        chgrp -R "$APACHE_GROUP" "$APP_ROOT"
        chmod -R g+rX "$APP_ROOT"
        as_user "$APACHE_USER" test -r "$DOC_ROOT/index.php" || die "Still not readable by '$APACHE_USER'."
        ok "Permissions fixed."
    else
        die "Apache cannot read $DOC_ROOT/index.php."
    fi
fi

# ============================================================ 2. .env =======
section "2/7  Application configuration (.env)"

WRITE_ENV=1
if [[ -f "$ENV_FILE" ]]; then
    load_env_file
    if confirm "An .env already exists. Keep it as is?" Y; then
        WRITE_ENV=0
        ok "Keeping existing .env."
        for k in DB_NAME DB_USER DB_PASS; do
            [[ -n "${!k:-}" ]] || die "Existing .env has no $k. Re-run and choose not to keep it."
        done
    else
        log "Existing values are shown as defaults; press Enter to keep them."
    fi
fi

if (( WRITE_ENV )); then
    # Known values — set without asking unless already defined.
    DB_HOST="${DB_HOST:-localhost}"
    DB_PORT="${DB_PORT:-3306}"

    echo "  -- Database --"
    ask DB_NAME "Database name" "online_store"
    ask DB_USER "Database user" "store_user"
    if [[ -z "${DB_PASS:-}" && -z "${PRESET[DB_PASS]:-}" && $INTERACTIVE -eq 1 ]]; then
        echo "  (leave the password blank to generate a strong random one)"
    fi
    ask_secret DB_PASS "Database password" optional
    if [[ -z "${DB_PASS:-}" ]]; then
        DB_PASS="$(gen_password)"; DB_PASS_GENERATED=1
        ok "Generated a random database password (stored in .env)."
    fi

    echo "  -- Application --"
    if (( IS_WSL )); then app_url_default="http://${DOMAIN}"; else app_url_default="https://${DOMAIN}"; fi
    ask APP_URL "Public base URL" "$app_url_default"
    ask STORE_NAME "Store name" "Finders Keep"
    ask STORE_EMAIL "Customer support email" "support@${DOMAIN}"
    ask STORE_CURRENCY "Currency (ISO code)" "EUR"
    ask THEME "Theme (default, minimal, warm, bold, neolab)" "default"

    echo "  -- Admin panel --"
    ask ADMIN_USERNAME "Admin username" "admin"
    if [[ -n "${ADMIN_PASSWORD:-}" ]]; then
        :   # provided via environment
    elif [[ -n "${PRESET[ADMIN_PASSWORD_HASH]:-}" ]]; then
        :
    else
        [[ -n "${ADMIN_PASSWORD_HASH:-}" ]] || ADMIN_PASSWORD=""
        while :; do
            ask_secret ADMIN_PASSWORD "Admin password (min. 10 chars)" \
                "$([[ -n "${ADMIN_PASSWORD_HASH:-}" ]] && echo optional || echo confirm)"
            if [[ -z "${ADMIN_PASSWORD:-}" || ${#ADMIN_PASSWORD} -ge 10 ]]; then break; fi
            warn "Password too short."; ADMIN_PASSWORD=""
            (( INTERACTIVE )) || die "ADMIN_PASSWORD must be at least 10 characters."
        done
    fi
    if [[ -n "${ADMIN_PASSWORD:-}" ]]; then
        (( ${#ADMIN_PASSWORD} >= 10 )) || die "ADMIN_PASSWORD must be at least 10 characters."
        ADMIN_PASSWORD_HASH="$(printf '%s' "$ADMIN_PASSWORD" \
            | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);')"
        unset ADMIN_PASSWORD
    fi
    [[ "${ADMIN_PASSWORD_HASH:-}" == \$2* ]] || die "Could not produce an admin password hash."

    echo "  -- OxaPay --"
    ask_secret OXAPAY_MERCHANT_KEY "OxaPay merchant key" optional
    while :; do
        ask OXAPAY_SANDBOX "OxaPay sandbox mode (true/false)" "true"
        if [[ "$OXAPAY_SANDBOX" == "true" || "$OXAPAY_SANDBOX" == "false" ]]; then break; fi
        warn "Enter 'true' or 'false'."; OXAPAY_SANDBOX=""
        (( INTERACTIVE )) || die "OXAPAY_SANDBOX must be true or false."
    done

    echo "  -- Gemini (product image generation) --"
    ask_secret   GEMINI_API_KEY     "Gemini API key" optional
    ask_optional GEMINI_MODEL       "Gemini text model"  "gemini-flash-latest"
    ask_optional GEMINI_IMAGE_MODEL "Gemini image model" "gemini-2.5-flash-image"
fi

# Validate identifiers before they go anywhere near SQL.
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "DB_NAME may only contain letters, digits and underscores."
[[ "$DB_USER" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "DB_USER may only contain letters, digits and underscores (max 32)."
[[ "$DB_PASS" == "$(trim "$DB_PASS")" ]] || die "DB_PASS must not start or end with whitespace (the app's .env parser trims it)."
case "$DB_HOST" in
    localhost|127.0.0.1) ;;
    *) die "DB_HOST=$DB_HOST: this script only provisions a local database server." ;;
esac

# ============================================================ 3. database ===
section "3/7  Database"

DB_ADMIN_CNF="$(make_tmp)"
printf '[client]\nuser=root\n' > "$DB_ADMIN_CNF"
if [[ -n "${DB_ROOT_PASS:-}" ]] || ! "$MYSQL_BIN" --defaults-extra-file="$DB_ADMIN_CNF" -e 'SELECT 1' >/dev/null 2>&1; then
    log "Root socket login unavailable; database root credentials needed."
    ask_secret DB_ROOT_PASS "MariaDB/MySQL root password" required
    printf '[client]\nuser=root\npassword="%s"\n' "$(cnf_escape "$DB_ROOT_PASS")" > "$DB_ADMIN_CNF"
    "$MYSQL_BIN" --defaults-extra-file="$DB_ADMIN_CNF" -e 'SELECT 1' >/dev/null 2>&1 \
        || die "Cannot connect to the database server as root."
fi
db_admin() { "$MYSQL_BIN" --defaults-extra-file="$DB_ADMIN_CNF" -N -B "$@"; }

app_login_ok() {
    local cnf
    cnf="$(make_tmp)"
    printf '[client]\nuser="%s"\npassword="%s"\n' "$DB_USER" "$(cnf_escape "$DB_PASS")" > "$cnf"
    "$MYSQL_BIN" --defaults-extra-file="$cnf" -h localhost -e 'SELECT 1' >/dev/null 2>&1
}

DB_PASS_SQL="$(sql_escape "$DB_PASS")"

db_admin <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL
ok "Database '${DB_NAME}' ready."

user_exists="$(db_admin -e "SELECT COUNT(*) FROM mysql.user WHERE User='${DB_USER}' AND Host='localhost';")"
if [[ "$user_exists" == "0" ]]; then
    db_admin <<SQL
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
SQL
    ok "Created user '${DB_USER}'@'localhost'."
elif app_login_ok; then
    ok "User '${DB_USER}'@'localhost' already exists and the password matches."
else
    warn "User '${DB_USER}'@'localhost' exists with a different password."
    if confirm "Reset its password to the one in the configuration?" N; then
        db_admin <<SQL
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
SQL
        ok "Password updated."
    else
        die "Password mismatch for '${DB_USER}'. Use the correct password or allow the reset."
    fi
fi

db_admin <<SQL
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
app_login_ok || die "Cannot log in as '${DB_USER}' after provisioning."
ok "Granted '${DB_USER}' full access to '${DB_NAME}' only."

strip_db_lines() {  # SQL files hard-code `online_store`; import into $DB_NAME instead
    sed -E '/^[[:space:]]*CREATE[[:space:]]+DATABASE[[:space:]]/Id; /^[[:space:]]*USE[[:space:]]/Id' "$1"
}

MIGRATIONS=()
if [[ -d "$MIGRATIONS_DIR" ]]; then
    mapfile -t MIGRATIONS < <(find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name '*.sql' -printf '%f\n' | LC_ALL=C sort)
fi

table_count="$(db_admin -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")"
FRESH_IMPORT=0
if [[ "$table_count" == "0" ]]; then
    log "Importing $SCHEMA_FILE into '${DB_NAME}'"
    strip_db_lines "$SCHEMA_FILE" | db_admin "$DB_NAME"
    FRESH_IMPORT=1
    ok "Schema imported ($(db_admin -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';") tables)."
else
    ok "Database already has ${table_count} tables; schema import skipped."
fi

# ---- migrations ----------------------------------------------------------
# schema.sql already contains every migration that exists today, so after a
# fresh import they are only recorded. On an existing database each file not
# yet recorded is applied; "already exists" errors mean it was applied by hand
# before this tracking existed, so it is recorded too.
db_admin "$DB_NAME" <<'SQL'
CREATE TABLE IF NOT EXISTS _setup_migrations (
    filename   VARCHAR(191) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    note       VARCHAR(64) NOT NULL DEFAULT 'applied'
) ENGINE=InnoDB;
SQL
record_migration() {
    db_admin "$DB_NAME" -e "INSERT IGNORE INTO _setup_migrations (filename, note) VALUES ('$(sql_escape "$1")', '$2');"
}
mig_applied=0; mig_recorded=0
for m in "${MIGRATIONS[@]}"; do
    done_count="$(db_admin "$DB_NAME" -e "SELECT COUNT(*) FROM _setup_migrations WHERE filename='$(sql_escape "$m")';")"
    [[ "$done_count" == "0" ]] || continue
    if (( FRESH_IMPORT )); then
        record_migration "$m" "in schema.sql"; mig_recorded=$((mig_recorded + 1)); continue
    fi
    log "Applying migration $m"
    if mig_err="$(strip_db_lines "$MIGRATIONS_DIR/$m" | db_admin "$DB_NAME" 2>&1)"; then
        record_migration "$m" "applied"; mig_applied=$((mig_applied + 1))
    elif grep -Eq 'ERROR (1050|1060|1061|1022|1091|1826)' <<<"$mig_err"; then
        record_migration "$m" "already present"; mig_recorded=$((mig_recorded + 1))
        log "  already present in the database — recorded."
    else
        printf '%s\n' "$mig_err" >&2
        die "Migration $m failed. Fix it and re-run (applied migrations are not repeated)."
    fi
done
if (( FRESH_IMPORT )); then
    ok "Migrations: ${#MIGRATIONS[@]} recorded as applied (already included in schema.sql)."
else
    ok "Migrations: ${#MIGRATIONS[@]} total, ${mig_applied} applied now, ${mig_recorded} recorded as already present."
fi

# ---- write .env (only after the DB credentials are proven to work) --------
if (( WRITE_ENV )); then
    if [[ -f "$ENV_FILE" ]]; then
        backup="${ENV_FILE}.bak.$(date +%Y%m%d%H%M%S)"
        cp -p -- "$ENV_FILE" "$backup"
        log "Previous .env backed up to $(basename "$backup")"
    fi
    tmp_env="$(mktemp "${APP_ROOT}/.env.tmp.XXXXXX")"
    {
        printf '# Generated by setup-apache.sh on %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S UTC')"
        printf 'DB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' \
            "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS"
        printf 'APP_URL=%s\nAPP_DEBUG=false\n\n' "$APP_URL"
        printf 'STORE_NAME=%s\nSTORE_EMAIL=%s\nSTORE_CURRENCY=%s\nTHEME=%s\n\n' \
            "$STORE_NAME" "$STORE_EMAIL" "$STORE_CURRENCY" "$THEME"
        printf 'ADMIN_USERNAME=%s\nADMIN_PASSWORD_HASH=%s\n\n' "$ADMIN_USERNAME" "$ADMIN_PASSWORD_HASH"
        printf 'OXAPAY_MERCHANT_KEY=%s\nOXAPAY_SANDBOX=%s\n\n' "${OXAPAY_MERCHANT_KEY:-}" "$OXAPAY_SANDBOX"
        printf 'GEMINI_API_KEY=%s\nGEMINI_MODEL=%s\nGEMINI_IMAGE_MODEL=%s\n' \
            "${GEMINI_API_KEY:-}" "${GEMINI_MODEL:-}" "${GEMINI_IMAGE_MODEL:-}"
        if (( ${#EXTRA_ENV_LINES[@]} )); then
            printf '\n# Preserved from previous .env\n'
            printf '%s\n' "${EXTRA_ENV_LINES[@]}"
        fi
    } > "$tmp_env"
    mv -f -- "$tmp_env" "$ENV_FILE"
    ok ".env written."
fi
# Readable by the repo owner (CLI commands) and Apache, nobody else.
# (On the Windows drive this only sticks when the mount has `metadata`.)
chown "${REPO_OWNER}:${APACHE_GROUP}" "$ENV_FILE" 2>/dev/null || (( ON_WINFS )) || die "chown failed on $ENV_FILE"
chmod 0640 "$ENV_FILE" 2>/dev/null || (( ON_WINFS )) || die "chmod failed on $ENV_FILE"

# ============================================================ 4. composer ===
section "4/7  Composer dependencies"

COMPOSER_ARGS=(install --no-dev --optimize-autoloader --no-interaction --no-progress
               --working-dir="$APP_ROOT")
if [[ "$REPO_OWNER" == "root" ]]; then
    COMPOSER_ALLOW_SUPERUSER=1 composer "${COMPOSER_ARGS[@]}"
else
    owner_home="$(getent passwd "$REPO_OWNER" | cut -d: -f6)"
    as_user "$REPO_OWNER" env HOME="$owner_home" composer "${COMPOSER_ARGS[@]}"
fi
[[ -f "$APP_ROOT/vendor/autoload.php" ]] || die "composer install did not produce vendor/autoload.php"
ok "Dependencies installed."

# Directories Apache writes into at runtime (images, themes, var/):
# owned by the repo owner, group-writable by Apache.
for d in "${WRITABLE_DIRS[@]}"; do
    dir="${APP_ROOT}/${d}"
    mkdir -p "$dir"
    if (( ! ON_WINFS )); then
        chown "${REPO_OWNER}:${APACHE_GROUP}" "$dir"
        chmod 2775 "$dir"
    else
        chmod 0777 "$dir" 2>/dev/null || true
    fi
    if ! as_user "$APACHE_USER" test -w "$dir"; then
        warn "User '$APACHE_USER' cannot write to $dir."
        if (( ON_WINFS )); then winfs_help; fi
        die "Uploads, caching and logging would fail."
    fi
done
ok "Writable for Apache: ${WRITABLE_DIRS[*]}"

# ============================================================ 5. apache =====
section "5/7  Apache VirtualHost"

as_user "$APACHE_USER" test -r "$ENV_FILE" || die "User '$APACHE_USER' cannot read $ENV_FILE."

a2enmod -q alias dir headers rewrite >/dev/null
ok "Apache modules enabled: alias dir headers rewrite."
# Silence "Could not reliably determine the server's fully qualified domain name".
if ! grep -rqsiE '^[[:space:]]*ServerName' /etc/apache2/apache2.conf /etc/apache2/conf-enabled/; then
    printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf
    a2enconf -q servername >/dev/null
fi

# WSL1 has no accept filters; without this Apache hangs on every request.
if (( IS_WSL )) && ! grep -qiE 'wsl2|microsoft-standard' /proc/sys/kernel/osrelease 2>/dev/null; then
    if [[ ! -e /etc/apache2/conf-available/wsl1-acceptfilter.conf ]]; then
        printf '# WSL1: no accept filters available\nAcceptFilter http none\nAcceptFilter https none\n' \
            > /etc/apache2/conf-available/wsl1-acceptfilter.conf
        a2enconf -q wsl1-acceptfilter >/dev/null
        ok "WSL1 detected: disabled Apache AcceptFilter."
    fi
fi

WINFS_OPTS=""
if (( ON_WINFS )); then
    # mmap/sendfile on Windows-drive files serve stale or truncated content.
    WINFS_OPTS="
        # Repository is on the Windows drive (WSL): mmap/sendfile are unreliable there.
        EnableMMAP Off
        EnableSendfile Off"
fi

domain_re="${DOMAIN//./\\.}"
vhosts_dump="$(apache2ctl -S 2>/dev/null)" || true
if [[ -e "$VHOST_FILE" ]]; then
    ok "${VHOST_FILE} already exists; leaving it untouched."
elif grep -Eq "(namevhost|alias) ${domain_re}( |$)" <<<"$vhosts_dump"; then
    ok "${DOMAIN} is already served by another enabled VirtualHost; leaving it untouched."
else
    log "Creating ${VHOST_FILE} (DocumentRoot: ${DOC_ROOT})"


    umask 022
    cat > "$VHOST_FILE" <<EOF
# Managed by setup-apache.sh — generated $(date -u '+%Y-%m-%d %H:%M:%S UTC')
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAlias www.${DOMAIN}
    ServerAdmin webmaster@${DOMAIN}

    DocumentRoot "${DOC_ROOT}"

    <Directory "${DOC_ROOT}">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php${WINFS_OPTS}
        # Every URL that isn't a real file goes to the front controller
        # (public/index.php), which routes it — see config/routes.php.
        FallbackResource /index.php
    </Directory>

    # The admin panel is served by the same front controller under /admin.
    # Restrict it before going live, e.g. by IP:
    #   <Location /admin>
    #       Require ip 203.0.113.10
    #   </Location>

    # Never serve dotfiles (.env, .git, .htaccess, ...)
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN}_error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN}_access.log combined
</VirtualHost>
EOF
    VHOST_CREATED=1
    chmod 0644 "$VHOST_FILE"
    a2ensite -q "$SITE_NAME" >/dev/null
    ok "VirtualHost written and enabled."
fi

log "Testing Apache configuration"
if ! configtest_out="$(apache2ctl configtest 2>&1)"; then
    printf '%s\n' "$configtest_out" >&2
    die "apache2ctl configtest failed."
fi
ok "Configuration syntax OK."
reload_apache || die "Apache failed to reload/start. Check: /var/log/apache2/error.log$(is_systemd && echo ' or journalctl -u apache2 -n 50')"
ok "Apache reloaded."

# ---- verification ---------------------------------------------------------
TMP_BODY="$(mktemp)"
TOKEN="vhost-probe-$(date +%s)-${RANDOM}${RANDOM}"
PROBE_NAME="__${TOKEN}.txt"
PROBE_FILE="${DOC_ROOT}/${PROBE_NAME}"
printf '%s\n' "$TOKEN" > "$PROBE_FILE"
chmod 0644 "$PROBE_FILE"

log "Verifying: GET http://127.0.0.1/${PROBE_NAME}  (Host: ${DOMAIN})"
code="000"
for (( i = 1; i <= VERIFY_RETRIES; i++ )); do
    code="$(http_get "/${PROBE_NAME}")"
    if [[ "$code" == "200" ]] && grep -qF "$TOKEN" "$TMP_BODY"; then break; fi
    sleep 1
done
rm -f -- "$PROBE_FILE"; PROBE_FILE=""

if [[ "$code" != "200" ]] || ! grep -qF "$TOKEN" "$TMP_BODY"; then
    tail -n 10 "/var/log/apache2/${DOMAIN}_error.log" 2>/dev/null | sed 's/^/        /' >&2 || true
    if (( VHOST_CREATED )); then
        die "Probe failed (HTTP ${code}) — Apache is not serving ${DOC_ROOT} for ${DOMAIN}."
    fi
    warn "Probe failed (HTTP ${code}). The existing ${DOMAIN} vhost does not point at ${DOC_ROOT}."
else
    ok "HTTP 200 — Apache is serving files from ${DOC_ROOT}."
fi

# The vhost is proven to work: from here on a failure must not roll it back.
VHOST_CREATED=0

# ============================================================ 6. hosts ======
section "6/7  Local name resolution (hosts)"

HOSTS_LINE="127.0.0.1 ${DOMAIN} www.${DOMAIN}"
WIN_HOSTS="/mnt/c/Windows/System32/drivers/etc/hosts"
WIN_HOSTS_OK=1
hosts_has_domain() {  # hosts_has_domain FILE
    [[ -r "$1" ]] || return 1
    tr -d '\r' < "$1" | awk -v d="$DOMAIN" '
        /^[[:space:]]*#/ { next }
        { for (i = 2; i <= NF; i++) if ($i == d) found = 1 }
        END { exit !found }'
}

if [[ "$ADD_HOSTS" != "1" ]]; then
    log "ADD_HOSTS=0 — leaving hosts files alone."
elif ! confirm "Map ${DOMAIN} to 127.0.0.1 in the hosts file (local testing)?" "$( (( IS_WSL )) && echo Y || echo N )"; then
    log "Skipped. Point DNS for ${DOMAIN} at this server instead."
else
    if hosts_has_domain /etc/hosts; then
        ok "/etc/hosts already has an entry for ${DOMAIN}."
    else
        printf '\n# Added by setup-apache.sh\n%s\n' "$HOSTS_LINE" >> /etc/hosts
        ok "Added '${HOSTS_LINE}' to /etc/hosts."
    fi
    if (( IS_WSL )) && [[ -e "$WIN_HOSTS" ]]; then
        # WSL rebuilds /etc/hosts from the Windows hosts file on restart, and
        # the Windows browser only reads the Windows one.
        if hosts_has_domain "$WIN_HOSTS"; then
            ok "Windows hosts file already has an entry for ${DOMAIN}."
        elif printf '\r\n# Added by setup-apache.sh (WSL)\r\n%s\r\n' "$HOSTS_LINE" >> "$WIN_HOSTS" 2>/dev/null \
                && hosts_has_domain "$WIN_HOSTS"; then
            ok "Added '${HOSTS_LINE}' to the Windows hosts file."
        else
            WIN_HOSTS_OK=0
            warn "Could not write the Windows hosts file (needs Administrator)."
            warn "Run this in PowerShell as Administrator so the Windows browser finds the site:"
            warn "  Add-Content -Path \"\$env:windir\\System32\\drivers\\etc\\hosts\" -Value \"\`r\`n${HOSTS_LINE}\""
        fi
    fi
fi

# ============================================================ 7. verify =====
section "7/7  Verification"

VERIFY_RC=0
if [[ -f "$VERIFY_SCRIPT" ]]; then
    DOMAIN="$DOMAIN" APP_ROOT="$APP_ROOT" APACHE_USER="$APACHE_USER" \
        bash "$VERIFY_SCRIPT" || VERIFY_RC=$?
else
    warn "$VERIFY_SCRIPT not found; skipping the verification suite."
fi

# ============================================================ summary =======
if (( VERIFY_RC == 0 )); then
    printf '\n%sDone.%s %s is set up and verified.\n' "$C_GREEN" "$C_RESET" "$DOMAIN"
else
    printf '\n%sSetup finished, but verification reported failures (see above).%s\n' "$C_RED" "$C_RESET"
fi
cat <<EOF
  Project     : ${APP_ROOT}
  .env        : ${ENV_FILE}  (mode 640, ${REPO_OWNER}:${APACHE_GROUP})
  Database    : ${DB_NAME}  (user ${DB_USER}@localhost$( (( DB_PASS_GENERATED )) && echo ", generated password in .env"))
  VirtualHost : ${VHOST_FILE}
  Logs        : /var/log/apache2/${DOMAIN}_{access,error}.log
  Re-check    : sudo ./verify-env.sh

Next steps:
EOF
if (( IS_WSL )); then
    echo "  * Open http://${DOMAIN}/ in your Windows browser (admin: http://${DOMAIN}/admin/login)."
    (( WIN_HOSTS_OK )) || echo "  * Add the Windows hosts entry shown above first."
    if ! is_systemd; then
        echo "  * WSL does not start services on boot. After each restart run:"
        echo "      sudo service mariadb start && sudo service apache2 start"
        echo "    or enable systemd: add  [boot] systemd=true  to /etc/wsl.conf, then  wsl --shutdown"
    fi
else
    echo "  * Point DNS A/AAAA records for ${DOMAIN} and www.${DOMAIN} at this server."
    echo "  * Add HTTPS:  apt-get install -y certbot python3-certbot-apache"
    echo "                certbot --apache --redirect -m ${STORE_EMAIL:-webmaster@${DOMAIN}} -d ${DOMAIN} -d www.${DOMAIN}"
    echo "  * Payment webhooks (only for the methods you enable in .env):"
    echo "      Stripe : ${APP_URL:-https://${DOMAIN}}/payment/stripe"
    echo "      Revolut: ${APP_URL:-https://${DOMAIN}}/payment/revolut"
    echo "      OxaPay : ${APP_URL:-https://${DOMAIN}}/payment/oxapay (sent automatically)"
    echo "    (the old *.php webhook URLs keep working too)"
fi
echo "  * Restrict /admin before going live (see comment in the vhost file)."
echo "  * CLI tools: php bin/console list"
if [[ -z "${OXAPAY_MERCHANT_KEY:-}" ]]; then
    echo "  * OXAPAY_MERCHANT_KEY is empty — set it in .env before taking payments."
fi
exit "$VERIFY_RC"
