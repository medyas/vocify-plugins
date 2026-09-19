#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Head-less provisioning for the WooCommerce e2e shop. Runs INSIDE the
# `wordpress` container: `docker compose exec wordpress bash /e2e/wp-provision.sh`
#
# Everything the admin UI would otherwise be clicked through is done here with
# WP-CLI against the real option names registered by
# `includes/class-vocify-admin.php`. Nothing about the plugin's own behaviour is
# stubbed — only its settings are written, exactly as a merchant would.
#
# Required env (injected by orchestrate.mjs after the platform fixture exists):
#   VOCIFY_SITE_URL   http://<host>:<port>  — the host part becomes X-Domain
#   VOCIFY_WEBHOOK_URL, VOCIFY_API_KEY, VOCIFY_SIGNATURE_SECRET
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

WP="wp --allow-root"

: "${VOCIFY_SITE_URL:?VOCIFY_SITE_URL is required}"
: "${VOCIFY_WEBHOOK_URL:?VOCIFY_WEBHOOK_URL is required}"
: "${VOCIFY_API_KEY:?VOCIFY_API_KEY is required}"
: "${VOCIFY_SIGNATURE_SECRET:?VOCIFY_SIGNATURE_SECRET is required}"

# wp-cli is not in the base image; orchestrate.mjs caches the phar on the host
# and mounts it read-only, so no download happens inside the container.
if [ ! -x /usr/local/bin/wp ]; then
  cp /e2e-cache/wp-cli.phar /usr/local/bin/wp
  chmod +x /usr/local/bin/wp
fi

# "wp responds" and "the site is installed" are different states — poll the
# second one, not the first.
if ! $WP core is-installed 2>/dev/null; then
  # ⚠️ DOMAIN TRAP: the host part of --url is what the plugin reports as
  # X-Domain (send_webhook() -> extract_domain(get_site_url()), which uses
  # parse_url(PHP_URL_HOST) and therefore DROPS the port). The platform's
  # validateIntegrationDomain() strips scheme/www/trailing slash but NOT a
  # port, so integrations.store_domain must be the bare host with no port.
  # orchestrate.mjs derives both from one constant for exactly this reason.
  $WP core install \
    --url="${VOCIFY_SITE_URL}" \
    --title="Vocify E2E WC" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@e2e.invalid \
    --skip-email
fi

# WooCommerce itself — from wordpress.org, unpinned. Pin a version here only if
# a specific release actually breaks; guessing a pin from memory produced a
# version that does not match this image's PHP in an earlier draft.
if ! $WP plugin is-active woocommerce 2>/dev/null; then
  $WP plugin install woocommerce --activate
fi

# Activating WooCommerce only SCHEDULES its installer; `WC_Install::install()`
# then creates ~40 tables on a subsequent bootstrap. Doing anything else while
# that is in flight fails in ways that look like unrelated breakage — measured:
# "Warning: Could not activate the 'woocommerce' plugin" and
# "Error: Could not update option 'woocommerce_currency'" on a run where the DB
# was healthy and the plugin files were fine.
#
# `woocommerce_db_version` is written at the END of that installer, so it is the
# signal that WooCommerce is actually ready to be built on.
for _ in $(seq 1 60); do
  if [ -n "$($WP option get woocommerce_db_version 2>/dev/null)" ]; then
    break
  fi
  sleep 2
done
if [ -z "$($WP option get woocommerce_db_version 2>/dev/null)" ]; then
  echo "WC-PROVISION-FAIL: WooCommerce did not finish installing within 120s" >&2
  exit 1
fi

# ⚠️ ORDER MATTERS, AND IT IS A PLUGIN BUG THAT IT DOES — see PROGRESS.md §8.
# `register_activation_hook()` lives inside `init_hooks()`, which the plugin's
# constructor only reaches after an early `return` when `class_exists('WooCommerce')`
# is false. Activate Vocify while WooCommerce is inactive and the activation
# hook is never registered, so `create_tables()` never runs — and never will,
# because WordPress fires an activation hook once. The harness therefore
# asserts WooCommerce is really loaded before activating the plugin under test,
# so a failure below is a genuine regression rather than this known trap.
$WP eval 'if (!class_exists("WooCommerce")) { fwrite(STDERR, "WC-PROVISION-FAIL: WooCommerce class not loaded before activating the plugin under test\n"); exit(1); }'

# The plugin under test is already present via the read-only bind mount.
$WP plugin activate vocify-ai-woocommerce

# Store basics, so checkout/tax/shipping never block an order.
$WP option update woocommerce_currency "TND"
$WP option update woocommerce_default_country "TN:TN-11"
$WP option update woocommerce_store_address "12 Rue de Marseille"
$WP option update woocommerce_store_city "Tunis"
$WP option update woocommerce_store_postcode "1000"
$WP option update woocommerce_calc_taxes "no"
$WP option update woocommerce_enable_guest_checkout "yes"

# One purchasable product, idempotent across re-runs.
if ! $WP wc product list --sku=WH-100 --field=id --user=admin 2>/dev/null | grep -q .; then
  $WP wc product create \
    --user=admin \
    --name="Wireless Headphones" \
    --sku="WH-100" \
    --type=simple \
    --regular_price=129.90 \
    --manage_stock=false \
    --porcelain >/dev/null
fi

# Plugin settings — the exact option names from class-vocify-admin.php.
$WP option update vocify_enabled "yes"
$WP option update vocify_debug_mode "yes"
$WP option update vocify_webhook_url "${VOCIFY_WEBHOOK_URL}"
$WP option update vocify_api_key "${VOCIFY_API_KEY}"
$WP option update vocify_signature_secret "${VOCIFY_SIGNATURE_SECRET}"

# The plugin's activation hook creates these. If it silently did not run,
# $wpdb->insert() fails quietly and an empty log table would later read as
# "the plugin never tried to send" — a false negative worth failing loudly on.
# (`wp db query` is unusable here: the wordpress image ships no mysql client.)
$WP eval '
global $wpdb;
$missing = array();
foreach (array("vocify_webhook_logs", "vocify_failed_webhooks") as $t) {
    $name = $wpdb->prefix . $t;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $name)) !== $name) {
        $missing[] = $name;
    }
}
if ($missing) {
    fwrite(STDERR, "WC-PROVISION-FAIL: missing plugin tables (" . implode(", ", $missing) . ") — the activation hook did not run\n");
    exit(1);
}
'

# A must-use plugin (harness-owned, NOT part of the plugin under test) that
# records the exact bytes the plugin transmits. Used by the negative suite to
# replay/mutate real plugin-produced requests instead of hand-built ones.
mkdir -p /var/www/html/wp-content/mu-plugins
cp /e2e/mu-plugins/vocify-e2e-capture.php /var/www/html/wp-content/mu-plugins/
: > /var/www/html/wp-content/uploads/vocify-e2e-capture.jsonl 2>/dev/null || {
  mkdir -p /var/www/html/wp-content/uploads
  : > /var/www/html/wp-content/uploads/vocify-e2e-capture.jsonl
}
chown -R www-data:www-data /var/www/html/wp-content/uploads

echo "WC-PROVISION-OK site=$($WP option get siteurl) wc=$($WP plugin get woocommerce --field=version) vocify=$($WP plugin get vocify-ai-woocommerce --field=version)"
