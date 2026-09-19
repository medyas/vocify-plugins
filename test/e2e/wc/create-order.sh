#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Place a REAL WooCommerce order, inside the container.
#
# `wp wc shop_order create` is WooCommerce's OWN CLI command, which runs
# WooCommerce's REST order controller, which performs a normal order save —
# so `woocommerce_new_order` fires for real and the Vocify order handler runs
# synchronously inside it. Nothing here touches the webhook endpoint directly;
# that would bypass the PHP under test and prove nothing.
#
# ⚠️ The subcommand is `wp wc shop_order create`, NOT `wp wc order create`.
# Both spellings appear in WooCommerce's documentation history; the installed
# WooCommerce 11.1.1 registers only `shop_order` (verified by running `wp wc`).
#
# Env:
#   ORDER_PHONE   billing phone; pass an empty string to exercise the plugin's
#                 own payload validator (it must refuse to send at all)
#   ORDER_STATUS  initial status (default: processing)
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

WP="wp --allow-root"
PHONE="${ORDER_PHONE-+21600000000}"
STATUS="${ORDER_STATUS:-processing}"

PID="$($WP wc product list --sku=WH-100 --field=id --user=admin)"
if [ -z "$PID" ]; then
  echo "WC-ORDER-FAIL: product WH-100 not found — run wp-provision.sh first" >&2
  exit 1
fi

BILLING=$(cat <<JSON
{"first_name":"Amina","last_name":"Ben Ali","email":"amina@e2e.invalid","phone":"${PHONE}","address_1":"1 Avenue Habib Bourguiba","city":"Tunis","postcode":"1000","country":"TN"}
JSON
)
SHIPPING=$(cat <<JSON
{"first_name":"Amina","last_name":"Ben Ali","address_1":"1 Avenue Habib Bourguiba","city":"Tunis","postcode":"1000","country":"TN"}
JSON
)

ORDER_ID="$($WP wc shop_order create \
  --user=admin \
  --status="${STATUS}" \
  --currency=TND \
  --payment_method=cod \
  --payment_method_title="Cash on delivery" \
  --billing="${BILLING}" \
  --shipping="${SHIPPING}" \
  --line_items="[{\"product_id\":${PID},\"quantity\":1}]" \
  --porcelain)"

echo "WC-ORDER-CREATED order_id=${ORDER_ID}"
