<?php
/**
 * Signer tests
 *
 * Pins the 2026-09-18 platform contract change: the HMAC is computed over
 * `"{timestamp}.{rawBody}"` (timestamp bound into the signed message), not
 * the body alone, and the header X-Timestamp actually sent MUST be the exact
 * same value that was folded into the signature.
 *
 * The known-answer vectors below (testKnownAnswerVector, its body-only and
 * stale-timestamp companions) were computed OUTSIDE this class — via
 * `node -e` using the built-in `crypto` module and independently cross-checked
 * with `php -r 'hash_hmac(...)'` in a bare php:8.2-cli container — and are
 * asserted here as literal strings. See plugin-hmac-fix-report.md for both
 * computations side by side. This is what makes them known-ANSWER tests: a
 * bug in `sign()`/`buildSignedMessage()` cannot make the test "prove itself
 * right" by also breaking the expectation, because the expectation was never
 * derived from this class in the first place.
 */

use PHPUnit\Framework\TestCase;

class VocifySignerTest extends TestCase
{
    /** @var VocifySigner */
    private $signer;

    /**
     * Fixed vector shared by the known-answer tests below.
     */
    const KAT_SECRET = 'ka_test_secret_9f2b1c';
    const KAT_TIMESTAMP = '2026-01-15T10:00:00+00:00';
    const KAT_STALE_TIMESTAMP = '2026-01-15T09:00:00+00:00';
    const KAT_RAW_BODY = '{"orderId":"ORD-777","customer":{"phone":"+21655667788"}}';

    /** HMAC-SHA256("{$KAT_TIMESTAMP}.{$KAT_RAW_BODY}", KAT_SECRET), hex. */
    const KAT_EXPECTED_DIGEST = '9b1f531fa76663fec288f9f82b71a1c882c16b36fbb552a9586b61a05f0c613c';

    /** HMAC-SHA256("{$KAT_STALE_TIMESTAMP}.{$KAT_RAW_BODY}", KAT_SECRET), hex. */
    const KAT_STALE_DIGEST = 'b56679852cebc5aafb72ef7f16a4f86147557fa0cdbe78d6c594e2c0d3f027fe';

    /** HMAC-SHA256(KAT_RAW_BODY, KAT_SECRET), hex — the OLD, body-only contract. */
    const KAT_BODY_ONLY_DIGEST = '8373035ef945ed449b200a97dfd8e96cfc362c94be2e570b8097e65a73bb2b56';

    protected function setUp(): void
    {
        $this->signer = new VocifySigner();
    }

    public function testSignProducesHexHmac()
    {
        $signature = $this->signer->sign('2026-01-01T00:00:00+00:00', '{"orderId":"1"}', 'secret123');

        $this->assertRegExp('/^[0-9a-f]{64}$/', $signature);
        $this->assertSame($signature, $this->signer->sign('2026-01-01T00:00:00+00:00', '{"orderId":"1"}', 'secret123'));
    }

    public function testSignIsSensitiveToTimestampBodyAndSecret()
    {
        $ts = '2026-01-01T00:00:00+00:00';
        $a = $this->signer->sign($ts, '{"orderId":"1"}', 'secret123');
        $b = $this->signer->sign($ts, '{"orderId":"2"}', 'secret123');
        $c = $this->signer->sign($ts, '{"orderId":"1"}', 'other-secret');
        $d = $this->signer->sign('2026-01-01T00:00:01+00:00', '{"orderId":"1"}', 'secret123');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($a, $d, 'changing the timestamp alone must change the signature');
    }

    public function testBuildSignedMessageFormat()
    {
        // Pins the exact separator/ordering the platform expects:
        // `${X-Timestamp}.${rawBody}` — see platform src/lib/auth/api-key.ts
        // buildSignedMessage().
        $this->assertSame(
            '2026-01-01T00:00:00+00:00.{"a":1}',
            $this->signer->buildSignedMessage('2026-01-01T00:00:00+00:00', '{"a":1}')
        );
    }

    /**
     * Known-answer test: fixed secret/timestamp/body -> fixed digest, computed
     * independently of this codebase (see class doc comment).
     */
    public function testKnownAnswerVector()
    {
        $actual = $this->signer->sign(self::KAT_TIMESTAMP, self::KAT_RAW_BODY, self::KAT_SECRET);

        $this->assertSame(self::KAT_EXPECTED_DIGEST, $actual);
    }

    /**
     * Negative KAT: signing over a stale/altered timestamp (same body, same
     * secret) must produce a different, specific digest — not the one for the
     * original timestamp. This is exactly the replay case the platform's
     * freshness window exists to catch: a captured request cannot have its
     * X-Timestamp rewritten without invalidating the signature.
     */
    public function testSignatureNoLongerMatchesWhenTimestampIsAltered()
    {
        $altered = $this->signer->sign(self::KAT_STALE_TIMESTAMP, self::KAT_RAW_BODY, self::KAT_SECRET);

        $this->assertSame(self::KAT_STALE_DIGEST, $altered);
        $this->assertNotSame(self::KAT_EXPECTED_DIGEST, $altered);
    }

    /**
     * Negative KAT: the OLD (pre-2026-09-18) body-only contract must no
     * longer be what this signer produces. Guards against a regression back
     * to the exact bug this fix closes.
     */
    public function testSignatureNoLongerMatchesBodyOnlyHmac()
    {
        $actual = $this->signer->sign(self::KAT_TIMESTAMP, self::KAT_RAW_BODY, self::KAT_SECRET);

        $this->assertNotSame(self::KAT_BODY_ONLY_DIGEST, $actual);
    }

    public function testHeadersIncludeAllRequiredFields()
    {
        $headers = $this->signer->buildHeaders('vcf_live_test1234567890', 'store.example.com', '{}', 'secret');

        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertContains('X-Platform: PRESTASHOP', $headers);
        $this->assertContains('X-API-Key: vcf_live_test1234567890', $headers);
        $this->assertContains('X-Domain: store.example.com', $headers);

        $timestampHeader = self::findHeader($headers, 'X-Timestamp: ');
        $this->assertNotNull($timestampHeader);
        $this->assertNotNull(self::findHeader($headers, 'X-Signature: '));
    }

    /**
     * The bug class this fix targets: a plugin could send an X-Timestamp
     * header that differs from the one folded into X-Signature (e.g. two
     * separate `gmdate('c')` calls a moment apart). Reconstructing the
     * signature from the header's own timestamp must match the header's own
     * signature — using hash_hmac directly (not $this->signer->sign()) so
     * this test doesn't just call the code under test on itself.
     */
    public function testHeaderTimestampIsTheSameOneFoldedIntoTheSignature()
    {
        $rawBody = '{"orderId":"42","customer":{"phone":"+21622110033"}}';
        $secret = 'vcf_secret';

        $headers = $this->signer->buildHeaders('vcf_live_test1234567890', 'store.example.com', $rawBody, $secret);

        $timestampHeader = self::findHeader($headers, 'X-Timestamp: ');
        $signatureHeader = self::findHeader($headers, 'X-Signature: ');
        $this->assertNotNull($timestampHeader);
        $this->assertNotNull($signatureHeader);

        $timestamp = substr($timestampHeader, strlen('X-Timestamp: '));
        $signature = substr($signatureHeader, strlen('X-Signature: '));

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        $this->assertSame($expected, $signature);
    }

    public function testTimestampIsFresh()
    {
        $headers = $this->signer->buildHeaders('k', 'd', '{}', '');
        $timestampHeader = self::findHeader($headers, 'X-Timestamp: ');

        $this->assertNotNull($timestampHeader);
        $timestamp = substr($timestampHeader, strlen('X-Timestamp: '));

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

    /**
     * buildHeaders() returns a flat "Header: value" array (curl-style), not
     * an associative one — find the entry with the given prefix.
     *
     * @param array  $headers
     * @param string $prefix
     * @return string|null
     */
    private static function findHeader($headers, $prefix)
    {
        foreach ($headers as $header) {
            if (strpos($header, $prefix) === 0) {
                return $header;
            }
        }

        return null;
    }
}
