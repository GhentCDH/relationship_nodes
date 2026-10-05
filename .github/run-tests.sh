#!/usr/bin/env bash
# Builds a Drupal project with this module and runs coding standards checks
# and the kernel tests. Used by .github/workflows/tests.yml; can also be run
# locally in a container with PHP 8.3 and Composer.
#
# Environment:
#   DRUPAL_CORE                  Composer constraint for drupal/core (e.g. ^10.3, ^11).
#   RN_ELASTICSEARCH_URL         Optional; enables the Elasticsearch integration test.
#   RN_PHPSTAN                   Optional; 1 runs PHPStan (with Drupal 11: on older
#                                cores, newer core classes are reported missing).
#   MINK_DRIVER_ARGS_WEBDRIVER   Optional; WebDriver settings that enable the
#                                browser tests (tests/src/FunctionalJavascript).
#   SIMPLETEST_BASE_URL          URL at which the browser reaches this machine's
#                                web server (default http://localhost:8080).
#   BUILD_DIR                    Where the Drupal project is built (default /tmp/rn-build).
set -euo pipefail

MODULE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${BUILD_DIR:-/tmp/rn-build}"
DRUPAL_CORE="${DRUPAL_CORE:-^11}"

rm -rf "$BUILD_DIR"
composer create-project --no-interaction --no-install drupal/recommended-project "$BUILD_DIR" "$DRUPAL_CORE"
cd "$BUILD_DIR"
composer config minimum-stability dev
composer config prefer-stable true
composer config --no-plugins allow-plugins.symfony/runtime true
composer config --no-plugins allow-plugins.tbachert/spi false
composer config repositories.relationship_nodes '{"type": "path", "url": "'"$MODULE_DIR"'", "options": {"symlink": false}}'
composer require --no-interaction -W \
  "drupal/core-recommended:$DRUPAL_CORE" \
  "drupal/core-composer-scaffold:$DRUPAL_CORE" \
  "drupal/relationship_nodes:*@dev" \
  "drupal/search_api:^1.38" \
  "drupal/elasticsearch_connector:^8.0@alpha" \
  "drupal/facets:^3.0" \
  "drupal/better_exposed_filters:^7.0"
composer require --no-interaction -W --dev "drupal/core-dev:$DRUPAL_CORE"

MODULE="$BUILD_DIR/web/modules/contrib/relationship_nodes"

echo "== Coding standards"
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install,inc,yml "$MODULE"

if [ "${RN_PHPSTAN:-0}" = 1 ]; then
  echo "== PHPStan"
  vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c "$MODULE/phpstan.neon.dist" "$MODULE"
fi

mkdir -p "$BUILD_DIR/web/sites/simpletest"
cd "$BUILD_DIR/web"
export SIMPLETEST_DB="sqlite://localhost/$BUILD_DIR/test.sqlite"
export SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://localhost:8080}"
# Deprecations in dependencies are reported, but do not fail the tests (as
# with PHPUnit 10 and later on Drupal 11).
export SYMFONY_DEPRECATIONS_HELPER=weak
TESTS="$MODULE/tests/src/Kernel"

if [ -n "${MINK_DRIVER_ARGS_WEBDRIVER:-}" ]; then
  # The browser tests need a web server for the test site.
  port="${SIMPLETEST_BASE_URL##*:}"
  php -S "0.0.0.0:${port%%/*}" .ht.router.php > "$BUILD_DIR/webserver.log" 2>&1 &
  server_pid=$!
  trap 'kill "$server_pid"' EXIT
  TESTS="$MODULE/tests"
fi

echo "== Tests ($TESTS)"
"$BUILD_DIR/vendor/bin/phpunit" -c "$BUILD_DIR/web/core/phpunit.xml.dist" "$TESTS"
