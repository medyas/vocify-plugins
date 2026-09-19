<?php
/**
 * Repoint an already-installed PrestaShop at a hostname that did not exist
 * when it was installed — here, a Cloudflare quick-tunnel hostname.
 *
 * Run INSIDE the prestashop container:
 *   php /e2e/repoint-shop.php <hostname> [--skip-config] [--skip-cache]
 *
 * Prints one line of JSON describing what it changed.
 *
 * ## Why this exists at all
 *
 * `docker-compose.rt.yml` installs WordPress with the tunnel hostname as its
 * `siteurl`, because WP-CLI installs the site AFTER the tunnel is up.
 * PrestaShop cannot do that: the official image's `PS_INSTALL_AUTO` runs from
 * the entrypoint, before anything else in the stack is usable, and a quick
 * tunnel's hostname only exists once cloudflared has connected. So the shop is
 * installed against a placeholder and moved afterwards.
 *
 * ## What actually has to change, measured rather than assumed
 *
 * 1. **`ps_shop_url.domain` / `domain_ssl` on the `main` row — REQUIRED.**
 *    This is the routing decision and nothing else is.
 *    `Shop::initialize()` calls `findShopByHost($host)`, whose SQL is
 *    `WHERE su.domain = '<host>' OR su.domain_ssl = '<host>'`
 *    (classes/shop/Shop.php:1359). No match means no `$id_shop`, and the
 *    "no shop found" branch answers `302 Location: <default shop domain>` and
 *    `exit`s — BEFORE any controller runs. Measured on this stack: a POST to
 *    the tunnel hostname before the update answered
 *    `302 Location: https://ps-rt.vocify.test/?fc=module…`; after the update
 *    alone, the module front controller ran and answered its own JSON.
 *    The platform's adapter fetches with `redirect: 'error'`, so that 302 is a
 *    hard delivery failure, not a retry.
 *
 *    ⚠️ **UPDATE the existing row; never INSERT a second one.** A non-`main`
 *    row matches `findShopByHost()` and then trips the `!$is_main_uri` branch
 *    at Shop.php:379, which redirects to the main row's domain — a 302 that
 *    looks exactly like the one you were trying to remove.
 *
 * 2. **`PS_SHOP_DOMAIN` / `PS_SHOP_DOMAIN_SSL` in `ps_configuration` — NOT
 *    required, for either leg.** This was assumed at first and the assumption
 *    was wrong, so it is worth stating plainly.
 *
 *    The outbound leg looked like it would need them:
 *    `VocifyWebhookService::sendWebhook()` builds its `X-Domain` header from
 *    `Tools::getShopDomainSsl(true)`, and the platform 403s when that does not
 *    match `integrations.store_domain`
 *    (`platform/src/lib/auth/api-key.ts:validateIntegrationDomain`). But
 *    `Tools::getShopDomainSsl()` does NOT read `ps_configuration` — it calls
 *    `ShopUrl::getMainShopDomainSSL()` (classes/Tools.php:395), which is
 *    `SELECT domain, domain_ssl FROM ps_shop_url WHERE main = 1`
 *    (classes/shop/ShopUrl.php:178). Both legs therefore read the same table,
 *    and step 1 alone serves both.
 *
 *    Measured: with `ps_shop_url` repointed and `PS_SHOP_DOMAIN` /
 *    `PS_SHOP_DOMAIN_SSL` deliberately left at the stale placeholder, an order
 *    placed in the shop still reached the platform with **HTTP 201** — no
 *    domain mismatch, no 403.
 *
 *    They are written anyway, because a shop that has genuinely moved should
 *    not be left with a stale domain in its own settings, and other back-office
 *    code does read them. `--skip-config` leaves them alone, which is how the
 *    above was measured.
 *
 * 3. **`physical_uri` — no change needed, measured.** It is already `/`, and
 *    `findShopByHost()` returns it as `CONCAT(physical_uri, virtual_uri)` for
 *    `Shop::initialize()` to prefix-match against `/index.php?…`, which `/`
 *    always satisfies.
 *
 * 4. **`PS_SSL_ENABLED` — deliberately left at 0.** The tunnel terminates TLS
 *    and forwards plain http, so Apache sees http while the world sees https.
 *    Nothing here needs the flag: `FrontController::sslRedirection()` exempts
 *    POST unconditionally (classes/controller/FrontController.php:858), and
 *    `Tools::getShopDomainSsl(true)` returns an `https://` URL regardless of
 *    it. Turning it on only adds a way for a GET to bounce.
 *
 * 5. **Cache — NOT required, measured.** The whole repoint was measured to
 *    work with `--skip-config --skip-cache`, i.e. the `ps_shop_url` UPDATE and
 *    nothing else: the next request through the tunnel reached the module
 *    front controller. `ShopUrl`'s main-domain memo is a per-request static,
 *    not a file cache, so a new Apache request never sees a stale one.
 *
 *    The drop is kept (skippable) because it is cheap, makes the step
 *    order-independent, and a raw SQL UPDATE of `ps_configuration` — which a
 *    future maintainer might reach for instead of `Configuration::updateValue`
 *    — WOULD need it.
 */

require_once '/var/www/html/config/config.inc.php';

$host = isset($argv[1]) ? trim((string) $argv[1]) : '';

// A plain regex, not a `Validate::` helper: PrestaShop 8 has no `isHostname()`
// (only `isUrl()`), and calling a method that does not exist throws an Error
// before the usage message can be printed — measured. Bare hostname only, no
// scheme, port or path, which is what `ps_shop_url.domain` stores.
if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host)) {
    fwrite(STDERR, "usage: repoint-shop.php <bare-hostname> [--skip-config] [--skip-cache]\n");
    exit(1);
}

$flags = array_slice($argv, 2);
$skipConfig = in_array('--skip-config', $flags, true);
$skipCache = in_array('--skip-cache', $flags, true);

$db = Db::getInstance();
$changed = array();

// ---- 1. the routing rows -------------------------------------------------
$before = $db->executeS('SELECT id_shop_url, domain, domain_ssl, physical_uri, main FROM ' . _DB_PREFIX_ . 'shop_url');

// UPDATE the main row in place. See the note above: a second row is worse than
// no change, because it matches and then redirects.
$ok = $db->execute(
    'UPDATE ' . _DB_PREFIX_ . 'shop_url
        SET domain = "' . pSQL($host) . '", domain_ssl = "' . pSQL($host) . '"
      WHERE main = 1'
);

if (!$ok) {
    fwrite(STDERR, "PS-REPOINT-FAIL: could not update shop_url\n");
    exit(1);
}

$changed[] = 'ps_shop_url.domain';
$changed[] = 'ps_shop_url.domain_ssl';

// ---- 2. the shop's own settings — hygiene, not a requirement -------------
if (!$skipConfig) {
    Configuration::updateValue('PS_SHOP_DOMAIN', $host);
    Configuration::updateValue('PS_SHOP_DOMAIN_SSL', $host);
    $changed[] = 'PS_SHOP_DOMAIN';
    $changed[] = 'PS_SHOP_DOMAIN_SSL';
}

// ---- 3. caches -----------------------------------------------------------
if (!$skipCache) {
    // PrestaShop's own memo of the main domain, used by Tools::getShopDomain()
    // within a request that has already looked it up.
    ShopUrl::resetMainDomainCache();
    $changed[] = 'ShopUrl::resetMainDomainCache()';

    // Symfony's compiled container and PrestaShop's Smarty cache live under
    // var/cache. Deleting the compiled containers is enough; the directory
    // itself must survive and stay writable by www-data, or the next request
    // 500s with `Cannot rename "/tmp/FrontContainer.php…"`.
    foreach (array('prod', 'dev') as $env) {
        $dir = _PS_ROOT_DIR_ . '/var/cache/' . $env;
        if (is_dir($dir)) {
            Tools::deleteDirectory($dir, false);
            $changed[] = 'var/cache/' . $env;
        }
    }
}

echo json_encode(array(
    'host' => $host,
    'before' => $before,
    'after' => $db->executeS('SELECT id_shop_url, domain, domain_ssl, physical_uri, main FROM ' . _DB_PREFIX_ . 'shop_url'),
    'ps_shop_domain' => Configuration::get('PS_SHOP_DOMAIN'),
    'ps_shop_domain_ssl' => Configuration::get('PS_SHOP_DOMAIN_SSL'),
    'ps_ssl_enabled' => (int) Configuration::get('PS_SSL_ENABLED'),
    'changed' => $changed,
)), PHP_EOL;
