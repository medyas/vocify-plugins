<?php
/**
 * Covers the authentication path of Vocify_AI_Status_Receiver::handle_order_status()
 * — the REST callback for the return leg (platform -> shop). PROGRESS.md O5
 * flags this class as covered only by the e2e harness; this is its PHPUnit
 * coverage: configuration -> presence -> freshness -> signature -> shape, in
 * the order the class itself documents.
 *
 * Deliberately stops at "authenticated and well-formed" (wc_get_order()
 * stubbed to return false, so the assertion is just "reached the order
 * lookup"). The order-mutation branches (idempotency, ordering, status
 * mapping) need a WC_Order double and stay e2e-covered, per PROGRESS.md O5.
 *
 * @package VocifyAI
 */

use PHPUnit\Framework\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class StatusReceiverAuthTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    const SECRET = 'a-test-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Brain\Monkey\setUp();
        Brain\Monkey\Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param string $body
     * @param string $timestamp Defaults to "now".
     * @return WP_REST_Request Signed with self::SECRET.
     */
    private function signed_request($body, $timestamp = null)
    {
        $timestamp = $timestamp !== null ? $timestamp : gmdate('c');
        $signer = new Vocify_AI_Signer();
        $signature = $signer->sign($timestamp, $body, self::SECRET);

        return new WP_REST_Request(
            array(
                'x_vocify_timestamp' => $timestamp,
                'x_vocify_signature' => $signature,
            ),
            $body
        );
    }

    public function test_rejects_when_no_signing_secret_is_configured()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn('');

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($this->signed_request('{}'));

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_not_configured', $result->code);
        $this->assertSame(503, $result->get_error_data());
    }

    public function test_rejects_missing_signature_or_timestamp()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);

        $request = new WP_REST_Request(array(), '{"orderId":"1","status":"confirmed"}');

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_signature_missing', $result->code);
        $this->assertSame(401, $result->get_error_data());
    }

    public function test_rejects_an_unparsable_timestamp()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);

        $request = new WP_REST_Request(
            array('x_vocify_timestamp' => 'not-a-date', 'x_vocify_signature' => 'deadbeef'),
            '{}'
        );

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_timestamp_invalid', $result->code);
    }

    public function test_rejects_a_stale_timestamp_outside_the_300s_window()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);

        // 310s in the past: past the ±300s tolerance with a safety margin,
        // so this cannot flake on slow CI.
        $stale = gmdate('c', time() - 310);
        $request = $this->signed_request('{"orderId":"1","status":"confirmed"}', $stale);

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_timestamp_stale', $result->code);
        $this->assertSame(401, $result->get_error_data());
    }

    public function test_accepts_a_timestamp_290s_old_inside_the_window()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);
        Brain\Monkey\Functions\when('wc_get_order')->justReturn(false);

        // 290s: inside the ±300s tolerance with the same safety margin.
        $fresh = gmdate('c', time() - 290);
        $body = '{"orderId":"999","status":"confirmed"}';
        $request = $this->signed_request($body, $fresh);

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($request);

        // Authenticated and well-formed, so it reached the order lookup —
        // wc_get_order() stubbed to false, which is the class's documented
        // "no such order" branch, not a signature/timestamp rejection.
        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_order_not_found', $result->code);
        $this->assertSame(404, $result->get_error_data());
    }

    public function test_rejects_a_tampered_body_even_with_a_correctly_shaped_signature()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);

        $timestamp = gmdate('c');
        $signer = new Vocify_AI_Signer();
        // Sign one body, transmit a different one — simulates a
        // man-in-the-middle or a corrupted transport, not just a bad secret.
        $signature = $signer->sign($timestamp, '{"orderId":"1","status":"confirmed"}', self::SECRET);
        $request = new WP_REST_Request(
            array('x_vocify_timestamp' => $timestamp, 'x_vocify_signature' => $signature),
            '{"orderId":"1","status":"cancelled"}'
        );

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_signature_mismatch', $result->code);
        $this->assertSame(401, $result->get_error_data());
    }

    public function test_rejects_a_signature_computed_with_the_wrong_secret()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);

        $timestamp = gmdate('c');
        $signer = new Vocify_AI_Signer();
        $body = '{"orderId":"1","status":"confirmed"}';
        $signature = $signer->sign($timestamp, $body, 'a-completely-different-secret');
        $request = new WP_REST_Request(
            array('x_vocify_timestamp' => $timestamp, 'x_vocify_signature' => $signature),
            $body
        );

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_signature_mismatch', $result->code);
    }

    public function test_rejects_a_body_that_is_not_a_json_object_despite_a_valid_signature()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($this->signed_request('not json at all'));

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_payload_invalid', $result->code);
        $this->assertSame(400, $result->get_error_data());
    }

    public function test_rejects_a_well_formed_json_object_missing_orderid_or_status()
    {
        Brain\Monkey\Functions\when('get_option')->justReturn(self::SECRET);

        $receiver = new Vocify_AI_Status_Receiver();
        $result = $receiver->handle_order_status($this->signed_request('{"status":"confirmed"}'));

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('vocify_payload_invalid', $result->code);
    }
}
