#!/usr/bin/env bash
# Installs the module in a disposable PrestaShop (prestashop-flashlight image plus MariaDB)
# and runs the integration fixtures. Nothing is written to the checkout or to a real shop.
# Usage: tools/integration.sh <flashlight tag>, for example 8.2.9-8.1-nginx or 9.1.5-8.4-nginx
set -euo pipefail

tag="${1:?Usage: tools/integration.sh <prestashop-flashlight tag>}"
root="$(cd "$(dirname "$0")/.." && pwd)"
name="pixel-audit-it-$$"
module=/var/www/html/modules/pixel_audit

cleanup() {
    docker rm -f "$name-shop" "$name-db" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

shop() {
    docker exec "$name-shop" "$@"
}
console() {
    shop php -d memory_limit=-1 /var/www/html/bin/console "$@"
}

docker network create "$name" >/dev/null
# MariaDB, as in the flashlight examples: the MariaDB client of the image refuses the TLS setup of mysql:8.
docker run -d --name "$name-db" --network "$name" --network-alias mysql --tmpfs /var/lib/mysql \
    -e MYSQL_ROOT_PASSWORD=prestashop -e MYSQL_USER=prestashop -e MYSQL_PASSWORD=prestashop -e MYSQL_DATABASE=prestashop \
    mariadb:lts >/dev/null
docker run -d --name "$name-shop" --network "$name" -e PS_DOMAIN=localhost:8000 \
    "prestashop/prestashop-flashlight:$tag" >/dev/null

echo "Waiting for PrestaShop $tag..."
for attempt in $(seq 1 120); do
    if shop test -f /tmp/flashlight-modules-installed.lock 2>/dev/null; then
        break
    fi
    if [ "$(docker inspect -f '{{.State.Running}}' "$name-shop")" != "true" ] || [ "$attempt" = 120 ]; then
        docker logs "$name-shop" | tail -n 40
        echo "PrestaShop did not start." >&2
        exit 1
    fi
    sleep 2
done

# The checkout is copied, not mounted: the installation writes into the module directory.
tar -C "$root" --exclude=./.git --exclude=./dist --exclude=./tools/vendor -cf - . \
    | docker exec -i -u root "$name-shop" sh -c "mkdir -p $module && tar -C $module -xf - && chown -R www-data:www-data $module"

# The module command does not rebuild the container: without this, the services of the module
# stay unknown after an installation and referenced after an uninstallation.
clear_cache() {
    shop sh -c 'rm -rf /var/www/html/var/cache/*'
}

# The module command exits with 0 even when the installation fails, so the result is read from the shop.
install() {
    console prestashop:module --no-interaction install pixel_audit
    shop php -r 'require "/var/www/html/config/config.inc.php";
        $module = Module::getInstanceByName("pixel_audit");
        $hooks = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "hook_module WHERE id_module = " . (int) ($module ? $module->id : 0));
        if (!Module::isInstalled("pixel_audit") || $hooks === 0) { fwrite(STDERR, "pixel_audit is not installed." . PHP_EOL); exit(1); }
        echo "Installed on PrestaShop ", _PS_VERSION_, " / PHP ", PHP_VERSION, " with ", $hooks, " hook registrations", PHP_EOL;'
    clear_cache
}

install
for fixture in "$root"/tests/Integration/*.php; do
    shop php "$module/tests/Integration/$(basename "$fixture")"
done
console pixel:audit:purge --batch-size=10 --max-batches=1
# The default uninstall keeps the journal: a second installation must reuse the tables.
console prestashop:module --no-interaction uninstall pixel_audit
clear_cache
install
echo "Integration checks passed on $tag"
