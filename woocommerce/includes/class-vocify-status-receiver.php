<?php
/**
 * Vocify AI Status Receiver — the RETURN half of the round trip.
 *
 * Everything else in this plugin pushes orders OUT to the platform. This is the
 * one thing that listens: after the AI call completes, the platform's cron
 * worker POSTs the outcome back here and the shop order is updated, so the
 * merchant sees on their own orders screen what the customer said on the phone.
 *
 * Before 2026-09-19 this file did not exist and no other inbound mechanism did
 * either (`grep register_rest_route` over this plugin returned nothing), so the
 * platform's POST to `/wp-json/vocify/v1/order-status` answered
 * `404 rest_no_route` and no order status ever changed. See PROGRESS.md §9.
 *
 * ## Contract
 *
 *   POST {store}/?rest_route=/vocify/v1/order-status
 *   POST {store}/wp-json/vocify/v1/order-status        (pretty permalinks only)
 *
 *   X-Vocify-Timestamp: 2026-09-19T13:45:02Z
 *   X-Vocify-Signature: hex HMAC-SHA256(secret, "{X-Vocify-Timestamp}.{rawBody}")
 *   Authorization:      Basic base64(ck:cs)   — sent, but NOT trusted (see below)
 *
 *   { "orderId": "12", "status": "confirmed",
 *     "callData": { "callSid": "…", "duration": 42,
 *                   "completedAt": "2026-09-19T13:44:20Z", "notes": "…" } }
 *
 * `secret` is the SAME "Webhook Signing Secret" the merchant already pastes
 * into this plugin's settings for the outbound direction (option
 * `vocify_signature_secret`, the platform's `api_keys.signature_secret`). One
 * shared secret per agent, used symmetrically — a second credential with its
 * own settings field would be one more thing to get wrong, and the platform has
 * no UI that populates `integrations.webhook_secret` (it is NULL on every
 * integration in production).
 *
 * The signature binds the timestamp INTO the signed message rather than letting
 * it ride alongside, matching the inbound contract fixed on 2026-09-18. Without
 * that binding the freshness window is decorative: a captured request can be
 * replayed with any timestamp.
 *
 * `Authorization: Basic ck:cs` is deliberately NOT used as the authentication
 * decision. WooCommerce's key authentication (`WC_REST_Authentication`) only
 * applies to its own `wc/*` namespaces, so consumer credentials authenticate
 * nothing on a `vocify/v1` route. The HMAC is the authentication.
 *
 * @package VocifyAI
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST callback for the return leg (platform -> shop). See the file
 * docblock above for the full contract.
 */
class Vocify_AI_Status_Receiver {

    /** REST namespace and route. */
    const NAMESPACE_V1 = 'vocify/v1';
    const ROUTE = '/order-status';

    /**
     * How far the platform's clock may be from this shop's, in seconds.
     * Two-sided, and the same 300s the platform allows in the other direction.
     */
    const TIMESTAMP_TOLERANCE = 300;

    /** Order meta key holding the last call whose result was applied. */
    const META_LAST_CALL = '_vocify_last_call_sid';

    /** Order meta key holding the last outcome applied. */
    const META_LAST_OUTCOME = '_vocify_last_call_outcome';

    /**
     * Order meta key holding `callData.completedAt` of the last applied result,
     * as a Unix timestamp.
     *
     * Needed because an order can have several calls (a retry produces a second
     * Attempt and a second Call) and the platform retries a failed sync up to
     * five times. Without an ordering check, this sequence silently rewinds the
     * merchant's order: call A `confirmed` lands, call B `cancelled` lands, then
     * a delayed retry of A arrives — its callSid is not the last one seen, so it
     * would re-apply and flip `cancelled` back to `processing`.
     */
    const META_LAST_COMPLETED_AT = '_vocify_last_call_completed_at';

    /**
     * Outcomes that change the WooCommerce order status, and what they change
     * it to.
     *
     * Deliberately short. An AI call answers exactly one question — "does the
     * customer still want this order?" — so only a yes and a no move the order.
     * `no_answer`, `failed` and friends say nothing about the customer's
     * intent, so they are recorded as an order note and the merchant decides.
     *
     * Filterable: `apply_filters('vocify_status_map', $map)`.
     */
    private function status_map() {
        return apply_filters(
            'vocify_status_map',
            array(
                'confirmed' => 'processing',
                'cancelled' => 'cancelled',
                'completed' => 'completed',
            )
        );
    }

    /**
     * Register the route. Hooked on `rest_api_init`, which fires on every REST
     * request; registering it earlier would be ignored.
     */
    public function register_routes() {
        register_rest_route(
            self::NAMESPACE_V1,
            self::ROUTE,
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_order_status'),
                // Authentication is the HMAC, checked inside the callback so a
                // failure can be logged with a reason. WordPress requires a
                // permission_callback to be present; returning true here means
                // "no WordPress capability required", not "no auth".
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle POST /vocify/v1/order-status.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_order_status($request) {
        $secret = (string)get_option('vocify_signature_secret', '');
        if ($secret === '') {
            // Fail closed. An empty secret would make hash_hmac produce a
            // deterministic value that any caller could compute.
            return $this->error('vocify_not_configured', 'No signing secret configured', 503);
        }

        $timestamp = (string)$request->get_header('x_vocify_timestamp');
        $signature = (string)$request->get_header('x_vocify_signature');
        // The RAW bytes, not a re-encode of the parsed body: re-encoding would
        // change key order and float formatting and break every signature.
        $raw_body = $request->get_body();

        if ($timestamp === '' || $signature === '') {
            return $this->error('vocify_signature_missing', 'Missing signature or timestamp', 401);
        }

        $sent_at = strtotime($timestamp);
        if ($sent_at === false) {
            return $this->error('vocify_timestamp_invalid', 'Unparsable timestamp', 401);
        }
        if (abs(time() - $sent_at) > self::TIMESTAMP_TOLERANCE) {
            return $this->error('vocify_timestamp_stale', 'Timestamp outside the freshness window', 401);
        }

        $signer = new Vocify_AI_Signer();
        $expected = $signer->sign($timestamp, $raw_body, $secret);
        if (!hash_equals($expected, $signature)) {
            return $this->error('vocify_signature_mismatch', 'Signature mismatch', 401);
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            return $this->error('vocify_payload_invalid', 'Body is not a JSON object', 400);
        }

        $external_id = isset($payload['orderId']) ? (string)$payload['orderId'] : '';
        $outcome = isset($payload['status']) ? (string)$payload['status'] : '';
        if ($external_id === '' || $outcome === '') {
            return $this->error('vocify_payload_invalid', 'orderId and status are required', 400);
        }

        $order = wc_get_order($external_id);
        if (!$order) {
            // 404, not 500: the platform should stop retrying an order this
            // shop does not have (deleted, or a different store).
            return $this->error('vocify_order_not_found', 'No such order: ' . $external_id, 404);
        }

        $call_data = isset($payload['callData']) && is_array($payload['callData'])
            ? $payload['callData']
            : array();
        $call_sid = isset($call_data['callSid']) ? (string)$call_data['callSid'] : '';

        // Idempotency. The platform retries a failed sync up to five times, and
        // a retry that lands after a success must not re-apply the status or
        // add a second note.
        if ($call_sid !== '' && (string)$order->get_meta(self::META_LAST_CALL) === $call_sid) {
            return $this->already_applied($order, 'Already applied (duplicate call result)');
        }

        // Ordering. A DIFFERENT call whose result is older than the one already
        // applied must not overwrite it — see META_LAST_COMPLETED_AT. Only a
        // strictly older result is refused; equal timestamps fall through, so a
        // platform that stamps two results in the same second still applies the
        // second one.
        $completed_at = isset($call_data['completedAt'])
            ? strtotime((string)$call_data['completedAt'])
            : false;
        $last_completed_at = (int)$order->get_meta(self::META_LAST_COMPLETED_AT);
        if ($completed_at !== false && $last_completed_at > 0 && $completed_at < $last_completed_at) {
            return $this->already_applied($order, 'Superseded by a more recent call result');
        }

        $previous_status = $order->get_status();
        $map = $this->status_map();
        $new_status = isset($map[$outcome]) ? $map[$outcome] : null;

        // WC_Order::add_order_note()'s second parameter is $is_customer_note,
        // typed int (0/1) by WooCommerce itself, not bool.
        $order->add_order_note($this->build_note($outcome, $call_data), 0);

        if ($new_status !== null && $new_status !== $previous_status) {
            // `set_status` + `save` rather than `update_status`, so the note
            // above and the status change land in one save.
            $order->set_status($new_status, '', true);
        }

        if ($call_sid !== '') {
            $order->update_meta_data(self::META_LAST_CALL, $call_sid);
        }
        if ($completed_at !== false) {
            // Cast to string: WC_Data::update_meta_data() stores meta as text
            // regardless, and it is read back with an (int) cast above, so
            // this is a no-op for behaviour and satisfies the stub's
            // array|string $value type.
            $order->update_meta_data(self::META_LAST_COMPLETED_AT, (string)$completed_at);
        }
        $order->update_meta_data(self::META_LAST_OUTCOME, $outcome);
        $order->save();

        return new WP_REST_Response(
            array(
                'success'        => true,
                'orderId'        => (string)$order->get_id(),
                'previousStatus' => $previous_status,
                'status'         => $order->get_status(),
                'changed'        => $order->get_status() !== $previous_status,
            ),
            200
        );
    }

    /**
     * A 200 that changed nothing — a duplicate or a superseded result.
     *
     * 200 and not 409: the platform should mark this call synced and stop
     * retrying. Nothing is wrong; the order is already in the state this push
     * would have produced, or in a newer one.
     *
     * @param WC_Order $order   The order.
     * @param string   $message Why nothing changed.
     * @return WP_REST_Response
     */
    private function already_applied($order, $message) {
        return new WP_REST_Response(
            array(
                'success' => true,
                'orderId' => (string)$order->get_id(),
                'status'  => $order->get_status(),
                'changed' => false,
                'message' => $message,
            ),
            200
        );
    }

    /**
     * Build the order note the merchant reads.
     *
     * @param string $outcome   Call outcome from the platform.
     * @param array  $call_data callData object from the payload.
     * @return string
     */
    private function build_note($outcome, $call_data) {
        $label = strtoupper(str_replace('_', ' ', $outcome));
        $note = sprintf(
            /* translators: %s: call outcome, e.g. CONFIRMED */
            __('Vocify AI call result: %s', 'vocify-ai'),
            $label
        );

        if (!empty($call_data['duration'])) {
            $note .= "\n" . sprintf(
                /* translators: %d: call duration in seconds */
                __('Duration: %ds', 'vocify-ai'),
                (int)$call_data['duration']
            );
        }
        if (!empty($call_data['completedAt'])) {
            $note .= "\n" . sprintf(
                /* translators: %s: ISO 8601 timestamp */
                __('Completed: %s', 'vocify-ai'),
                sanitize_text_field((string)$call_data['completedAt'])
            );
        }
        if (!empty($call_data['notes'])) {
            $note .= "\n" . sanitize_textarea_field((string)$call_data['notes']);
        }
        if (!empty($call_data['callSid'])) {
            $note .= "\n" . sprintf(
                /* translators: %s: platform call id */
                __('Call ID: %s', 'vocify-ai'),
                sanitize_text_field((string)$call_data['callSid'])
            );
        }

        return $note;
    }

    /**
     * Build an error response.
     *
     * The message is deliberately generic for every authentication failure —
     * telling a caller *why* their signature was rejected helps only them.
     *
     * @param string $code    Machine-readable code.
     * @param string $message Human-readable message.
     * @param int    $status  HTTP status.
     * @return WP_Error
     */
    private function error($code, $message, $status) {
        return new WP_Error($code, $message, array('status' => $status));
    }
}
