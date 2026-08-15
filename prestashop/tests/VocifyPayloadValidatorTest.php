<?php

use PHPUnit\Framework\TestCase;

class VocifyPayloadValidatorTest extends TestCase
{
    /** @var VocifyPayloadValidator */
    private $validator;

    /** @var VocifyPayloadBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->validator = new VocifyPayloadValidator();
        $this->builder = new VocifyPayloadBuilder();
    }

    public function testValidPayloadPasses()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());

        $errors = $this->validator->validate($payload);

        $this->assertSame(array(), $errors, 'Expected no errors, got: ' . implode('; ', $errors));
    }

    public function testMissingCustomerPhoneFails()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['phones'] = array();
        $fixture['customer']['phone'] = '';
        $fixture['customer']['mobile_phone'] = '';
        $fixture['shipping_address']['phone'] = '';
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
        $fixture['currency'] = 'usdX';
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

    public function testUnknownAddressFieldFails()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());
        $payload['shippingAddress']['county'] = 'Springfield County';

        $errors = $this->validator->validate($payload);

        $this->assertContains('shippingAddress contains unknown field: county', $errors);
    }

    public function testIsValidConvenienceWrapper()
    {
        $valid = $this->builder->build(vocify_test_order_fixture());
        $this->assertTrue($this->validator->isValid($valid));

        $invalid = $this->builder->build(vocify_test_order_fixture());
        $invalid['currency'] = 'tn';
        $this->assertFalse($this->validator->isValid($invalid));
    }
}