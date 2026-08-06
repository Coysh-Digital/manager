#!/bin/sh
#
# Container entrypoint.
#
# Refuses to start on a configuration that would be insecure rather than starting and hoping
# somebody notices. The specification asks a self-hosted deployment to refuse insecure defaults, and
# the only reliable moment to do that is before serving the first request.
set -eu

fail() {
    echo "manager: $1" >&2
    exit 1
}

require_config() {
    [ "${APP_KEY:-}" = "" ] && fail "APP_KEY is not set. Generate one with 'php artisan key:generate --show' and set it in the environment."

    case "${APP_KEY:-}" in
        base64:*) ;;
        *) fail "APP_KEY does not look like a generated key." ;;
    esac

    [ "${APP_URL:-}" = "" ] && fail "APP_URL is not set. Cookie security and connector callbacks depend on it."

    # These used to be conditional on APP_ENV=production, and the condition was the hole.
    #
    # The fallback was right - an absent APP_ENV is treated as production, so an operator who deletes
    # the line is protected. What it could not survive was the line being *present and wrong*, which
    # is the likelier case by far: the shipped .env.example says APP_ENV=local, and docs/install.md
    # tells an operator to copy that file. Following the documentation exactly therefore produced an
    # installation where every check written to catch a dangerous configuration was skipped by the
    # very setting that made it dangerous.
    #
    # So they are unconditional now. This image is how Manager is *run*; it is not how Manager is
    # developed - ddev serves that, with its own container and its own entrypoint - so there is no
    # audience for whom booting with a default database password is the right outcome.
    case "${DB_PASSWORD:-}" in
        ""|password|secret|postgres|changeme|manager)
            fail "DB_PASSWORD is empty or a well-known default. Refusing to start."
            ;;
    esac

    case "${APP_DEBUG:-false}" in
        true|1|on)
            fail "APP_DEBUG is on. Refusing to start: exceptions would render internal detail to visitors, including configuration and query fragments."
            ;;
    esac

    # Not a security check, and it belongs here anyway.
    #
    # Laravel behaves differently outside production, and one of those differences is load-bearing:
    # Model::preventLazyLoading() is enabled when the environment is not production, so a lazy load
    # that is merely inefficient in development becomes an exception here. An operator who copied the
    # example file gets a control plane that throws on pages that work everywhere else, and nothing
    # in the resulting stack trace points at APP_ENV.
    case "${APP_ENV:-production}" in
        production) ;;
        *) fail "APP_ENV is '${APP_ENV}'. This image runs Manager in production; set APP_ENV=production. Development is served by ddev, not by this image." ;;
    esac
}

# Key generation is exempt from the checks below, and has to be.
#
# Installing starts with generating an APP_KEY, and the documented way to do that is to run this image
# with `key:generate --show`. Refusing that for want of an APP_KEY makes the first step of the install
# depend on having already completed it - which is precisely the state a first-time installer is in.
#
# An exact allowlist rather than a flag or an environment variable. These three commands print a fresh
# random value to stdout and touch nothing: no database, no storage, no request served. Anything else,
# including any other artisan command, still goes through require_config.
case "${1:-web} ${2:-} ${3:-}" in
    "php artisan key:generate"*|"artisan key:generate"*)
        exec "$@"
        ;;
    "php artisan manager:keys:generate"*|"artisan manager:keys:generate"*)
        exec "$@"
        ;;
    "php artisan manager:backups:keygen"*|"artisan manager:backups:keygen"*)
        exec "$@"
        ;;
esac

require_config

# Cached for speed, and cleared first so a stale cache from an earlier image cannot survive an
# upgrade. A stale route or config cache is a genuinely baffling class of bug.
php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

case "${1:-web}" in
    web)
        # Migrations run here rather than in the worker or scheduler, so that exactly one process
        # does it however many replicas are running.
        if [ "${MANAGER_RUN_MIGRATIONS:-true}" = "true" ]; then
            php artisan migrate --force --isolated
        fi

        exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;
    worker)
        exec php artisan queue:work --sleep=1 --tries=3 --max-time=3600
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    doctor)
        exec php artisan manager:doctor
        ;;
    *)
        exec "$@"
        ;;
esac
