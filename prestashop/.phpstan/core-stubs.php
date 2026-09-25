<?php
/**
 * Hand-written PHPStan stubs for the slice of PrestaShop core this module
 * touches (`classes/`, `controllers/front/`, `vocifyai.php`, `upgrade/`).
 *
 * ## Why hand-written, not `prestashop/php-dev-tools`
 *
 * That package is PrestaShop's *coding-standards* tool (PHP-CS-Fixer rules,
 * namespace `PrestaShop\CodingStandards\`) — checked 2026-09-25 via
 * `composer show prestashop/php-dev-tools --all`. It ships no PHPStan
 * configuration and no core stubs; its only relation to PHPStan is a
 * `suggests: phpstan/phpstan ^0.12`, an EOL 2020-era release. There is no
 * official PrestaShop PHPStan-stubs package to prefer over hand-written ones.
 * The realistic alternative was a full PrestaShop core checkout as a
 * dev dependency (some module repos do this via a git submodule or a
 * `prestashop/prestashop` dev-require) — rejected: this workspace has no PS
 * core anywhere, pulling one in just to lint ~10 files violates
 * #FewerMovingParts, and it would make `composer install` require a
 * multi-hundred-MB download in CI for every run.
 *
 * ## Scope
 *
 * Every class/method/constant below exists because `grep`-ing `new `,
 * `extends`, `instanceof` and `Class::` across the module's real code (not
 * comments) found it called. Nothing here is speculative. Property lists are
 * exactly the properties the module reads or writes — this is deliberately
 * not a faithful reproduction of PrestaShop core, which would be a much
 * larger, staler maintenance burden for no benefit to a 10-file module.
 *
 * Loaded via `scanFiles` (declarations only — these classes are never
 * instantiated), never via the dev autoloader: kept outside `classes/` and
 * `tests/` so `composer.json`'s `autoload-dev` classmap (which scans
 * `tests/`) can never pick them up and shadow the real classes.
 */

class Module
{
    /**
     * PrestaShop's real base classes do not declare native return types on
     * these (kept loose across the PHP 7.1+ range this module supports, and
     * to leave every module's override unconstrained) — only PHPDoc. A
     * native `: bool` here would make PHPStan flag every real module's
     * un-typed override (VocifyAI::install()/uninstall(), which return bool
     * without declaring it) as an LSP violation that does not exist in the
     * real class hierarchy.
     */

    /** @var string */
    public $name;

    /** @var string */
    public $tab;

    /** @var string */
    public $version;

    /** @var string */
    public $author;

    /** @var int */
    public $need_instance;

    /** @var array<string,string> */
    public $ps_versions_compliancy;

    /** @var bool */
    public $bootstrap;

    /** @var string */
    public $displayName;

    /** @var string */
    public $description;

    /** @var string */
    public $confirmUninstall;

    /** @var string */
    public $table;

    /** @var string */
    public $identifier;

    /** @var Context */
    public $context;

    public function __construct()
    {
    }

    /**
     * @return bool
     */
    public function install()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        return true;
    }

    /**
     * @param string $hookName
     */
    public function registerHook($hookName): bool
    {
        return true;
    }

    /**
     * @param string $string
     * @param string|bool $specific
     */
    public function l($string, $specific = false): string
    {
        return (string)$string;
    }

    /**
     * @param string $file
     * @param string $template
     */
    public function display($file, $template): string
    {
        return '';
    }

    /**
     * @param string $message
     */
    public function displayError($message): string
    {
        return (string)$message;
    }

    /**
     * @param string $message
     */
    public function displayConfirmation($message): string
    {
        return (string)$message;
    }

    /**
     * @param string $message
     */
    public function displayWarning($message): string
    {
        return (string)$message;
    }
}

class ModuleFrontController
{
    /** @var bool */
    public $ajax;

    /** @var bool */
    public $display_header;

    /** @var bool */
    public $display_footer;

    /** @var bool */
    public $display_column_left;

    /** @var bool */
    public $display_column_right;

    /** @var bool */
    public $ssl;

    public function __construct()
    {
    }

    /**
     * @return void
     */
    public function initContent()
    {
    }
}

class Db
{
    const INSERT_IGNORE = 4;

    public static function getInstance(): self
    {
        return new self();
    }

    /**
     * @param string $sql
     */
    public function execute($sql): bool
    {
        return true;
    }

    /**
     * @param string $sql
     * @return array<int,array<string,mixed>>
     */
    public function executeS($sql): array
    {
        return array();
    }

    /**
     * Real signature returns null too: an aggregate (MAX/MIN/...) over zero
     * matching rows is SQL NULL, not false — see
     * VocifyWebhookService::retryFailedWebhooks() and
     * VocifyAIWebhookModuleFrontController::lastCompletedAt(), both of which
     * check for it explicitly.
     *
     * @param string $sql
     * @return string|int|bool|null
     */
    public function getValue($sql)
    {
        return false;
    }

    /**
     * @param string $table
     * @param array<string,mixed> $data
     */
    public function insert($table, $data, bool $nullValues = false, bool $useCache = true, int $type = 1, bool $addPrefix = false): bool
    {
        return true;
    }

    /**
     * @param string $table
     * @param array<string,mixed> $data
     * @param string $where
     */
    public function update($table, $data, $where = '', int $limit = 0, bool $nullValues = false, bool $useCache = true, bool $addPrefix = false): bool
    {
        return true;
    }

    /**
     * @param string $table
     * @param string $where
     */
    public function delete($table, $where = '', int $limit = 0, bool $useCache = true, bool $addPrefix = false): bool
    {
        return true;
    }
}

class Configuration
{
    /**
     * @param string $key
     * @param int|null $idLang
     * @param int|null $idShopGroup
     * @param int|null $idShop
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return $default;
    }

    /**
     * @param string $key
     * @param mixed $values
     */
    public static function updateValue($key, $values, bool $html = false, $idShopGroup = null, $idShop = null): bool
    {
        return true;
    }

    /**
     * @param string $key
     */
    public static function deleteByName($key): bool
    {
        return true;
    }
}

class Language
{
    /** @var int */
    public $id;
}

class Smarty
{
    /**
     * @param array<string,mixed>|string $tplVar
     * @param mixed $value
     */
    public function assign($tplVar, $value = null): void
    {
    }
}

class Controller
{
    /**
     * @return Language[]
     */
    public function getLanguages(): array
    {
        return array();
    }
}

class Context
{
    /** @var Language */
    public $language;

    /**
     * Not set in every context (e.g. CLI/cron) — VocifyWebhookService::
     * extractItems() isset()-guards it before use.
     *
     * @var Link|null
     */
    public $link;

    /** @var Controller */
    public $controller;

    /** @var Smarty */
    public $smarty;

    public static function getContext(): self
    {
        return new self();
    }
}

class Link
{
    /**
     * @param string $module
     * @param string $controller
     * @param array<string,mixed> $params
     * @param bool|null $ssl
     */
    public function getModuleLink($module, $controller = '', $params = array(), $ssl = null): string
    {
        return '';
    }

    /**
     * @param string $name
     * @param int $idImage
     * @param string|null $type
     */
    public function getImageLink($name, $idImage, $type = null): string
    {
        return '';
    }

    /**
     * @param string $controller
     * @param bool $withToken
     * @param array<string,mixed> $params
     */
    public function getAdminLink($controller, $withToken = true, $params = array()): string
    {
        return '';
    }
}

class Validate
{
    /**
     * @param object|null $object
     */
    public static function isLoadedObject($object): bool
    {
        return true;
    }

    /**
     * @param string $url
     */
    public static function isAbsoluteUrl($url): bool
    {
        return true;
    }
}

class Tools
{
    /**
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed
     */
    public static function getValue($key, $defaultValue = false)
    {
        return $defaultValue;
    }

    /**
     * @param string $name
     */
    public static function isSubmit($name): bool
    {
        return false;
    }

    public static function passwdGen(int $length = 8, string $flag = 'ALPHANUMERIC'): string
    {
        return '';
    }

    /**
     * @param string $str
     */
    public static function strtoupper($str): string
    {
        return strtoupper((string)$str);
    }

    public static function getShopDomainSsl(bool $withSsl = false, bool $entities = false): string
    {
        return '';
    }

    /**
     * @param string $tab
     */
    public static function getAdminTokenLite($tab): string
    {
        return '';
    }
}

class PrestaShopLogger
{
    /**
     * @param string $message
     * @param int $severity
     * @param int|null $errorCode
     * @param string|null $objectType
     * @param int|null $objectId
     * @param bool $allowDuplicate
     */
    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false): void
    {
    }
}

class Group
{
    public static function getPriceDisplayMethod(int $idGroup): int
    {
        return 0;
    }
}

class ImageType
{
    /**
     * @param string $type
     */
    public static function getFormattedName($type): string
    {
        return (string)$type;
    }
}

class HelperForm
{
    /** @var bool */
    public $show_toolbar;

    /** @var string */
    public $table;

    /** @var Module */
    public $module;

    /** @var int */
    public $default_form_language;

    /** @var int */
    public $allow_employee_form_lang;

    /** @var string */
    public $identifier;

    /** @var string */
    public $submit_action;

    /** @var string */
    public $currentIndex;

    /** @var string */
    public $token;

    /** @var array<string,mixed> */
    public $tpl_vars;

    /**
     * @param array<int,mixed> $fields
     */
    public function generateForm($fields): string
    {
        return '';
    }
}

/**
 * Base of every PrestaShop entity. Only `$id` is common to all of them; each
 * subclass below adds exactly the properties this module reads.
 */
class ObjectModel
{
    /**
     * Null until loaded from the database — see the isset($order->id) guard
     * in VocifyWebhookService::transformOrder()'s catch block, which exists
     * precisely because a failed `new Order($id)` can leave this unset.
     *
     * @var int|null
     */
    public $id;
}

class OrderPayment
{
    /** @var string */
    public $transaction_id;

    /** @var string */
    public $date_add;
}

class Order extends ObjectModel
{
    /** @var string */
    public $reference;

    /** @var int */
    public $current_state;

    /** @var int */
    public $id_customer;

    /** @var int */
    public $id_address_delivery;

    /** @var int */
    public $id_address_invoice;

    /** @var int */
    public $id_currency;

    /** @var int */
    public $id_carrier;

    /** @var string */
    public $payment;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    /** @var float */
    public $total_products;

    /** @var float */
    public $total_discounts;

    /** @var float */
    public $total_shipping;

    /** @var float */
    public $total_paid;

    /** @var float */
    public $total_paid_tax_incl;

    /** @var float */
    public $total_paid_tax_excl;

    /**
     * @param int|null $id
     */
    public function __construct($id = null)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getProducts(): array
    {
        return array();
    }

    /**
     * @return OrderPayment[]
     */
    public function getOrderPaymentCollection(): array
    {
        return array();
    }

    public function getTotalPaid(): float
    {
        return 0.0;
    }
}

class Customer extends ObjectModel
{
    /** @var string */
    public $firstname;

    /** @var string */
    public $lastname;

    /** @var string */
    public $email;

    /**
     * Nullable: `phone`/`phone_mobile` are optional shop fields, and
     * VocifyWebhookService::transformOrder() isset()-guards both before
     * reading them.
     *
     * @var string|null
     */
    public $phone;

    /** @var string|null */
    public $phone_mobile;

    /** @var int */
    public $id_default_group;

    /**
     * @param int|null $id
     */
    public function __construct($id = null)
    {
    }
}

class Address extends ObjectModel
{
    /** @var string */
    public $firstname;

    /** @var string */
    public $lastname;

    /** @var string */
    public $company;

    /** @var string */
    public $address1;

    /** @var string */
    public $address2;

    /** @var string */
    public $city;

    /** @var string */
    public $postcode;

    /** @var string */
    public $phone;

    /** @var string */
    public $phone_mobile;

    /** @var int */
    public $id_country;

    /** @var int */
    public $id_state;

    /**
     * @param int|null $id
     */
    public function __construct($id = null)
    {
    }
}

class Currency extends ObjectModel
{
    /** @var string|null */
    public $iso_code;

    /**
     * @param int|null $id
     */
    public function __construct($id = null)
    {
    }
}

class Country extends ObjectModel
{
    /** @var string|null */
    public $iso_code;

    /**
     * @param int|null $id
     */
    public function __construct($id = null)
    {
    }
}

class State extends ObjectModel
{
    /** @var string|null */
    public $iso_code;

    /**
     * @param int|null $id
     */
    public function __construct($id = null)
    {
    }
}

class OrderState extends ObjectModel
{
    /** @var string|null */
    public $name;

    /** @var int */
    public $paid;

    /** @var int */
    public $shipped;

    /** @var int */
    public $delivery;

    /**
     * Not a real PrestaShop 1.7/8.x property — the module code defends
     * against it with `isset()` in case a future core version adds it.
     *
     * @var string|null
     */
    public $slug;

    /**
     * @param int|null $id
     * @param int|null $idLang
     */
    public function __construct($id = null, $idLang = null)
    {
    }

    /**
     * @param int $idLang
     * @return array<int,array<string,mixed>>
     */
    public static function getOrderStates($idLang): array
    {
        return array();
    }
}

/**
 * PrestaShop's real global SQL-escaping helper (classes/db/Db.php). A plain
 * function, not a class method — declared here so calls to it don't report
 * as `Function pSQL not found.` across VocifyWebhookService and the webhook
 * controller.
 *
 * @param string $string
 * @param bool   $htmlOk
 * @return string
 */
function pSQL($string, $htmlOk = false)
{
    return (string)$string;
}

class OrderHistory extends ObjectModel
{
    /** @var int */
    public $id_order;

    /** @var int */
    public $id_employee;

    /**
     * @param int $newOrderStateId
     * @param Order $orderOrIdOrder
     * @param bool $useExistingPayment
     */
    public function changeIdOrderState($newOrderStateId, $orderOrIdOrder, $useExistingPayment = false): void
    {
    }

    public function add(bool $autoDate = true, bool $nullValues = false): bool
    {
        return true;
    }
}
