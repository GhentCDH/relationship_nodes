#!/usr/bin/env bash
# Builds a Drupal project with this module and runs coding standards checks
# and the kernel tests. Used by .github/workflows/tests.yml; can also be run
# locally in a container with PHP 8.3 and Composer.
#
# Environment:
#   DRUPAL_CORE           Composer constraint for drupal/core (e.g. ^10.3, ^11).
#   RN_ELASTICSEARCH_URL  Optional; enables the Elasticsearch integration test.
#   BUILD_DIR             Where the Drupal project is built (default /tmp/rn-build).
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

echo "== Kernel tests"
mkdir -p "$BUILD_DIR/web/sites/simpletest"
cd "$BUILD_DIR/web"
# Deprecations in dependencies are reported, but do not fail the tests (as
# with PHPUnit 10 and later on Drupal 11).
SYMFONY_DEPRECATIONS_HELPER=weak \
SIMPLETEST_DB="sqlite://localhost/$BUILD_DIR/test.sqlite" \
SIMPLETEST_BASE_URL="http://localhost" \
  "$BUILD_DIR/vendor/bin/phpunit" -c "$BUILD_DIR/web/core/phpunit.xml.dist" "$MODULE/tests"
