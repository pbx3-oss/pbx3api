#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"

PHP_VERSION="${PHP_VERSION:-8.3}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php${PHP_VERSION}-fpm}"
PHP_FPM_SOCKET="${PHP_FPM_SOCKET:-/run/php/${PHP_FPM_SERVICE}.sock}"
PBX3_SQLITE_PATH="${PBX3_SQLITE_PATH:-${REPO_ROOT}/../pbx3/db/sqlite.db}"
SKIP_APT="${SKIP_APT:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"
SKIP_ARTISAN="${SKIP_ARTISAN:-0}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run installer as root (sudo)." >&2
    exit 1
fi

echo "Installing pbx3api on Ubuntu 24.04 (nginx + PHP-FPM)"

install_runtime_packages() {
    if [ "${SKIP_APT}" = "1" ]; then
        echo "Skipping apt package install (SKIP_APT=1)"
        return
    fi

    if ! command -v apt-get >/dev/null 2>&1; then
        echo "apt-get not found. Install runtime dependencies manually." >&2
        return
    fi

    echo "Installing runtime packages via apt-get..."
    apt-get update
    apt-get install -y \
        nginx \
        ssl-cert \
        composer \
        "php${PHP_VERSION}-fpm" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-sqlite3" \
        "php${PHP_VERSION}-mysql" \
        "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-ldap" \
        "php${PHP_VERSION}-gd"
}

ensure_snakeoil_cert() {
    cert_path="/etc/ssl/certs/ssl-cert-snakeoil.pem"
    key_path="/etc/ssl/private/ssl-cert-snakeoil.key"
    need_regen=0

    if [ ! -f "${cert_path}" ] || [ ! -f "${key_path}" ]; then
        need_regen=1
    fi

    if [ "${need_regen}" -eq 0 ] && command -v openssl >/dev/null 2>&1; then
        if ! openssl x509 -in "${cert_path}" -noout >/dev/null 2>&1; then
            need_regen=1
        elif ! openssl pkey -in "${key_path}" -noout >/dev/null 2>&1; then
            need_regen=1
        else
            cert_modulus="$(openssl x509 -noout -modulus -in "${cert_path}" 2>/dev/null || true)"
            key_modulus="$(openssl rsa -noout -modulus -in "${key_path}" 2>/dev/null || true)"
            if [ -z "${cert_modulus}" ] || [ "${cert_modulus}" != "${key_modulus}" ]; then
                need_regen=1
            fi
        fi
    fi

    if [ "${need_regen}" -eq 0 ]; then
        return
    fi

    echo "Generating fallback snakeoil certificate for nginx..."

    if command -v make-ssl-cert >/dev/null 2>&1; then
        make-ssl-cert generate-default-snakeoil --force-overwrite >/dev/null 2>&1 || true
    fi

    if [ ! -f "${cert_path}" ] || [ ! -f "${key_path}" ] || ! openssl x509 -in "${cert_path}" -noout >/dev/null 2>&1; then
        if command -v openssl >/dev/null 2>&1; then
            mkdir -p /etc/ssl/certs /etc/ssl/private
            openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
                -keyout "${key_path}" \
                -out "${cert_path}" \
                -subj "/CN=pbx3api.local" >/dev/null 2>&1
            chmod 600 "${key_path}"
        fi
    fi

    if [ ! -f "${cert_path}" ] || [ ! -f "${key_path}" ]; then
        echo "Unable to create fallback certificate (${cert_path})." >&2
        echo "Install ssl-cert or provide custom cert paths in config/nginx/pbx3-api.conf." >&2
        exit 1
    fi

    if command -v openssl >/dev/null 2>&1; then
        if ! openssl x509 -in "${cert_path}" -noout >/dev/null 2>&1 || ! openssl pkey -in "${key_path}" -noout >/dev/null 2>&1; then
            echo "Unable to create fallback certificate (${cert_path})." >&2
            echo "Install ssl-cert or provide custom cert paths in config/nginx/pbx3-api.conf." >&2
            exit 1
        fi
    fi
}

install_php_deps() {
    if [ "${SKIP_COMPOSER}" = "1" ]; then
        echo "Skipping composer install (SKIP_COMPOSER=1)"
        return
    fi

    if [ ! -f "${REPO_ROOT}/composer.json" ]; then
        return
    fi

    if [ ! -f "${REPO_ROOT}/vendor/autoload.php" ]; then
        echo "Installing composer dependencies (--no-scripts; discover runs after .env as www-data)..."
        cd "${REPO_ROOT}"
        # Avoid root @php artisan package:discover during install (#5l / Symfony 7.4 lazy loader).
        COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-scripts
    else
        echo "Composer dependencies already present (vendor/autoload.php found)"
    fi

    if [ -f "${REPO_ROOT}/scripts/ensure-console-command-loader.php" ]; then
        php "${REPO_ROOT}/scripts/ensure-console-command-loader.php" || {
            echo "ensure-console-command-loader.php failed" >&2
            exit 1
        }
    fi
}

set_env_value() {
    env_file="$1"
    key="$2"
    value="$3"

    if [ ! -f "${env_file}" ]; then
        return
    fi

    if grep -q "^${key}=" "${env_file}"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "${env_file}"
    else
        printf '%s=%s\n' "${key}" "${value}" >> "${env_file}"
    fi
}

run_as_www_data() {
    if command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- "$@"
    else
        su -s /bin/sh www-data -c "$*"
    fi
}

bootstrap_laravel_app() {
    if [ "${SKIP_ARTISAN}" = "1" ]; then
        echo "Skipping Laravel bootstrap (SKIP_ARTISAN=1)"
        return
    fi

    if [ ! -f "${REPO_ROOT}/artisan" ]; then
        return
    fi

    if [ ! -f "${REPO_ROOT}/.env" ] && [ -f "${REPO_ROOT}/.env.example" ]; then
        echo "Creating .env from .env.example"
        cp "${REPO_ROOT}/.env.example" "${REPO_ROOT}/.env"
    fi

    # Avoid requiring Laravel session/cache/queue tables on first boot.
    set_env_value "${REPO_ROOT}/.env" "SESSION_DRIVER" "file"
    set_env_value "${REPO_ROOT}/.env" "CACHE_STORE" "file"
    set_env_value "${REPO_ROOT}/.env" "QUEUE_CONNECTION" "sync"

    mkdir -p "${REPO_ROOT}/database"
    if [ ! -f "${PBX3_SQLITE_PATH}" ]; then
        echo "PBX3 sqlite database not found at ${PBX3_SQLITE_PATH}" >&2
        echo "Set PBX3_SQLITE_PATH to the correct sqlite.db path and rerun installer." >&2
        exit 1
    fi

    pbx3_db_dir="$(dirname "${PBX3_SQLITE_PATH}")"
    chown www-data:www-data "${pbx3_db_dir}" "${PBX3_SQLITE_PATH}"
    chmod 775 "${pbx3_db_dir}"
    chmod 664 "${PBX3_SQLITE_PATH}"

    ln -sfn "${PBX3_SQLITE_PATH}" "${REPO_ROOT}/database/database.sqlite"

    mkdir -p "${REPO_ROOT}/storage/logs" "${REPO_ROOT}/bootstrap/cache"
    chown -R root:root "${REPO_ROOT}"
    chown -R www-data:www-data "${REPO_ROOT}/storage" "${REPO_ROOT}/bootstrap/cache"
    find "${REPO_ROOT}/storage" "${REPO_ROOT}/bootstrap/cache" -type d -exec chmod 775 {} \;
    find "${REPO_ROOT}/storage" "${REPO_ROOT}/bootstrap/cache" -type f -exec chmod 664 {} \;
    # Ensure log file exists as www-data — root artisan creates root-owned laravel.log and login 500s.
    if command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- touch "${REPO_ROOT}/storage/logs/laravel.log"
    else
        su -s /bin/sh www-data -c "touch '${REPO_ROOT}/storage/logs/laravel.log'"
    fi
    chown www-data:www-data "${REPO_ROOT}/storage/logs/laravel.log"
    chmod 664 "${REPO_ROOT}/storage/logs/laravel.log"
    chown root:www-data "${REPO_ROOT}/.env"
    chmod 664 "${REPO_ROOT}/.env"

    if command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- test -r "${PBX3_SQLITE_PATH}" || {
            echo "www-data cannot read sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
        runuser -u www-data -- test -w "${PBX3_SQLITE_PATH}" || {
            echo "www-data cannot write sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
    else
        su -s /bin/sh www-data -c "test -r '${PBX3_SQLITE_PATH}'" || {
            echo "www-data cannot read sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
        su -s /bin/sh www-data -c "test -w '${PBX3_SQLITE_PATH}'" || {
            echo "www-data cannot write sqlite database at ${PBX3_SQLITE_PATH}" >&2
            exit 1
        }
    fi

    if ! command -v php >/dev/null 2>&1; then
        return
    fi

    echo "Running Laravel setup commands (as www-data)"
    cd "${REPO_ROOT}"
    # Never run artisan as root — it creates root-owned storage/logs/laravel.log and SPA login 500s.
    if command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan package:discover --ansi || true"
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan key:generate --force || true"
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan migrate --force --no-interaction"
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan config:clear || true"
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan cache:clear || true"
        runuser -u www-data -- sh -c "cd '${REPO_ROOT}' && php artisan route:clear || true"
    else
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan package:discover --ansi || true"
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan key:generate --force || true"
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan migrate --force --no-interaction"
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan config:clear || true"
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan cache:clear || true"
        su -s /bin/sh www-data -c "cd '${REPO_ROOT}' && php artisan route:clear || true"
    fi

    # Re-assert log ownership after artisan (belt and braces).
    chown www-data:www-data "${REPO_ROOT}/storage/logs/laravel.log" 2>/dev/null || true
    run_as_www_data test -w "${REPO_ROOT}/storage/logs/laravel.log" || {
        echo "www-data cannot write ${REPO_ROOT}/storage/logs/laravel.log — SPA login will 500" >&2
        exit 1
    }
}

validate_install_health() {
    app_root="${APP_ROOT:-${REPO_ROOT}}"
    db_link="${app_root}/database/database.sqlite"
    pbx3_sqlite="${PBX3_SQLITE_PATH:-${REPO_ROOT}/../pbx3/db/sqlite.db}"

    echo "Running post-install health checks..."

    if [ ! -e "${db_link}" ]; then
        echo "Health check failed: Laravel DB link missing: ${db_link}" >&2
        echo "Run installer without SKIP_ARTISAN=1 or create the symlink to ${pbx3_sqlite}." >&2
        exit 1
    fi

    if [ -L "${db_link}" ]; then
        link_target="$(readlink -f "${db_link}" 2>/dev/null || true)"
        if [ -z "${link_target}" ] || [ ! -e "${link_target}" ]; then
            echo "Health check failed: symlink ${db_link} does not resolve to a readable file" >&2
            exit 1
        fi
        pbx3_sqlite="${link_target}"
    elif [ -f "${db_link}" ]; then
        pbx3_sqlite="${db_link}"
    else
        echo "Health check failed: ${db_link} is not a symlink or regular file" >&2
        exit 1
    fi

    run_as_www_data test -r "${pbx3_sqlite}" || {
        echo "Health check failed: www-data cannot read ${pbx3_sqlite}" >&2
        exit 1
    }
    run_as_www_data test -w "${pbx3_sqlite}" || {
        echo "Health check failed: www-data cannot write ${pbx3_sqlite}" >&2
        exit 1
    }

    log_file="${app_root}/storage/logs/laravel.log"
    run_as_www_data test -w "${log_file}" || {
        echo "Health check failed: www-data cannot write ${log_file}" >&2
        echo "SPA login Log::info will 500. Fix: chown www-data:www-data ${log_file}" >&2
        exit 1
    }

    # API schema on shared PBX sqlite (2FA / privileges) — migrations should have run in bootstrap.
    if command -v sqlite3 >/dev/null 2>&1; then
        for col in abilities portable two_factor_secret; do
            if ! sqlite3 "${pbx3_sqlite}" "PRAGMA table_info(users);" | awk -F'|' '{print $2}' | grep -qx "${col}"; then
                echo "Health check failed: users.${col} missing — re-run installer migrate step" >&2
                exit 1
            fi
        done
    fi

    if ! command -v nginx >/dev/null 2>&1; then
        echo "Health check failed: nginx not found in PATH" >&2
        exit 1
    fi
    if ! nginx -t >/dev/null 2>&1; then
        echo "Health check failed: nginx -t" >&2
        nginx -t >&2 || true
        exit 1
    fi

    if [ ! -S "${PHP_FPM_SOCKET}" ]; then
        echo "Health check failed: PHP-FPM socket missing: ${PHP_FPM_SOCKET}" >&2
        echo "Ensure ${PHP_FPM_SERVICE} is running (systemctl status ${PHP_FPM_SERVICE})." >&2
        exit 1
    fi

    if ! command -v curl >/dev/null 2>&1; then
        echo "Health check failed: curl required for HTTPS readiness check (install pbx3 .deb or apt install curl)" >&2
        exit 1
    fi

    http_code="$(curl -k -s -o /dev/null -w '%{http_code}' --connect-timeout 10 'https://127.0.0.1:44300/up' 2>/dev/null || echo '000')"
    case "${http_code}" in
        200) ;;
        *)
            echo "Health check failed: GET https://127.0.0.1:44300/up returned HTTP ${http_code} (expected 200)" >&2
            echo "Check nginx site, php-fpm socket, and Laravel bootstrap (journalctl -u nginx -u ${PHP_FPM_SERVICE})." >&2
            exit 1
            ;;
    esac

    echo "Post-install health checks passed (DB symlink, nginx -t, php-fpm socket, /up → 200)."
}

install_cron_jobs() {
    cron_dir="${SCRIPT_DIR}/cron.d"
    app_root="${APP_ROOT:-${REPO_ROOT}}"

    # Only wire the app path if it differs from the packaged /opt/pbx3api default.
    for job in pbx3-backup pbx3-recordings pbx3-logs pbx3-cdr; do
        src="${cron_dir}/${job}.example"
        dst="/etc/cron.d/${job}"
        if [ ! -f "${src}" ]; then
            continue
        fi
        if [ -f "${dst}" ]; then
            echo "Cron ${dst} already present — leaving as-is."
            continue
        fi
        sed "s#/opt/pbx3api#${app_root}#g" "${src}" > "${dst}"
        chown root:root "${dst}"
        chmod 644 "${dst}"
        echo "Installed cron ${dst}"
    done
}

install_runtime_packages
install_php_deps
ensure_snakeoil_cert
bootstrap_laravel_app
install_cron_jobs

# Default to the current clone path so local testing works.
# Override APP_ROOT/SOURCE_CONF/PHP_FPM_SERVICE/PHP_FPM_SOCKET/PHP_VERSION/PBX3_SQLITE_PATH as needed.
APP_ROOT="${APP_ROOT:-${REPO_ROOT}}" \
SOURCE_CONF="${SOURCE_CONF:-${REPO_ROOT}/config/nginx/pbx3-api.conf}" \
PHP_FPM_SERVICE="${PHP_FPM_SERVICE}" \
PHP_FPM_SOCKET="${PHP_FPM_SOCKET}" \
PBX3_SQLITE_PATH="${PBX3_SQLITE_PATH}" \
sh "${SCRIPT_DIR}/install-nginx-site.sh"

validate_install_health

echo "pbx3api installer completed"
