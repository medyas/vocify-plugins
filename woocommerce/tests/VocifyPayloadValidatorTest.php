<?php
/**
 * Payload Validator tests
 *
 * @package VocifyAI
 */

use PHPUnit\Framework\TestCase;

class VocifyPayloadValidatorTest extends TestCase
{
    /** @var Vocify_AI_Payload_Validator */
    private $validator;

    /** @var Vocify_AI_Payload_Builder */
    private $builder;

    protected function setUp(): void
    {
        $this->validator = new Vocify_AI_Payload_Validator();
        $this->builder = new Vocify_AI_Payload_Builder();
    }

    public function testValidPayloadPasses()
    {
        $fixture = vocify_test_order_fixture();
        $payload = $this->builder->build($fixture);

        $errors = $this->validator->validate($payload);

        $this->assertSame(array(), $errors, 'Expected no errors, got: ' . implode('; ', $errors));
    }

    public function testMissingCustomerPhoneFails()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['customer']['phone'] = '';
        $fixture['shipping_address']['phone'] = '';
        vocify_test_unset($fixture, 'customer.mobile_phone');
        $payload = $this->builder->build($fixture);

        $errors = $this->validator->validate($payload);

        $this->assertContains('customer.phone is required', $errors);
    }

    public function testInvalidEmailFails()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['customer']['email'] = 'not-an-email';
        $payload = $this->builder->build($fixture);

        $errors = $this->validator->validate($payload);

        $this->assertContains('customer.email must be a valid email address', $errors);
    }

    public function testBadCurrencyFails()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['currency'] = 'U$D';
        $payload = $this->builder->build($fixture);

        $errors = $this->validator->validate($payload);

        $this->assertContains('currency must be a 3-letter ISO 4217 code (e.g. USD, TND)', $errors);
    }

    public function testEmptyShippingCountryFails()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['shipping_address'] = array();
        $fixture['billing_address'] = array();
        $fixture['default_country'] = '';

        $payload = $this->builder->build($fixture);

        $errors = $this->validator->validate($payload);

        $this->assertContains('shippingAddress.country must be a 2-letter ISO 3166-1 code', $errors);
    }

    public function testInvalidFinancialStatusFails()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());
        $payload['financialStatus'] = 'weird';

        $errors = $this->validator->validate($payload);

        $this->assertStringContainsString('financialStatus must be one of', $errors[0]);
    }

    public function testMissingTotalsTotalFails()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());
        unset($payload['totals']['total']);

        $errors = $this->validator->validate($payload);

        $this->assertContains('totals.total must be a non-negative number', $errors);
    }

    public function testEmptyItemsFail()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());
        $payload['items'] = array();

        $errors = $this->validator->validate($payload);

        $this->assertContains('items must contain at least one item', $errors);
    }

    public function testZeroQuantityFails()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());
        $payload['items'][0]['quantity'] = 0;

        $errors = $this->validator->validate($payload);

        $this->assertContains('items[0].quantity must be a positive integer', $errors);
    }

    public function testIsValidConvenienceWrapper()
    {
        $valid = $this->builder->build(vocify_test_order_fixture());
        $this->assertTrue($this->validator->is_valid($valid));

        $invalid = $this->builder->build(vocify_test_order_fixture());
        $invalid['currency'] = 'usd';
        $this->assertFalse($this->validator->is_valid($invalid));
    }
}