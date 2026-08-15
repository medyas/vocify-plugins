<?php

use PHPUnit\Framework\TestCase;

class VocifyPayloadBuilderTest extends TestCase
{
    /** @var VocifyPayloadBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->builder = new VocifyPayloadBuilder();
    }

    public function testBuildsValidPayloadFromFixture()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());

        $this->assertNotNull($payload);
        $this->assertSame('42', $payload['orderId']);
        $this->assertSame('XA123456', $payload['orderNumber']);
        $this->assertSame('payment_accepted', $payload['status']);
        $this->assertSame('paid', $payload['financialStatus']);
        $this->assertSame('unfulfilled', $payload['fulfillmentStatus']);
        $this->assertSame('TND', $payload['currency']);
        $this->assertCount(2, $payload['items']);
        $this->assertSame(40.49, $payload['totals']['total']);
        $this->assertSame(28.75, $payload['totals']['subtotal']);
        $this->assertTrue($payload['requiresShipping']);
        $this->assertFalse($payload['taxIncluded']);
        $this->assertFalse($payload['isGift']);
        $this->assertSame('ps_secure_key_abc', $payload['metadata']['prestashopOrderKey']);
    }

    public function testPhonePriorityChainUsesFirstValidCandidate()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());

        // First non-empty candidate is '(220) 221-1003' with default country TN.
        // Either E.164 (libphonenumber) or stripped digits must be phone-like.
        $this->assertMatchesRegularExpression('/^\+?\d+$/', $payload['customer']['phone']);
        $this->assertStringNotContainsString('(', $payload['customer']['phone']);
    }

    public function testPhonesChainSkipsEmptyEntries()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['phones'] = array('', '', '+21622001155');

        $payload = $this->builder->build($fixture);

        $this->assertSame('+21622001155', $payload['customer']['phone']);
    }

    public function testPhonesChainFallsBackToCustomerFields()
    {
        $fixture = vocify_test_order_fixture();
        unset($fixture['phones']);
        $fixture['customer']['phone'] = '+21698300011';
        $fixture['customer']['mobile_phone'] = '+21698300022';

        $payload = $this->builder->build($fixture);

        $this->assertSame('+21698300011', $payload['customer']['phone']);
    }

    public function testMobilePhoneIncludedWhenSet()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());

        $this->assertSame('+21622110033', $payload['customer']['mobilePhone']);
    }

    public function testItemImageOmittedWhenEmpty()
    {
        $payload = $this->builder->build(vocify_test_order_fixture());

        $this->assertArrayHasKey('image', $payload['items'][0]);
        $this->assertArrayNotHasKey('image', $payload['items'][1]);
    }

    public function testShippingAddressFallsBackToBilling()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['shipping_address'] = array();
        $fixture['needs_shipping'] = false;

        $payload = $this->builder->build($fixture);

        $this->assertSame('12 Rue de Carthage', $payload['shippingAddress']['address1']);
        $this->assertSame('Tunis', $payload['shippingAddress']['city']);
        $this->assertSame('TN', $payload['shippingAddress']['country']);
        $this->assertFalse($payload['requiresShipping']);
    }

    public function testCountryFallsBackToStoreDefault()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['shipping_address'] = array();
        $fixture['billing_address'] = array();
        $fixture['default_country'] = 'FR';

        $payload = $this->builder->build($fixture);

        $this->assertSame('FR', $payload['shippingAddress']['country']);
    }

    public function testOptionalDatesOmittedWhenNull()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['updated_at'] = null;
        $fixture['paid_at'] = null;

        $payload = $this->builder->build($fixture);

        $this->assertArrayNotHasKey('updatedAt', $payload);
        $this->assertArrayNotHasKey('paidAt', $payload);
        $this->assertSame('2025-01-01T00:00:00+00:00', $payload['createdAt']);
    }

    public function testInvalidEnumValuesAreDropped()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['financial_status'] = 'weird';
        $fixture['fulfillment_status'] = 'weird';

        $payload = $this->builder->build($fixture);

        $this->assertArrayNotHasKey('financialStatus', $payload);
        $this->assertArrayNotHasKey('fulfillmentStatus', $payload);
    }

    public function testReturnsNullWithoutItems()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['items'] = array();

        $this->assertNull($this->builder->build($fixture));
    }

    public function testReturnsNullWithoutCustomerEmail()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['customer']['email'] = '';

        $this->assertNull($this->builder->build($fixture));
    }

    public function testBillingAddressOmittedWhenEmpty()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['billing_address'] = array();

        $payload = $this->builder->build($fixture);

        $this->assertArrayNotHasKey('billingAddress', $payload);
        $this->assertArrayHasKey('shippingAddress', $payload);
    }
}