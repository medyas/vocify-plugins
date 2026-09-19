<?php
/**
 * Vocify AI - Call-result receiver (platform → shop).
 *
 * The RETURN half of the round trip, and the PrestaShop counterpart of
 * WooCommerce's `Vocify_AI_Status_Receiver`. After the AI call completes, the
 * platform's cron worker POSTs the outcome here and the merchant's order
 * changes state, so their own orders screen shows what the customer said on
 * the phone.
 *
 *   POST {store}/index.php?fc=module&module=vocifyai&controller=webhook
 *
 * Before 2026-09-19 this file did not exist — `controllers/front/` held only
 * `cron.php` — so the platform's POST hit PrestaShop's 404 page and a
 * PrestaShop merchant's orders never moved. See `PROGRESS.md` §10.
 *
 * ## This file is deliberately thin
 *
 * Authentication, freshness, parsing, idempotency ordering and the
 * outcome→state mapping all live in `classes/VocifyStatusReceiver.php`, which
 * loads without PrestaShop and is unit-tested. This controller only does the
 * things that genuinely need PrestaShop: read the request, read
 * `Configuration`, load the `Order`, apply the verdict with `OrderHistory`,
 * and emit JSON.
 *
 * ## The URL form, and why it is the `index.php?fc=module…` one
 *
 * `/module/vocifyai/webhook` is the FRIENDLY-URL rewrite and only resolves
 * when friendly URLs are switched on, which is a per-shop setting the platform
 * cannot see. `index.php?fc=module&module=vocifyai&controller=webhook` is the
 * canonical dispatcher entry point and resolves either way. The platform's
 * `buildModuleWebhookUrl()` (platform/src/lib/adapters/outbound/prestashop.adapter.ts)
 * targets exactly this.
 *
 * ## Two PrestaShop behaviours that would break this, and why they do not
 *
 * - `FrontController::sslRedirection()` 301s an http request when
 *   `PS_SSL_ENABLED` is on — but it explicitly exempts POST
 *   (`$_SERVER['REQUEST_METHOD'] != 'POST'`, classes/controller/FrontController.php:858).
 *   That matters because the platform fetches with `redirect: 'error'`, so any
 *   3xx is a hard failure, not a retry. `$this->ssl` is left alone for the
 *   same reason.
 * - `canonicalRedirection()` returns early unless the method is GET and the
 *   controller sets `$php_self`; a module front controller sets neither.
 *
 * ## `VOCIFY_ENABLED` is deliberately NOT checked here
 *
 * The toggle governs the OUTBOUND direction — whether this shop forwards its
 * orders. A call result arriving here is the outcome of a call that has
 * already been placed and already been paid for, so refusing it because the
 * merchant has since switched the integration off would lose real information
 * about a real customer and leave the order stuck. WooCommerce's receiver does
 * not check its equivalent either. Authentication is the HMAC, which is
 * unaffected by the toggle.
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VocifyAIWebhookModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function __construct()
    {
        parent::__construct();
        $this->display_header = false;
        $this->display_footer = false;
        $this->display_column_left = false;
        $this->display_column_right = false;
    }

    /**
     * Verify the push, apply it to the order, answer JSON.
     *
     * @return void
     */
    public function initContent()
    {
        if (Tools::strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
            $this->respond(405, array(
                'success' => false,
                'code' => 'vocify_method_not_allowed',
                'message' => 'POST only',
            ));
        }

        $receiver = new VocifyStatusReceiver();

        // The RAW transmitted bytes. NOT Tools::getValue() and not a re-encode
        // of the decoded body: re-encoding changes key order and float
        // formatting, and every signature would fail.
        $rawBody = (string)file_get_contents('php://input');

        $request = $receiver->readRequest(
            $rawBody,
            isset($_SERVER['HTTP_X_VOCIFY_TIMESTAMP']) ? (string)$_SERVER['HTTP_X_VOCIFY_TIMESTAMP'] : '',
            isset($_SERVER['HTTP_X_VOCIFY_SIGNATURE']) ? (string)$_SERVER['HTTP_X_VOCIFY_SIGNATURE'] : '',
            (string)Configuration::get('VOCIFY_SIGNATURE_SECRET')
        );

        if (!$request['ok']) {
            $this->respond($request['http'], array(
                'success' => false,
                'code' => $request['code'],
                'message' => $request['message'],
            ));
        }

        // The platform's `external_id` for a PrestaShop order is the numeric
        // `id_order` — `VocifyWebhookService::transformOrder()` sends
        // `(string)$order->id`. An unknown id gives an unloaded object, which
        // is a 404 and not a 500: the platform should stop retrying an order
        // this shop does not have (deleted, or a different store), not treat it
        // as a transient server fault.
        //
        // `ctype_digit` before the cast, because `(int)"12abc"` is 12 — a
        // half-numeric id would silently apply the result to a real order
        // rather than being refused. The input is authenticated, so this is
        // about not acting on something malformed, not about an attacker.
        $order = ctype_digit($request['order_id']) && (int)$request['order_id'] > 0
            ? new Order((int)$request['order_id'])
            : null;

        if (!$order || !Validate::isLoadedObject($order)) {
            $this->respond(404, array(
                'success' => false,
                'code' => VocifyStatusReceiver::ERR_ORDER_NOT_FOUND,
                'message' => 'No such order: ' . $request['order_id'],
            ));
        }

        $callSid = (string)$request['call_sid'];

        $verdict = $receiver->decide(
            $request,
            $callSid !== '' && $this->hasAppliedCall($callSid),
            $this->lastCompletedAt((int)$order->id)
        );

        if ($verdict['action'] !== VocifyStatusReceiver::ACTION_APPLY) {
            // 200 and not 409: nothing is wrong. The order is already in the
            // state this push would have produced, or in a newer one, so the
            // platform should mark the call synced and stop retrying.
            $this->respond(200, array(
                'success' => true,
                'orderId' => (string)$order->id,
                'status' => (string)$order->current_state,
                'changed' => false,
                'message' => $verdict['message'],
            ));
        }

        $previousState = (int)$order->current_state;
        $targetState = $verdict['state_key'] === null ? 0 : $this->resolveStateId($verdict['state_key']);
        $warning = null;

        if ($verdict['state_key'] !== null && $targetState === 0) {
            // The merchant chose a state that has since been deleted AND the
            // PrestaShop default it falls back to is missing too. Record the
            // result and tell the merchant rather than 500-ing: the outcome is
            // still worth keeping, and a 5xx would make the platform retry a
            // push that can never succeed.
            $warning = 'No usable order state configured for outcome "' . $request['outcome'] . '"';
            PrestaShopLogger::addLog(
                'Vocify AI: ' . $warning . ' (order ' . (int)$order->id . ')',
                3,
                null,
                'Order',
                (int)$order->id
            );
        }

        $changed = false;

        if ($targetState > 0 && $targetState !== $previousState) {
            $changed = $this->applyOrderState($order, $targetState);
        }

        $this->recordResult($order, $request, $receiver->buildNote($request['outcome'], $request['call_data']), $changed ? $targetState : $previousState);

        $response = array(
            'success' => true,
            'orderId' => (string)$order->id,
            'previousStatus' => (string)$previousState,
            'status' => (string)($changed ? $targetState : $previousState),
            'changed' => $changed,
        );

        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        $this->respond(200, $response);
    }

    /**
     * Move the order into `$stateId`, with the module's own outbound hooks
     * suppressed for the duration.
     *
     * ⚠️ This is the re-entrancy trap. `OrderHistory::changeIdOrderState()`
     * fires `actionOrderStatusPostUpdate` (classes/order/OrderHistory.php:421),
     * which this module hooks and forwards to the platform as an OUTBOUND
     * webhook. Without the guard, every inbound result would bounce straight
     * back out: the platform dedupes it, so nothing breaks visibly, but the
     * merchant's shop makes a pointless signed HTTPS round trip — with the
     * module's 3×-backoff retry loop behind it — for every call result it
     * receives. The guard is a static because the hook fires synchronously in
     * this same request, in this same process; anything more durable would be
     * a moving part with nothing to do.
     *
     * `add()` and not `addWithemail()`: the platform pushing a call outcome is
     * not the merchant deciding to notify a customer, and sending order-status
     * emails as a side effect of an AI call is not a choice this module should
     * make silently. A merchant who wants the mail can send it from the BO.
     *
     * @param Order $order
     * @param int   $stateId
     * @return bool Whether the state actually changed.
     */
    private function applyOrderState($order, $stateId)
    {
        VocifyAI::$suppressOutboundWebhooks = true;

        try {
            $history = new OrderHistory();
            $history->id_order = (int)$order->id;
            $history->id_employee = 0;
            $history->changeIdOrderState((int)$stateId, $order, true);

            if (!$history->add()) {
                PrestaShopLogger::addLog(
                    'Vocify AI: could not write order history for order ' . (int)$order->id,
                    3,
                    null,
                    'Order',
                    (int)$order->id
                );

                return false;
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Vocify AI: failed to apply call result to order ' . (int)$order->id . ' - ' . $e->getMessage(),
                3,
                null,
                'Order',
                (int)$order->id
            );

            return false;
        } finally {
            VocifyAI::$suppressOutboundWebhooks = false;
        }

        return true;
    }

    /**
     * Resolve the numeric order-state id for a mapping.
     *
     * Order-state NAMES live in `order_state_lang`: they are per-language and
     * a merchant can rename them, so matching on "Processing in progress" (or
     * "Préparation en cours") would be matching on a display string that the
     * merchant is free to change and that differs per install. What is stored
     * and compared here is always the numeric `id_order_state`.
     *
     * Three layers, in order: the merchant's chosen state, PrestaShop's own
     * built-in pointer for that outcome (`PS_OS_PREPARATION` and friends —
     * themselves ids, not names), then nothing. A state can be deleted at any
     * time, so each candidate is loaded and validated before it is used.
     *
     * @param string $stateKey One of VocifyStatusReceiver::stateConfigKeys().
     * @return int Order-state id, or 0 when none is usable.
     */
    private function resolveStateId($stateKey)
    {
        $configured = (int)Configuration::get($stateKey);

        if ($this->stateExists($configured)) {
            return $configured;
        }

        $outcome = array_search($stateKey, VocifyStatusReceiver::stateConfigKeys(), true);
        $defaults = VocifyStatusReceiver::defaultStateKeys();

        if ($outcome !== false && isset($defaults[$outcome])) {
            $fallback = (int)Configuration::get($defaults[$outcome]);

            if ($this->stateExists($fallback)) {
                return $fallback;
            }
        }

        return 0;
    }

    /**
     * @param int $stateId
     * @return bool
     */
    private function stateExists($stateId)
    {
        if ((int)$stateId <= 0) {
            return false;
        }

        $state = new OrderState((int)$stateId);

        return Validate::isLoadedObject($state);
    }

    /**
     * Has this exact call result already been applied?
     *
     * Scoped to `call_sid` alone, which is the table's UNIQUE key: a callSid
     * identifies one Call on the platform and belongs to exactly one order, so
     * a hit anywhere means "seen".
     *
     * @param string $callSid
     * @return bool
     */
    private function hasAppliedCall($callSid)
    {
        return (bool)Db::getInstance()->getValue(
            'SELECT `id_result` FROM `' . _DB_PREFIX_ . 'vocify_call_results`
             WHERE `call_sid` = "' . pSQL($callSid) . '"'
        );
    }

    /**
     * `completedAt` of the most recent result already applied to this order,
     * as a Unix timestamp, or null when there is none.
     *
     * @param int $orderId
     * @return int|null
     */
    private function lastCompletedAt($orderId)
    {
        $value = Db::getInstance()->getValue(
            'SELECT MAX(`completed_at`) FROM `' . _DB_PREFIX_ . 'vocify_call_results`
             WHERE `id_order` = ' . (int)$orderId
        );

        return $value === false || $value === null || (int)$value <= 0 ? null : (int)$value;
    }

    /**
     * Persist the applied result. This row is both the idempotency record and
     * the merchant-visible note — the module's own order panel
     * (`hookDisplayAdminOrderLeft`) renders it.
     *
     * `INSERT IGNORE` rather than a check-then-insert: the UNIQUE index on
     * `call_sid` is what actually makes a concurrent duplicate a no-op, and a
     * read followed by a write is not atomic.
     *
     * @param Order  $order
     * @param array  $request A successful readRequest() result.
     * @param string $note
     * @param int    $stateId The state the order is in after this result.
     * @return void
     */
    private function recordResult($order, array $request, $note, $stateId)
    {
        $callSid = (string)$request['call_sid'];

        Db::getInstance()->insert(
            'vocify_call_results',
            array(
                'id_order' => (int)$order->id,
                // NULL, not '': MySQL allows any number of NULLs in a UNIQUE
                // index, so results the platform sent without a callSid can
                // all be recorded instead of colliding with each other.
                'call_sid' => $callSid === '' ? null : pSQL($callSid),
                'outcome' => pSQL((string)$request['outcome']),
                'completed_at' => $request['completed_at'] === null ? 0 : (int)$request['completed_at'],
                'id_order_state' => (int)$stateId,
                // pSQL(..., true) — the second argument keeps newlines. The
                // default path runs strip_tags(Tools::nl2br($s)), which turns
                // every newline into a <br /> and then deletes it, flattening
                // a multi-line note into one run-on line. The note is already
                // tag-stripped and control-character-stripped by
                // VocifyStatusReceiver::buildNote(), and the template escapes
                // it on output.
                'note' => pSQL($note, true),
                'created_at' => date('Y-m-d H:i:s'),
            ),
            true,
            true,
            Db::INSERT_IGNORE
        );
    }

    /**
     * Emit a JSON response and stop.
     *
     * Buffers are cleaned first: `FrontController::init()` opens one
     * (`ob_start()`), and anything a hook or a notice wrote into it would be
     * prepended to the body and make it unparsable JSON.
     *
     * @param int   $status
     * @param array $body
     * @return void
     */
    private function respond($status, array $body)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code((int)$status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        die(json_encode($body));
    }
}
