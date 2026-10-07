#!/usr/bin/env bash
set -euo pipefail

# docker-compose.yml passes these into the Sail image build, where the Dockerfile
# runs `groupadd -g $WWWGROUP`. Sail's own `sail` wrapper exports them; nothing else
# does, so a bare ./start.sh on a fresh clone would fail the build with an empty
# argument. Defaulting them here is what makes the documented one-command setup
# actually be one command.
export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"

cp -n .env.example .env
touch database/database.sqlite

# Chicken and egg: docker-compose.yml builds the app image from
# ./vendor/laravel/sail/runtimes/8.5, so the Sail runtime has to be on disk before
# `docker compose up --build` can run — but on a fresh clone vendor/ does not exist
# yet, because it is gitignored. Bootstrap it in a throwaway Composer container so
# no PHP on the host is required. The in-container install below still runs, which
# is what resolves platform-specific packages and executes the post-install scripts.
if [ ! -d vendor/laravel/sail/runtimes ]; then
    echo '==> Bootstrapping vendor/ so the Sail build context exists...'
    # --user matters on Linux: without it the container writes vendor/ as root on
    # the bind mount, and the host's own composer, vendor/bin/* or IDE indexer then
    # hit permission errors on a tree they cannot touch. COMPOSER_HOME has to move
    # with it, because the image's default home is not writable by that uid.
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -e COMPOSER_HOME=/tmp/composer \
        -e COMPOSER_CACHE_DIR=/tmp/composer/cache \
        -v "$(pwd)":/app -w /app composer:2 \
        composer install --ignore-platform-reqs --no-scripts --no-interaction
fi

docker compose up --build --remove-orphans -d
docker compose exec -T app composer install
docker compose exec -T app php artisan migrate:fresh --seed

echo
echo "==> Ready: ${APP_URL:-http://localhost:8080}"
echo "    Queue worker is running, so the outbox drains on its own."
