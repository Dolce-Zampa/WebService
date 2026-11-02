#!/usr/bin/env bash

# Static guard for DZ-92. It intentionally checks integration boundaries only;
# functional behaviour belongs to the checkout/backend test suites.
set -euo pipefail

MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOCATIONS_CONTROLLER="$MODULE_DIR/controllers/front/locations.php"
CHECKOUT_CONTROLLER="$MODULE_DIR/views/js/CheckOutController.js"
MODAL_CONTROLLER="$MODULE_DIR/views/js/MapModalController.js"

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

require_match() {
  local pattern="$1"
  local file="$2"
  rg -q -- "$pattern" "$file" || fail "Expected '$pattern' in ${file#$MODULE_DIR/}"
}

for source in "$LOCATIONS_CONTROLLER" "$CHECKOUT_CONTROLLER" "$MODAL_CONTROLLER"; do
  [[ -f "$source" ]] || fail "Required Packlink source is missing: ${source#$MODULE_DIR/}"
done

# The current native path must remain Packlink's LocationService plus the
# cart/carrier mapping. A dedicated endpoint may wrap it, but must not replace
# it with a direct carrier integration.
require_match 'LocationService::CLASS_NAME' "$LOCATIONS_CONTROLLER"
require_match 'locationService->getLocations' "$LOCATIONS_CONTROLLER"
require_match 'CartCarrierDropOffMapping' "$LOCATIONS_CONTROLLER"
require_match "method: 'getLocations'" "$MODAL_CONTROLLER"
require_match "method: 'postSelectedDropoff'" "$MODAL_CONTROLLER"
require_match 'getLocationsUrl' "$CHECKOUT_CONTROLLER"

# Direct InPost hosts or the InPost geowidget would bypass Packlink and are
# prohibited. Match URL-like strings only so carrier names/translations remain
# allowed in UI code.
if rg -n -i --glob '*.{php,js}' \
  'https?://[^[:space:]]*inpost|(?:api\.)?inpost[^[:space:]]*\.(?:it|com|pl)|geowidget' \
  "$MODULE_DIR/controllers" "$MODULE_DIR/classes" "$MODULE_DIR/views"; then
  fail 'Direct InPost endpoint or widget reference found in Packlink source'
fi

printf 'PASS: Packlink-only boundary and native pickup-point path are present.\n'
