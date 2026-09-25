<?php
/**
 * Status-receiver tests — the RETURN leg (platform → shop).
 *
 * Covers every branch of `VocifyStatusReceiver`: the whole rejection matrix
 * (unconfigured secret, missing headers, unparsable and stale timestamps,
 * wrong secret, body tampered after signing, the pre-2026-09-19 body-only
 * signature, malformed and incomplete payloads), idempotency on `callSid`,
 * the ordering guard that stops an older result from a DIFFERENT call
 * rewinding an order, and the outcome → order-state mapping.
 *
 * WooCommerce's equivalent receiver has no unit tests at all — it is asserted
 * only by the Dockerised e2e suite (`plugins/test/e2e/suites/wc-return.mjs`),
 * which was the right call for a class fused to WordPress but leaves contract
 * drift invisible without Docker. The PrestaShop decision logic lives in a
 * class that loads without PrestaShop precisely so this suite can exist; see
 * that class's docblock.
 *
 * The signature expectations here are NOT produced by the class under test.
 * `sign()` on `VocifySigner` is itself pinned by known-answer vectors in
 * `VocifySignerTest`, and the two inputs (a raw body, a timestamp) are
 * combined by `hash_hmac` directly in this file, so a bug in the receiver
 * cannot make a test agree with itself.
 */

use PHPUnit\Framework\TestCase;

class VocifyStatusReceiverTest extends TestCase
{
    const SECRET = 'whsec_return_leg_test_9f2b1c';

    /** @var VocifyStatusReceiver */
    private $receiver;

    /** Fixed "now" so freshness assertions never depend on wall-clock time. */
    private $now;

    protected function setUp(): void
    {
        $this->receiver = new VocifyStatusReceiver();
        $this->now = strtotime('2026-09-19T13:45:02+00:00');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The exact bytes the platform would transmit. Built once and signed as-is
     * — re-encoding a decoded payload changes key order and float formatting
     * and is the classic way to break a body signature.
     *
     * @param array $overrides
     * @return string
     */
    private function body(array $overrides = array())
    {
        $payload = array_merge(array(
            'orderId' => '12',
            'status' => 'confirmed',
            'callData' => array(
                'callSid' => 'call_aaa',
                'duration' => 42,
                'completedAt' => '2026-09-19T13:44:20.000Z',
                'notes' => 'Customer confirmed the order.',
            ),
        ), $overrides);

        return json_encode($payload);
    }

    /** The timestamp the platform would send, inside the freshness window. */
    private function timestamp($offsetSeconds = 0)
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $this->now + $offsetSeconds);
    }

    /**
     * Sign independently of the class under test, straight from hash_hmac.
     *
     * @param string $timestamp
     * @param string $rawBody
     * @param string $secret
     * @return string
     */
    private function sign($timestamp, $rawBody, $secret = self::SECRET)
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * Read a well-formed, correctly signed request.
     *
     * @param string|null $rawBody
     * @return array
     */
    private function readValid($rawBody = null)
    {
        $rawBody = $rawBody === null ? $this->body() : $rawBody;
        $ts = $this->timestamp();

        return $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody), self::SECRET, $this->now);
    }

    // ── fail closed ─────────────────────────────────────────────────────────

    public function testRejectsWhenNoSigningSecretIsConfigured()
    {
        $rawBody = $this->body();
        $ts = $this->timestamp();

        // Note the request is otherwise perfect, and signed with the empty
        // string — which is exactly what an attacker could compute if an
        // empty secret were accepted. It must still be refused.
        $result = $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody, ''), '', $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(503, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_NOT_CONFIGURED, $result['code']);
    }

    // ── missing / malformed authentication material ─────────────────────────

    public function testRejectsMissingSignature()
    {
        $result = $this->receiver->readRequest($this->body(), $this->timestamp(), '', self::SECRET, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_SIGNATURE_MISSING, $result['code']);
    }

    public function testRejectsMissingTimestamp()
    {
        $rawBody = $this->body();

        $result = $this->receiver->readRequest(
            $rawBody,
            '',
            $this->sign($this->timestamp(), $rawBody),
            self::SECRET,
            $this->now
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_SIGNATURE_MISSING, $result['code']);
    }

    public function testRejectsUnparsableTimestamp()
    {
        $rawBody = $this->body();

        $result = $this->receiver->readRequest(
            $rawBody,
            'not-a-date',
            $this->sign('not-a-date', $rawBody),
            self::SECRET,
            $this->now
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_TIMESTAMP_INVALID, $result['code']);
    }

    // ── freshness ───────────────────────────────────────────────────────────

    /**
     * A correctly signed replay. This is the assertion that gives the
     * freshness window teeth: the request is genuine, the signature is valid,
     * and it is refused purely on age.
     */
    public function testRejectsTimestampOlderThanTheWindow()
    {
        $rawBody = $this->body();
        $ts = $this->timestamp(-(VocifyStatusReceiver::TIMESTAMP_TOLERANCE + 1));

        $result = $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody), self::SECRET, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_TIMESTAMP_STALE, $result['code']);
    }

    /** The window is two-sided: a clock ahead of ours is just as suspect. */
    public function testRejectsTimestampFurtherAheadThanTheWindow()
    {
        $rawBody = $this->body();
        $ts = $this->timestamp(VocifyStatusReceiver::TIMESTAMP_TOLERANCE + 1);

        $result = $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody), self::SECRET, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(VocifyStatusReceiver::ERR_TIMESTAMP_STALE, $result['code']);
    }

    public function testAcceptsTimestampExactlyAtTheEdgeOfTheWindow()
    {
        $rawBody = $this->body();
        $ts = $this->timestamp(-VocifyStatusReceiver::TIMESTAMP_TOLERANCE);

        $result = $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody), self::SECRET, $this->now);

        $this->assertTrue($result['ok']);
    }

    // ── signature ───────────────────────────────────────────────────────────

    public function testRejectsSignatureMadeWithTheWrongSecret()
    {
        $rawBody = $this->body();
        $ts = $this->timestamp();

        $result = $this->receiver->readRequest(
            $rawBody,
            $ts,
            $this->sign($ts, $rawBody, 'not-the-secret'),
            self::SECRET,
            $this->now
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_SIGNATURE_MISMATCH, $result['code']);
    }

    /**
     * A body altered after signing. The signature is valid for the ORIGINAL
     * bytes; the receiver must verify against what it actually received.
     */
    public function testRejectsBodyAlteredAfterSigning()
    {
        $original = $this->body();
        $ts = $this->timestamp();
        $signature = $this->sign($ts, $original);
        $tampered = str_replace('"confirmed"', '"cancelled"', $original);

        $this->assertNotSame($original, $tampered, 'the fixture must actually differ');

        $result = $this->receiver->readRequest($tampered, $ts, $signature, self::SECRET, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(VocifyStatusReceiver::ERR_SIGNATURE_MISMATCH, $result['code']);
    }

    /**
     * The pre-2026-09-19 shape: HMAC over the body alone, timestamp riding
     * alongside and unauthenticated. Accepting it would make the freshness
     * window decorative — a captured request could be replayed forever with a
     * fresh timestamp.
     */
    public function testRejectsBodyOnlySignatureWithUnboundTimestamp()
    {
        $rawBody = $this->body();
        $ts = $this->timestamp();

        $result = $this->receiver->readRequest(
            $rawBody,
            $ts,
            hash_hmac('sha256', $rawBody, self::SECRET),
            self::SECRET,
            $this->now
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(VocifyStatusReceiver::ERR_SIGNATURE_MISMATCH, $result['code']);
    }

    /**
     * A signature that is valid for a DIFFERENT timestamp than the one sent.
     * Catches the "two separate timestamps" bug class specifically — the same
     * one `VocifySignerTest` guards on the outbound side.
     */
    public function testRejectsSignatureBoundToADifferentTimestamp()
    {
        $rawBody = $this->body();

        $result = $this->receiver->readRequest(
            $rawBody,
            $this->timestamp(),
            $this->sign($this->timestamp(-60), $rawBody),
            self::SECRET,
            $this->now
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(VocifyStatusReceiver::ERR_SIGNATURE_MISMATCH, $result['code']);
    }

    // ── payload shape ───────────────────────────────────────────────────────

    public function testRejectsBodyThatIsNotAJsonObject()
    {
        $rawBody = 'not json at all';
        $ts = $this->timestamp();

        $result = $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody), self::SECRET, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_PAYLOAD_INVALID, $result['code']);
    }

    public function testRejectsPayloadWithoutOrderId()
    {
        $rawBody = json_encode(array('status' => 'confirmed'));
        $ts = $this->timestamp();

        $result = $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody), self::SECRET, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['http']);
        $this->assertSame(VocifyStatusReceiver::ERR_PAYLOAD_INVALID, $result['code']);
    }

    public function testRejectsPayloadWithoutStatus()
    {
        $rawBody = json_encode(array('orderId' => '12'));
        $ts = $this->timestamp();

        $result = $this->receiver->readRequest($rawBody, $ts, $this->sign($ts, $rawBody), self::SECRET, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame(VocifyStatusReceiver::ERR_PAYLOAD_INVALID, $result['code']);
    }

    /**
     * Authentication is checked before shape, so an unauthenticated caller
     * learns nothing about what this endpoint expects.
     */
    public function testSignatureIsCheckedBeforePayloadShape()
    {
        $rawBody = 'not json at all';
        $ts = $this->timestamp();

        $result = $this->receiver->readRequest($rawBody, $ts, 'deadbeef', self::SECRET, $this->now);

        $this->assertSame(VocifyStatusReceiver::ERR_SIGNATURE_MISMATCH, $result['code']);
    }

    // ── happy path parsing ──────────────────────────────────────────────────

    public function testParsesAValidPush()
    {
        $result = $this->readValid();

        $this->assertTrue($result['ok']);
        $this->assertSame('12', $result['order_id']);
        $this->assertSame('confirmed', $result['outcome']);
        $this->assertSame('call_aaa', $result['call_sid']);
        $this->assertSame(strtotime('2026-09-19T13:44:20+00:00'), $result['completed_at']);
        $this->assertSame(42, $result['call_data']['duration']);
    }

    /**
     * `completedAt` arrives as an ISO 8601 string with a literal `Z` and
     * milliseconds — `new Date().toISOString()` on the platform side. PHP has
     * to parse that spelling, not just `+00:00`.
     */
    public function testParsesMillisecondZuluCompletedAt()
    {
        $result = $this->readValid($this->body(array(
            'callData' => array('callSid' => 'c1', 'completedAt' => '2026-09-19T13:44:20.123Z'),
        )));

        $this->assertSame(strtotime('2026-09-19T13:44:20+00:00'), $result['completed_at']);
    }

    public function testTolerantOfAMissingOrUnparsableCompletedAt()
    {
        $noDate = $this->readValid($this->body(array('callData' => array('callSid' => 'c1'))));
        $badDate = $this->readValid($this->body(array(
            'callData' => array('callSid' => 'c1', 'completedAt' => 'yesterday-ish??'),
        )));

        $this->assertTrue($noDate['ok']);
        $this->assertNull($noDate['completed_at']);
        $this->assertTrue($badDate['ok']);
        $this->assertNull($badDate['completed_at']);
    }

    public function testTolerantOfAMissingCallDataObject()
    {
        $result = $this->readValid(json_encode(array('orderId' => '12', 'status' => 'no_answer')));

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['call_sid']);
        $this->assertSame(array(), $result['call_data']);
    }

    // ── idempotency ─────────────────────────────────────────────────────────

    /**
     * The platform retries a failed sync up to five times. A retry landing
     * after a success must not re-apply the state or add a second note — even
     * when it carries a DIFFERENT status, which is what a mis-sequenced retry
     * looks like.
     */
    public function testARepeatOfTheSameCallSidIsANoOp()
    {
        $request = $this->readValid($this->body(array('status' => 'cancelled')));

        $verdict = $this->receiver->decide($request, true, null);

        $this->assertSame(VocifyStatusReceiver::ACTION_DUPLICATE, $verdict['action']);
        $this->assertNull($verdict['state_key']);
    }

    public function testAFirstSightingOfACallSidIsApplied()
    {
        $verdict = $this->receiver->decide($this->readValid(), false, null);

        $this->assertSame(VocifyStatusReceiver::ACTION_APPLY, $verdict['action']);
        $this->assertSame('VOCIFY_STATE_CONFIRMED', $verdict['state_key']);
    }

    /**
     * A push with no callSid cannot be deduped. It is applied rather than
     * dropped: losing a real result is worse than applying it twice, and the
     * platform always sends one.
     */
    public function testAPushWithoutACallSidIsAppliedRatherThanTreatedAsDuplicate()
    {
        $request = $this->readValid(json_encode(array('orderId' => '12', 'status' => 'confirmed')));

        $verdict = $this->receiver->decide($request, true, null);

        $this->assertSame(VocifyStatusReceiver::ACTION_APPLY, $verdict['action']);
    }

    // ── ordering ────────────────────────────────────────────────────────────

    /**
     * An older result from a DIFFERENT call must not rewind the order.
     *
     * An order can have several calls — a retry makes a second Attempt and a
     * second Call — and the platform retries a failed sync five times. So:
     * call A `confirmed` lands, call B `cancelled` lands, then a delayed retry
     * of A arrives. Its callSid is new, so deduping alone does not catch it,
     * and without this guard it would flip the merchant's cancelled order back
     * to processing. Found in the WooCommerce receiver before it shipped.
     */
    public function testAnOlderResultFromADifferentCallCannotRewindTheOrder()
    {
        $request = $this->readValid($this->body(array(
            'status' => 'confirmed',
            'callData' => array('callSid' => 'call_old', 'completedAt' => '2026-09-19T12:00:00.000Z'),
        )));

        $verdict = $this->receiver->decide($request, false, strtotime('2026-09-19T13:00:00+00:00'));

        $this->assertSame(VocifyStatusReceiver::ACTION_SUPERSEDED, $verdict['action']);
    }

    public function testANewerResultFromADifferentCallIsApplied()
    {
        $request = $this->readValid($this->body(array(
            'status' => 'cancelled',
            'callData' => array('callSid' => 'call_new', 'completedAt' => '2026-09-19T13:30:00.000Z'),
        )));

        $verdict = $this->receiver->decide($request, false, strtotime('2026-09-19T13:00:00+00:00'));

        $this->assertSame(VocifyStatusReceiver::ACTION_APPLY, $verdict['action']);
        $this->assertSame('VOCIFY_STATE_CANCELLED', $verdict['state_key']);
    }

    /**
     * Only a STRICTLY older result is refused, so a platform that stamps two
     * results in the same second still applies the second one.
     */
    public function testAResultWithTheSameCompletedAtIsStillApplied()
    {
        $at = '2026-09-19T13:30:00.000Z';
        $request = $this->readValid($this->body(array(
            'status' => 'cancelled',
            'callData' => array('callSid' => 'call_tie', 'completedAt' => $at),
        )));

        $verdict = $this->receiver->decide($request, false, strtotime($at));

        $this->assertSame(VocifyStatusReceiver::ACTION_APPLY, $verdict['action']);
    }

    public function testAResultWithNoCompletedAtIsNotTreatedAsSuperseded()
    {
        $request = $this->readValid($this->body(array(
            'status' => 'confirmed',
            'callData' => array('callSid' => 'call_undated'),
        )));

        $verdict = $this->receiver->decide($request, false, strtotime('2026-09-19T13:00:00+00:00'));

        $this->assertSame(VocifyStatusReceiver::ACTION_APPLY, $verdict['action']);
    }

    /** Dedupe wins over ordering: a duplicate is a duplicate whatever its date. */
    public function testDuplicateIsReportedBeforeSuperseded()
    {
        $request = $this->readValid($this->body(array(
            'callData' => array('callSid' => 'call_aaa', 'completedAt' => '2026-09-19T11:00:00.000Z'),
        )));

        $verdict = $this->receiver->decide($request, true, strtotime('2026-09-19T13:00:00+00:00'));

        $this->assertSame(VocifyStatusReceiver::ACTION_DUPLICATE, $verdict['action']);
    }

    // ── outcome → state mapping ─────────────────────────────────────────────

    /**
     * The three outcomes that carry purchase intent move the order; the ones
     * that do not are recorded and left alone. `no_answer` and `failed` are
     * the other two statuses the platform actually pushes — see
     * `outcomeToAdapterStatus()` in the platform's ecommerce-sync service.
     */
    public function testOnlyPurchaseIntentOutcomesMoveTheOrder()
    {
        $moves = array(
            'confirmed' => 'VOCIFY_STATE_CONFIRMED',
            'cancelled' => 'VOCIFY_STATE_CANCELLED',
            'completed' => 'VOCIFY_STATE_COMPLETED',
        );

        foreach ($moves as $outcome => $expectedKey) {
            $request = $this->readValid($this->body(array('status' => $outcome)));
            $verdict = $this->receiver->decide($request, false, null);

            $this->assertSame(VocifyStatusReceiver::ACTION_APPLY, $verdict['action'], $outcome);
            $this->assertSame($expectedKey, $verdict['state_key'], $outcome);
        }

        foreach (array('no_answer', 'failed', 'voicemail_left', 'something_new') as $outcome) {
            $request = $this->readValid($this->body(array('status' => $outcome)));
            $verdict = $this->receiver->decide($request, false, null);

            $this->assertSame(VocifyStatusReceiver::ACTION_APPLY, $verdict['action'], $outcome);
            $this->assertNull($verdict['state_key'], $outcome . ' must not move the order');
        }
    }

    /**
     * The mapping is expressed as Configuration KEYS holding numeric state
     * ids, never as status names. PrestaShop order states live in
     * `order_state_lang`: they are per-language and a merchant can rename
     * them, so a receiver that matched on "Payment accepted" would break on a
     * French shop, a renamed status, or a second language.
     */
    public function testTheMappingIsExpressedAsConfigurationKeysNotStatusNames()
    {
        foreach (VocifyStatusReceiver::stateConfigKeys() as $outcome => $configKey) {
            $this->assertRegExp('/^VOCIFY_STATE_[A-Z]+$/', $configKey, $outcome);
        }

        // And the fallbacks are PrestaShop's own state pointers, which are
        // also ids rather than names.
        foreach (VocifyStatusReceiver::defaultStateKeys() as $outcome => $psKey) {
            $this->assertRegExp('/^PS_OS_[A-Z]+$/', $psKey, $outcome);
        }

        $this->assertSame(
            array_keys(VocifyStatusReceiver::stateConfigKeys()),
            array_keys(VocifyStatusReceiver::defaultStateKeys()),
            'every mapped outcome needs a fallback'
        );
    }

    // ── the merchant-visible note ───────────────────────────────────────────

    public function testNoteNamesTheOutcomeAndTheCallDetails()
    {
        $note = $this->receiver->buildNote('confirmed', array(
            'callSid' => 'call_aaa',
            'duration' => 42,
            'completedAt' => '2026-09-19T13:44:20.000Z',
            'notes' => 'Customer confirmed.',
        ));

        $this->assertStringContainsString('Vocify AI call result: CONFIRMED', $note);
        $this->assertStringContainsString('42s', $note);
        $this->assertStringContainsString('call_aaa', $note);
        $this->assertStringContainsString('Customer confirmed.', $note);
    }

    public function testNoteLabelsUnderscoredOutcomesReadably()
    {
        $this->assertStringContainsString(
            'Vocify AI call result: NO ANSWER',
            $this->receiver->buildNote('no_answer', array())
        );
    }

    public function testNoteOmitsFieldsThePlatformDidNotSend()
    {
        $note = $this->receiver->buildNote('no_answer', array());

        $this->assertStringNotContainsString('Duration', $note);
        $this->assertStringNotContainsString('Call ID', $note);
        $this->assertSame('Vocify AI call result: NO ANSWER', $note);
    }

    /**
     * The note text is stored and rendered in the back office, and every value
     * in it comes from the platform. Tags are stripped here so the row is
     * always storable (`CustomerMessage`-style `isCleanHtml` validation would
     * otherwise reject it) and so the template's escaping is not the only
     * thing standing between a remote string and the merchant's browser.
     */
    public function testNoteStripsMarkupFromPlatformSuppliedText()
    {
        $note = $this->receiver->buildNote('confirmed', array(
            'notes' => '<script>alert(1)</script>ok',
            'callSid' => '<img src=x onerror=alert(1)>',
        ));

        $this->assertStringNotContainsString('<script', $note);
        $this->assertStringNotContainsString('<img', $note);
        $this->assertStringContainsString('ok', $note);
    }
}
