<?php
/**
 * Signer tests
 *
 * @package VocifyAI
 */

use PHPUnit\Framework\TestCase;

class VocifySignerTest extends TestCase
{
    /** @var Vocify_AI_Signer */
    private $signer;

    protected function setUp(): void
    {
        $this->signer = new Vocify_AI_Signer();
    }

    public function testSignProducesHexHmac()
    {
        $signature = $this->signer->sign('{"orderId":"1"}', 'secret123');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
        $this->assertSame($signature, $this->signer->sign('{"orderId":"1"}', 'secret123'));
    }

    public function testSignIsSensitiveToBodyAndSecret()
    {
        $a = $this->signer->sign('{"orderId":"1"}', 'secret123');
        $b = $this->signer->sign('{"orderId":"2"}', 'secret123');
        $c = $this->signer->sign('{"orderId":"1"}', 'other-secret');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function testSignatureMatchesPlatformVerification()
    {
        $rawBody = '{"orderId":"123","customer":{"phone":"+12025551234"}}';
        $secret = 'vcf_secret';

        $headers = $this->signer->build_headers('vcf_live_test1234567890', 'mystore.com', $rawBody, $secret);

        // The platform verifies: hash_hmac('sha256', rawBody, signatureSecret)
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $this->assertSame($expected, $headers['X-Signature']);
    }

    public function testHeadersIncludeAllRequiredFields()
    {
        $headers = $this->signer->build_headers('vcf_live_test1234567890', 'mystore.com', '{}', 'secret');

        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('WOOCOMMERCE', $headers['X-Platform']);
        $this->assertSame('vcf_live_test1234567890', $headers['X-API-Key']);
        $this->assertSame('mystore.com', $headers['X-Domain']);
        $this->assertArrayHasKey('X-Timestamp', $headers);
        $this->assertArrayHasKey('X-Signature', $headers);
    }

    public function testSignatureOmittedWithoutSecret()
    {
        $headers = $this->signer->build_headers('vcf_live_test1234567890', 'mystore.com', '{}', '');

        $this->assertArrayNotHasKey('X-Signature', $headers);
    }

    public function testExtractDomainStripsSchemeAndWww()
    {
        $this->assertSame('mystore.com', $this->signer->extract_domain('https://mystore.com'));
        $this->assertSame('mystore.com', $this->signer->extract_domain('https://www.mystore.com/'));
        $this->assertSame('mystore.com', $this->signer->extract_domain('http://MYSTORE.com:8080/path'));
        $this->assertSame('mystore.com', $this->signer->extract_domain('mystore.com'));
        $this->assertSame('', $this->signer->extract_domain(''));
    }
}