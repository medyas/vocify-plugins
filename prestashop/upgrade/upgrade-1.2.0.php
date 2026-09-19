<?php
/**
 * Vocify AI - upgrade 1.1.0 → 1.2.0
 *
 * 1.2.0 adds the RETURN leg: a `webhook` front controller that accepts call
 * results from the platform and moves the merchant's order. Two things it
 * needs do not exist on a shop that installed 1.1.0, because `install()` runs
 * once and never again:
 *
 *   * the `vocify_call_results` table (idempotency record + merchant-visible
 *     note), and
 *   * the outcome → order-state mapping in `configuration`.
 *
 * Without this file an upgraded merchant would get a receiver whose table is
 * missing: every push would 500 on the first insert, the platform would burn
 * its five retries, and the order would never move. A fresh install is
 * unaffected — `createTables()`/`installConfiguration()` cover it there — so
 * this is the upgrade path and nothing else.
 *
 * Idempotent by construction: `CREATE TABLE IF NOT EXISTS`, and the mapping is
 * only seeded where the merchant has not already chosen a value.
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param VocifyAI $module
 * @return bool
 */
function upgrade_module_1_2_0($module)
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'vocify_call_results` (
        `id_result` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_order` INT(11) UNSIGNED NOT NULL,
        `call_sid` VARCHAR(191) NULL DEFAULT NULL,
        `outcome` VARCHAR(64) NOT NULL,
        `completed_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
        `id_order_state` INT(11) UNSIGNED NOT NULL DEFAULT 0,
        `note` TEXT,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id_result`),
        UNIQUE KEY `call_sid` (`call_sid`),
        KEY `id_order` (`id_order`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    // Seed the mapping from PrestaShop's own state pointers, which are ids and
    // therefore survive renaming and translation. Only where the merchant has
    // no value yet: re-running an upgrade must not overwrite a deliberate
    // choice.
    $defaults = VocifyStatusReceiver::defaultStateKeys();

    foreach (VocifyStatusReceiver::stateConfigKeys() as $outcome => $configKey) {
        if ((int)Configuration::get($configKey) > 0) {
            continue;
        }

        $stateId = isset($defaults[$outcome]) ? (int)Configuration::get($defaults[$outcome]) : 0;

        if (!Configuration::updateValue($configKey, $stateId)) {
            return false;
        }
    }

    // ⚠️ `displayAdminOrderSide` is NEW in 1.2.0 and the important one here.
    // Until now the module hooked only `displayAdminOrderLeft`, which
    // PrestaShop deprecated in 1.7.7.0 and 8.x dispatches nowhere — so on a
    // modern shop the module's order panel, and with it the call results the
    // merchant is meant to read, never appeared on the page. An upgraded shop
    // needs the live hook registered or the return leg works invisibly.
    //
    // `actionOrderStatusPostUpdate` (the receiver's re-entrancy guard hangs off
    // it) and `displayAdminOrderLeft` (kept for 1.7.0–1.7.6) were already
    // registered in 1.1.0; registerHook() is a no-op when they are, and repairs
    // an install where one was lost.
    return $module->registerHook('actionOrderStatusPostUpdate')
        && $module->registerHook('displayAdminOrderSide')
        && $module->registerHook('displayAdminOrderLeft');
}
