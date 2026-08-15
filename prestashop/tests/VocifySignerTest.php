<?php

use PHPUnit\Framework\TestCase;

class VocifySignerTest extends TestCase
{
    /** @var VocifySigner */
    private $signer;

    protected function setUp(): void
    {
        $this->signer = new VocifySigner();
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
        $rawBody = '{"orderId":"42","customer":{"phone":"+21622110033"}}';
        $secret = 'vcf_secret';

        $headers = $this->signer->buildHeaders('vcf_live_test1234567890', 'store.example.com', $rawBody, $secret);

        // The platform verifies: hash_hmac('sha256', rawBody, signatureSecret)
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $this->assertContains('X-Signature: ' . $expected, $headers);
    }

    public function testHeadersIncludeAllRequiredFields()
    {
        $headers = $this->signer->buildHeaders('vcf_live_test1234567890', 'store.example.com', '{}', 'secret');

        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertContains('X-Platform: PRESTASHOP', $headers);
        $this->assertContains('X-API-Key: vcf_live_test1234567890', $headers);
        $this->assertContains('X-Domain: store.example.com', $headers);
        $this->assertContains('X-Timestamp: ' . gmdate('c'), $headers);
        $this->assertNotEmpty($headers);
    }

    public function testTimestampIsFresh()
    {
        $headers = $this->signer->buildHeaders('k', 'd', '{}', '');
        $timestamp = null;

        foreach ($headers as $header) {
            if (strpos($header, 'X-Timestamp: ') === 0) {
                $timestamp = substr($header, strlen('X-Timestamp: '));
                break;
            }
        }

        $this->assertNotNull($timestamp);
        $this->assertLessThanOrEqual(30, abs(time() - strtotime($timestamp)));
    }

    public function testSignatureOmittedWithoutSecret()
    {
        $headers = $this->signer->buildHeaders('vcf_live_test1234567890', 'store.example.com', '{}', '');

        foreach ($headers as $header) {
            $this->assertStringNotContainsString('X-Signature', $header);
        }
    }

    public function testExtractDomainStripsSchemeAndWww()
    {
        $this->assertSame('store.example.com', $this->signer->extractDomain('https://store.example.com'));
        $this->assertSame('store.example.com', $this->signer->extractDomain('https://www.store.example.com/'));
        $this->assertSame('store.example.com', $this->signer->extractDomain('http://STORE.EXAMPLE.COM:8080/path'));
        $this->assertSame('store.example.com', $this->signer->extractDomain('store.example.com'));
        $this->assertSame('', $this->signer->extractDomain(''));
    }
}