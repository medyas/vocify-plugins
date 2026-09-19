#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Head-less provisioning for the PrestaShop e2e shop. Runs INSIDE the
# `prestashop` container.
#
# Required env (injected by orchestrate.mjs after the platform fixture exists):
#   VOCIFY_WEBHOOK_URL, VOCIFY_API_KEY, VOCIFY_SIGNATURE_SECRET
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

cd /var/www/html

# The installer does not remove its own directory (PrestaShop/docker#204) and
# the front office refuses to serve normally while it is present.
rm -rf /var/www/html/install_e2e 2>/dev/null || true

# PrestaShop ships a console for exactly this; no admin HTTP round-trip needed.
if ! php bin/console prestashop:module list 2>/dev/null | grep -q vocifyai; then
  : # listing is best-effort; install below is the real check
fi
php bin/console prestashop:module install vocifyai

# Module settings live in the `configuration` table. Writing them through
# PrestaShop's own Configuration API (rather than raw SQL) keeps the cache and
# the multistore columns consistent.
php -r '
require_once "/var/www/html/config/config.inc.php";
Configuration::updateValue("VOCIFY_API_KEY", getenv("VOCIFY_API_KEY"));
Configuration::updateValue("VOCIFY_SIGNATURE_SECRET", getenv("VOCIFY_SIGNATURE_SECRET"));
Configuration::updateValue("VOCIFY_WEBHOOK_URL", getenv("VOCIFY_WEBHOOK_URL"));
Configuration::updateValue("VOCIFY_ENABLED", 1);
Configuration::updateValue("VOCIFY_DEBUG_MODE", 1);
echo "config written\n";
'

# Same guard as the WooCommerce side: if the install hook did not create the
# module tables, every log insert fails silently and an empty table would later
# read as "the module never tried to send".
php -r '
require_once "/var/www/html/config/config.inc.php";
$missing = array();
foreach (array("vocify_webhook_logs", "vocify_failed_webhooks") as $t) {
    $name = _DB_PREFIX_ . $t;
    if (!Db::getInstance()->executeS("SHOW TABLES LIKE \"" . pSQL($name) . "\"")) {
        $missing[] = $name;
    }
}
if ($missing) {
    fwrite(STDERR, "PS-PROVISION-FAIL: missing module tables (" . implode(", ", $missing) . ")\n");
    exit(1);
}
'

# One bootstrap, not three subshells: a subshell that fails silently produced
# `PS-PROVISION-OK ps= vocify= domain=` on a run where the shop was not ready,
# which the orchestrator then accepted as success. Fail loudly instead.
php -r '
require_once "/var/www/html/config/config.inc.php";
$module = Module::getInstanceByName("vocifyai");
$parts = array(
    "ps" => _PS_VERSION_,
    "vocify" => $module ? $module->version : "",
    "domain" => Configuration::get("PS_SHOP_DOMAIN"),
);
foreach ($parts as $k => $v) {
    if ($v === "" || $v === false) {
        fwrite(STDERR, "PS-PROVISION-FAIL: could not read {$k}
");
        exit(1);
    }
}
echo "PS-PROVISION-OK ps={$parts["ps"]} vocify={$parts["vocify"]} domain={$parts["domain"]}
";
'
