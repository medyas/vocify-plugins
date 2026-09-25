<?php
/**
 * Covers Vocify_AI_Webhook_Service::send_webhook() — the HMAC/payload path
 * that PROGRESS.md O5 flagged as untested by PHPUnit (only the e2e harness,
 * against a deployed platform, exercised it). This is the method that
 * actually signs and transmits an order: `send_order()` builds the payload
 * from a live WC_Order and delegates here, so testing at this boundary
 * covers the security-critical half (exact signed bytes, exact headers)
 * without needing a WC_Order double.
 *
 * Brain Monkey stubs the WordPress functions this method calls
 * (get_option, get_site_url, wp_remote_post, is_wp_error,
 * wp_remote_retrieve_response_code, wp_remote_retrieve_body) so this runs
 * with no WP bootstrap, matching this plugin's tests/bootstrap.php.
 *
 * @package VocifyAI
 */

use PHPUnit\Framework\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class WebhookServiceSendTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Brain\Monkey\setUp();
        // is_allowed_webhook_url() calls the real wp_parse_url(); alias it to
        // PHP's native parse_url() (identical behaviour for the well-formed
        // absolute https:// URLs these tests use).
        Brain\Monkey\Functions\when('wp_parse_url')->alias('parse_url');
    }

    protected function tearDown(): void
    {
        Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array The unified payload send_webhook() expects, built via
     *               the real (pure, WP-free) payload builder from the same
     *               fixture the payload-builder tests use.
     */
    private function fixture_payload()
    {
        $builder = new Vocify_AI_Payload_Builder();
        $payload = $builder->build(vocify_test_order_fixture());
        $this->assertNotNull($payload, 'fixture must build a valid payload');
        return $payload;
    }

    public function test_send_webhook_signs_the_exact_transmitted_bytes()
    {
        $secret = 'a-test-signing-secret';
        $api_key = 'vcf_live_1234567890abcdef';
        $webhook_url = 'https://app.vocify-ai.com/api/webhooks/ecommerce';
        $payload = $this->fixture_payload();

        Brain\Monkey\Functions\when('get_site_url')->justReturn('https://my-shop.example.com');
        Brain\Monkey\Functions\when('get_option')->justReturn($secret);
        Brain\Monkey\Functions\when('is_wp_error')->justReturn(false);
        Brain\Monkey\Functions\when('wp_remote_retrieve_response_code')->justReturn(201);
        Brain\Monkey\Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(array('success' => true)));

        $captured = null;

        Brain\Monkey\Functions\expect('wp_remote_post')
            ->once()
            ->with($webhook_url, \Mockery::type('array'))
            ->andReturnUsing(function ($url, $args) use (&$captured) {
                $captured = $args;
                return array('response' => array('code' => 201));
            });

        $service = new Vocify_AI_Webhook_Service();
        $result = $service->send_webhook($payload, $api_key, $webhook_url);

        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['http_code']);

        $this->assertNotNull($captured, 'wp_remote_post must have been called');
        $headers = $captured['headers'];

        // The body actually transmitted MUST be exactly what json_encode()
        // produces from $payload — the platform verifies the signature
        // against these exact bytes, not a re-encode.
        $expected_body = json_encode($payload);
        $this->assertSame($expected_body, $captured['body']);

        $this->assertSame('WOOCOMMERCE', $headers['X-Platform']);
        $this->assertSame($api_key, $headers['X-API-Key']);
        $this->assertSame('my-shop.example.com', $headers['X-Domain']);
        $this->assertArrayHasKey('X-Timestamp', $headers);
        $this->assertArrayHasKey('X-Signature', $headers);

        // Recompute the signature independently (not by calling the signer
        // class) to prove the contract end to end: hex HMAC-SHA256 of
        // "{X-Timestamp}.{rawBody}" under the configured secret.
        $expected_signature = hash_hmac(
            'sha256',
            $headers['X-Timestamp'] . '.' . $captured['body'],
            $secret
        );
        $this->assertSame($expected_signature, $headers['X-Signature']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $headers['X-Signature']);
    }

    public function test_send_webhook_omits_signature_header_when_no_secret_is_configured()
    {
        $payload = $this->fixture_payload();
        $webhook_url = 'https://app.vocify-ai.com/api/webhooks/ecommerce';

        Brain\Monkey\Functions\when('get_site_url')->justReturn('https://my-shop.example.com');
        // No signing secret configured locally (O7: legacy fallback path).
        Brain\Monkey\Functions\when('get_option')->justReturn('');
        Brain\Monkey\Functions\when('is_wp_error')->justReturn(false);
        Brain\Monkey\Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        Brain\Monkey\Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(array(
            'error' => 'SIGNATURE_REQUIRED',
        )));

        $captured = null;

        Brain\Monkey\Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function ($url, $args) use (&$captured) {
                $captured = $args;
                return array('response' => array('code' => 401));
            });

        $service = new Vocify_AI_Webhook_Service();
        $result = $service->send_webhook($payload, 'vcf_live_1234567890abcdef', $webhook_url);

        $this->assertArrayNotHasKey('X-Signature', $captured['headers']);
        // The platform fails closed on a secretless key — see claude.md
        // "The signing secret is mandatory in practice" — send_webhook()
        // itself does not decide that; it only must not fabricate a header.
        $this->assertFalse($result['success']);
        $this->assertSame(401, $result['http_code']);
    }

    public function test_send_webhook_rejects_a_non_https_url_before_sending_anything()
    {
        $payload = $this->fixture_payload();

        Brain\Monkey\Functions\expect('wp_remote_post')->never();

        $service = new Vocify_AI_Webhook_Service();
        $result = $service->send_webhook($payload, 'vcf_live_1234567890abcdef', 'http://app.vocify-ai.com/webhook');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['http_code']);
        $this->assertStringContainsString('https://', $result['error']);
    }
}
