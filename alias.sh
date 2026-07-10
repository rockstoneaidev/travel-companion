# ~/.sailrc

# Unalias if they exist so functions take over
unalias sail 2>/dev/null || true
unalias a 2>/dev/null || true
unalias pest 2>/dev/null || true

# --- Helper functions ------------------------------------------------------

# Resolve a bash we can use (vendor/bin/sail is a bash script)
__sail_bash() {
  if command -v bash >/dev/null 2>&1; then
    command -v bash
  elif [ -x /bin/bash ]; then
    echo /bin/bash
  else
    # Fallback to sh (may fail with vendor/bin/sail, but we try)
    echo /bin/sh
  fi
}

# Detect if we’re inside a Laravel Sail project
__in_sail_project() {
  [ -f "./sail" ] || [ -f "vendor/bin/sail" ] || [ -f "artisan" ]
}

# Print colored warning if supported
__warn() {
  local msg="$1"
  if [ -t 1 ]; then
    printf "\033[0;33m%s\033[0m\n" "$msg" >&2
  else
    printf "%s\n" "$msg" >&2
  fi
}

# --- Main Commands ---------------------------------------------------------

# Sail wrapper: prefer local ./sail or vendor/bin/sail; otherwise docker compose
sail() {
  if ! __in_sail_project; then
    __warn "sail: not a Laravel project (no ./sail, vendor/bin/sail, or artisan found)"
    return 1
  fi

  export SAIL_USER="${SAIL_USER:-www-data}"
  export WWWUSER="${WWWUSER:-www-data}"
  export WWWGROUP="${WWWGROUP:-www-data}"

  local _bash
  _bash="$(__sail_bash)"

  if [ -f ./sail ]; then
    "$_bash" ./sail "$@"
    return
  elif [ -f vendor/bin/sail ]; then
    "$_bash" vendor/bin/sail "$@"
    return
  fi

  # No sail binary — map common commands onto docker compose directly
  local cmd="${1:-}"
  shift || true
  case "$cmd" in
    up)       docker compose up "$@" ;;
    down)     docker compose down "$@" ;;
    build)    docker compose build "$@" ;;
    pull)     docker compose pull "$@" ;;
    ps)       docker compose ps "$@" ;;
    logs)     docker compose logs "$@" ;;
    restart)  docker compose restart "$@" ;;
    exec)     docker compose exec app "$@" ;;
    shell|bash) docker compose exec app bash "$@" ;;
    artisan)  docker compose exec app php artisan "$@" ;;
    composer) docker compose exec app composer "$@" ;;
    npm)      docker compose exec app npm "$@" ;;
    npx)      docker compose exec app npx "$@" ;;
    pest)     docker compose exec app ./vendor/bin/pest "$@" ;;
    php)      docker compose exec app php "$@" ;;
    *)        docker compose "$cmd" "$@" ;;
  esac
}

# Convenience: artisan via Sail
a() {
  if ! __in_sail_project; then
    __warn "a: not a Laravel project (no ./sail, vendor/bin/sail, or artisan found)"
    return 1
  fi
  sail artisan "$@"
}

# Convenience: run Pest tests via Sail
pest() {
  if ! __in_sail_project; then
    __warn "pest: not a Laravel project (no ./sail, vendor/bin/sail, or artisan found)"
    return 1
  fi
  sail pest "$@"
}
