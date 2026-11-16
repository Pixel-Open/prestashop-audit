# Contributing

Use English for source strings, documentation and focused comments. Preserve PHP 8.1
syntax and the PrestaShop 8.2/9 runtime contract. Never commit credentials or shop data.

## Standalone tools

The module keeps development dependencies outside its runtime autoloader:

```sh
composer install --working-dir=tools
composer test --working-dir=tools
composer analyse --working-dir=tools
composer style --working-dir=tools
php tools/check-release.php
php tools/build.php
```

Use `composer style:fix --working-dir=tools` to apply the configured PSR-12 baseline.
CI tests PHP 8.1 through 8.4. Its pure-unit/static checks do not boot a shop. A second
CI job runs `tools/integration.sh <prestashop-flashlight tag>` (Docker only) on new
PrestaShop 8.2 and 9 shops: installation, the `tests/Integration` fixtures, purge and
reinstallation. Test permissions, restoration and the interface on isolated shops too.

Production autoload files are generated with `composer install --no-dev
--optimize-autoloader` at the module root. Never put PHPUnit/PHPStan/style tools in that
runtime vendor directory. `tools/composer.lock` pins the development environment to PHP
8.1-compatible dependencies.

## Releases

Pushing a `v<version>` tag that matches the module version runs the release workflow:
it checks the source, builds `pixel_audit.zip`, attaches it with its SHA-256 checksum
to a draft release, then publishes it. Enable immutable releases in the repository
settings so published assets and tags cannot be changed; fix a bad release with a new
version. The archive is reproducible: with the same PHP and libzip versions,
`SOURCE_DATE_EPOCH="$(git log -1 --format=%ct)" php tools/build.php` on the tagged
commit yields the published checksum.

Before submitting a change, describe the concrete behavior, compatibility impact and
validation. For API changes include a generic example and version the contract. Add
an upgrade migration for hook/tab/schema changes without erasing audit data.

See [testing](docs/testing.md), [integration checklist](docs/integrations.md) and [AGENTS.md](AGENTS.md).
