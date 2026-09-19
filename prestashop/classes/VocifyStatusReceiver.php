<?php
/**
 * Vocify AI Status Receiver — the decision half of the RETURN leg.
 *
 * Everything else in this module pushes orders OUT to the platform. The return
 * leg listens: after the AI call completes, the platform's cron worker POSTs
 * the outcome back and the merchant's own order changes state, so they see on
 * their orders screen what the customer said on the phone.
 *
 * Before 2026-09-19 PrestaShop had no inbound controller at all
 * (`controllers/front/` held only `cron.php`), so the platform's POST answered
 * with PrestaShop's 404 page and no order ever moved. WooCommerce got its
 * receiver the same day; this is the PrestaShop half. See `PROGRESS.md` §10.
 *
 * ## Contract (identical in both plugins)
 *
 *   POST {store}/index.php?fc=module&module=vocifyai&controller=webhook
 *
 *   X-Vocify-Timestamp: 2026-09-19T13:45:02.000Z
 *   X-Vocify-Signature: hex HMAC-SHA256(secret, "{X-Vocify-Timestamp}.{rawBody}")
 *
 *   { "orderId": "12", "status": "confirmed",
 *     "callData": { "callSid": "…", "duration": 42,
 *                   "completedAt": "2026-09-19T13:44:20Z", "notes": "…" } }
 *
 * `secret` is the SAME `VOCIFY_SIGNATURE_SECRET` the merchant already pastes in
 * for the outbound direction (the platform's `api_keys.signature_secret`). One
 * shared secret per agent, used symmetrically — a second credential with its
 * own settings field would be one more thing to get wrong, and the platform has
 * no UI that populates `integrations.webhook_secret` (NULL on every integration
 * in production).
 *
 * The signature binds the timestamp INTO the signed message rather than letting
 * it ride alongside, matching the inbound contract fixed on 2026-09-18 and
 * `VocifySigner`. Without that binding the freshness window is decorative: a
 * captured request can be replayed forever with a fresh timestamp.
 *
 * ## Why this class knows nothing about PrestaShop
 *
 * `tests/bootstrap.php` deliberately does not bootstrap PrestaShop — the unit
 * suite loads the platform-agnostic classes and nothing else. A
 * `ModuleFrontController` subclass cannot be tested under it at all. So every
 * decision lives here as pure functions over scalars and arrays, and
 * `controllers/front/webhook.php` is a thin adapter that reads the request,
 * reads `Configuration`, calls this, and applies the verdict with
 * `OrderHistory`. Every rejection branch below is covered by
 * `tests/VocifyStatusReceiverTest.php`.
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VocifyStatusReceiver
{
    /**
     * How far the platform's clock may be from this shop's, in seconds.
     * Two-sided, and the same 300s the platform allows in the other direction.
     */
    const TIMESTAMP_TOLERANCE = 300;

    /** No signing secret configured locally — reject rather than accept unsigned. */
    const ERR_NOT_CONFIGURED = 'vocify_not_configured';

    /** X-Vocify-Signature or X-Vocify-Timestamp absent. */
    const ERR_SIGNATURE_MISSING = 'vocify_signature_missing';

    /** X-Vocify-Timestamp is not a parsable date. */
    const ERR_TIMESTAMP_INVALID = 'vocify_timestamp_invalid';

    /** X-Vocify-Timestamp is outside the freshness window. */
    const ERR_TIMESTAMP_STALE = 'vocify_timestamp_stale';

    /** The HMAC does not match the transmitted bytes. */
    const ERR_SIGNATURE_MISMATCH = 'vocify_signature_mismatch';

    /** Body is not a JSON object, or orderId/status are missing. */
    const ERR_PAYLOAD_INVALID = 'vocify_payload_invalid';

    /** This shop has no such order — 404, so the platform stops retrying. */
    const ERR_ORDER_NOT_FOUND = 'vocify_order_not_found';

    /** Apply the result: move the order (when `state_key` is set) and note it. */
    const ACTION_APPLY = 'apply';

    /** This exact callSid was already applied — no-op, HTTP 200. */
    const ACTION_DUPLICATE = 'duplicate';

    /** An older result from a different call — no-op, HTTP 200. */
    const ACTION_SUPERSEDED = 'superseded';

    /**
     * Outcomes that move the order, mapped to the module Configuration key
     * holding the merchant's chosen target order-state ID.
     *
     * Deliberately short, and the same three WooCommerce moves on. An AI call
     * answers exactly one question — "does the customer still want this
     * order?" — so only a yes and a no move the order. `no_answer` and
     * `failed` (the platform's other two pushed statuses, see
     * `outcomeToAdapterStatus()` in `ecommerce-sync.service.ts`) say nothing
     * about the customer's intent, so they are recorded as a merchant-visible
     * order message and the merchant decides.
     *
     * The VALUES are Configuration keys, never state names. PrestaShop order
     * states live in `order_state_lang`: their names are per-language and a
     * merchant can rename them freely, so matching on "Payment accepted" or
     * "Paiement accepté" is matching on a display string. The merchant picks
     * the target state in the module's settings and what is stored is the
     * numeric `id_order_state`.
     *
     * @return array<string,string> outcome => Configuration key
     */
    public static function stateConfigKeys()
    {
        return array(
            'confirmed' => 'VOCIFY_STATE_CONFIRMED',
            'cancelled' => 'VOCIFY_STATE_CANCELLED',
            'completed' => 'VOCIFY_STATE_COMPLETED',
        );
    }

    /**
     * The PrestaShop Configuration key each mapping falls back to when the
     * merchant has not chosen one (or has chosen a state that no longer
     * exists). These are PrestaShop's own built-in state pointers, so they
     * survive renaming, translation and re-numbering; the closest analogues to
     * WooCommerce's `processing` / `cancelled` / `completed`.
     *
     * @return array<string,string> outcome => PrestaShop Configuration key
     */
    public static function defaultStateKeys()
    {
        return array(
            'confirmed' => 'PS_OS_PREPARATION',
            'cancelled' => 'PS_OS_CANCELED',
            'completed' => 'PS_OS_DELIVERED',
        );
    }

    /**
     * Signer instance — the same one that signs the outbound direction, so the
     * two directions can never drift apart on the message format.
     *
     * @var VocifySigner
     */
    private $signer;

    /**
     * @param VocifySigner|null $signer Injected in tests; defaults to the real one.
     */
    public function __construct($signer = null)
    {
        $this->signer = $signer ? $signer : new VocifySigner();
    }

    /**
     * Authenticate and parse an inbound push.
     *
     * Order matters and is the same in both plugins: configuration, then
     * presence, then freshness, then signature, then shape. Freshness before
     * signature means a replayed request is rejected without spending an HMAC;
     * shape after signature means an unauthenticated caller learns nothing
     * about what this endpoint expects.
     *
     * @param string      $rawBody   The RAW transmitted bytes. Never a re-encode
     *                               of the parsed body: re-encoding changes key
     *                               order and float formatting and breaks every
     *                               signature.
     * @param string      $timestamp X-Vocify-Timestamp header value.
     * @param string      $signature X-Vocify-Signature header value.
     * @param string      $secret    VOCIFY_SIGNATURE_SECRET.
     * @param int|null    $now       Unix time; injected by tests.
     * @return array `array('ok' => false, 'http' => int, 'code' => string, 'message' => string)`
     *               or `array('ok' => true, 'order_id' => string, 'outcome' => string,
     *               'call_sid' => string, 'completed_at' => int|null, 'call_data' => array)`
     */
    public function readRequest($rawBody, $timestamp, $signature, $secret, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $rawBody = (string)$rawBody;
        $timestamp = (string)$timestamp;
        $signature = (string)$signature;
        $secret = (string)$secret;

        if ($secret === '') {
            // Fail closed. An empty secret would make hash_hmac produce a
            // deterministic value any caller could compute, so "no secret"
            // must mean "no access", never "no check".
            return $this->reject(503, self::ERR_NOT_CONFIGURED, 'No signing secret configured');
        }

        if ($timestamp === '' || $signature === '') {
            return $this->reject(401, self::ERR_SIGNATURE_MISSING, 'Missing signature or timestamp');
        }

        $sentAt = strtotime($timestamp);

        if ($sentAt === false) {
            return $this->reject(401, self::ERR_TIMESTAMP_INVALID, 'Unparsable timestamp');
        }

        if (abs($now - $sentAt) > self::TIMESTAMP_TOLERANCE) {
            return $this->reject(401, self::ERR_TIMESTAMP_STALE, 'Timestamp outside the freshness window');
        }

        $expected = $this->signer->sign($timestamp, $rawBody, $secret);

        if (!hash_equals($expected, $signature)) {
            return $this->reject(401, self::ERR_SIGNATURE_MISMATCH, 'Signature mismatch');
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return $this->reject(400, self::ERR_PAYLOAD_INVALID, 'Body is not a JSON object');
        }

        $orderId = isset($payload['orderId']) && is_scalar($payload['orderId'])
            ? trim((string)$payload['orderId'])
            : '';
        $outcome = isset($payload['status']) && is_scalar($payload['status'])
            ? (string)$payload['status']
            : '';

        if ($orderId === '' || $outcome === '') {
            return $this->reject(400, self::ERR_PAYLOAD_INVALID, 'orderId and status are required');
        }

        $callData = isset($payload['callData']) && is_array($payload['callData'])
            ? $payload['callData']
            : array();

        $callSid = isset($callData['callSid']) && is_scalar($callData['callSid'])
            ? (string)$callData['callSid']
            : '';

        $completedAt = null;
        if (isset($callData['completedAt']) && is_scalar($callData['completedAt'])) {
            $parsed = strtotime((string)$callData['completedAt']);
            if ($parsed !== false) {
                $completedAt = $parsed;
            }
        }

        return array(
            'ok' => true,
            'http' => 200,
            'order_id' => $orderId,
            'outcome' => $outcome,
            'call_sid' => $callSid,
            'completed_at' => $completedAt,
            'call_data' => $callData,
        );
    }

    /**
     * Decide what to do with an authenticated, well-formed push.
     *
     * @param array    $request         A successful `readRequest()` result.
     * @param bool     $alreadyApplied  Has this exact callSid already been applied
     *                                  to this order? (`vocify_call_results` has a
     *                                  UNIQUE index on `call_sid`.)
     * @param int|null $lastCompletedAt `completedAt` of the most recent result
     *                                  already applied to this order, or null.
     * @return array `array('action' => self::ACTION_*, 'state_key' => string|null,
     *               'message' => string)`
     */
    public function decide(array $request, $alreadyApplied, $lastCompletedAt = null)
    {
        $callSid = isset($request['call_sid']) ? (string)$request['call_sid'] : '';
        $outcome = isset($request['outcome']) ? (string)$request['outcome'] : '';
        $completedAt = isset($request['completed_at']) ? $request['completed_at'] : null;

        // Idempotency. The platform retries a failed sync up to five times, and
        // a retry that lands after a success must not re-apply the state or add
        // a second order message. A push with no callSid cannot be deduped at
        // all, so it is always applied — that is the platform's problem to
        // avoid, not a reason to drop a real result on the floor.
        if ($callSid !== '' && $alreadyApplied) {
            return $this->verdict(self::ACTION_DUPLICATE, null, 'Already applied (duplicate call result)');
        }

        // Ordering. A DIFFERENT call whose result is older than the one already
        // applied must not overwrite it. An order can have several calls (a
        // retry produces a second Attempt and a second Call) and the platform
        // retries a failed sync five times, so: call A `confirmed` lands, call
        // B `cancelled` lands, then a delayed retry of A arrives. Its callSid
        // is new, so deduping alone does not catch it, and without this check
        // it would flip the merchant's cancelled order back to processing.
        //
        // Only a STRICTLY older result is refused; equal timestamps fall
        // through, so a platform that stamps two results in the same second
        // still applies the second one.
        if ($completedAt !== null && $lastCompletedAt !== null
            && (int)$lastCompletedAt > 0 && (int)$completedAt < (int)$lastCompletedAt
        ) {
            return $this->verdict(self::ACTION_SUPERSEDED, null, 'Superseded by a more recent call result');
        }

        $map = self::stateConfigKeys();
        $stateKey = isset($map[$outcome]) ? $map[$outcome] : null;

        return $this->verdict(
            self::ACTION_APPLY,
            $stateKey,
            $stateKey === null
                ? 'Recorded; outcome carries no purchase-intent meaning'
                : 'Applied'
        );
    }

    /**
     * Build the message the merchant reads on the order.
     *
     * Plain text, not HTML: PrestaShop renders `CustomerMessage` bodies as
     * text in the back office, and the values come from the platform.
     *
     * @param string $outcome  Call outcome from the platform.
     * @param array  $callData `callData` object from the payload.
     * @return string
     */
    public function buildNote($outcome, array $callData)
    {
        // strtoupper(), not Tools::strtoupper(): this class must load without
        // PrestaShop (see the class docblock), and platform outcomes are ASCII
        // slugs (`confirmed`, `no_answer`) where the two behave identically.
        $label = strtoupper(str_replace('_', ' ', (string)$outcome));
        $note = 'Vocify AI call result: ' . $label;

        if (!empty($callData['duration']) && is_scalar($callData['duration'])) {
            $note .= "\n" . 'Duration: ' . (int)$callData['duration'] . 's';
        }
        if (!empty($callData['completedAt']) && is_scalar($callData['completedAt'])) {
            $note .= "\n" . 'Completed: ' . $this->plain($callData['completedAt'], 64);
        }
        if (!empty($callData['notes']) && is_scalar($callData['notes'])) {
            $note .= "\n" . $this->plain($callData['notes'], 2000);
        }
        if (!empty($callData['callSid']) && is_scalar($callData['callSid'])) {
            $note .= "\n" . 'Call ID: ' . $this->plain($callData['callSid'], 128);
        }

        return $note;
    }

    /**
     * Flatten a platform-supplied string into something safe to store and
     * render as plain text: strip tags and control characters, collapse the
     * result to a bounded length.
     *
     * `CustomerMessage::$message` is `isCleanHtml`-validated by PrestaShop,
     * which rejects `<script`/`javascript:` but is not a sanitiser, and
     * anything that fails validation would make `add()` return false and lose
     * the note entirely. Stripping first means a note is always storable.
     *
     * @param mixed $value
     * @param int   $max
     * @return string
     */
    private function plain($value, $max)
    {
        $text = strip_tags((string)$value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        if ($text === null) {
            // preg_replace returns null on malformed UTF-8 input.
            $text = '';
        }

        // mb_substr when the extension is there, so a truncated note never
        // ends mid-character; plain substr is the correct fallback and the
        // bound is generous enough that the difference is cosmetic.
        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }

    /**
     * @param int    $http
     * @param string $code
     * @param string $message
     * @return array
     */
    private function reject($http, $code, $message)
    {
        return array(
            'ok' => false,
            'http' => (int)$http,
            'code' => $code,
            'message' => $message,
        );
    }

    /**
     * @param string      $action
     * @param string|null $stateKey
     * @param string      $message
     * @return array
     */
    private function verdict($action, $stateKey, $message)
    {
        return array(
            'action' => $action,
            'state_key' => $stateKey,
            'message' => $message,
        );
    }
}
