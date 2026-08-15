<?php
/**
 * Payload Builder tests
 *
 * @package VocifyAI
 */

use PHPUnit\Framework\TestCase;

class VocifyPayloadBuilderTest extends TestCase
{
    /** @var Vocify_AI_Payload_Builder */
    private $builder;

    protected function setUp(): void
    {
        $this->builder = new Vocify_AI_Payload_Builder();
    }

    public function testBuildsValidPayloadFromFixture()
    {
        $fixture = vocify_test_order_fixture();
        $payload = $this->builder->build($fixture);

        $this->assertNotNull($payload);
        $this->assertSame('12345', $payload['orderId']);
        $this->assertSame('12345', $payload['orderNumber']);
        $this->assertSame('processing', $payload['status']);
        $this->assertSame('paid', $payload['financialStatus']);
        $this->assertSame('unfulfilled', $payload['fulfillmentStatus']);
        $this->assertSame('USD', $payload['currency']);
        $this->assertSame('+12025551234', $payload['customer']['phone']);
        $this->assertCount(2, $payload['items']);
        $this->assertSame(52.46, $payload['totals']['total']);
        $this->assertSame(49.97, $payload['totals']['subtotal']);
        $this->assertTrue($payload['requiresShipping']);
        $this->assertFalse($payload['taxIncluded']);

        // Metadata must be preserved
        $this->assertSame('wc_order_abc123', $payload['metadata']['woocommerceOrderKey']);
    }

    public function testItemTypesAndImageOmission()
    {
        $fixture = vocify_test_order_fixture();
        $payload = $this->builder->build($fixture);

        // Item ids are always strings (platform schema requires strings)
        $this->assertSame('101', $payload['items'][0]['id']);
        $this->assertSame('102', $payload['items'][1]['id']);

        // Empty image omitted entirely
        $this->assertArrayNotHasKey('image', $payload['items'][1]);
        $this->assertArrayHasKey('image', $payload['items'][0]);

        // Empty attributes dropped
        $this->assertArrayNotHasKey('attributes', $payload['items'][1]);

        $this->assertSame(2, $payload['items'][0]['quantity']);
        $this->assertSame(19.99, $payload['items'][0]['price']);
        $this->assertSame(39.98, $payload['items'][0]['total']);
    }

    public function testFalseImageIsOmitted()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['items'][0]['image'] = false;
        $payload = $this->builder->build($fixture);

        $this->assertArrayNotHasKey('image', $payload['items'][0]);
    }

    public function testShippingAddressFallsBackToBilling()
    {
        $fixture = vocify_test_order_fixture();
        // Virtual order: no shipping address at all
        $fixture['shipping_address'] = array();
        $fixture['needs_shipping'] = false;

        $payload = $this->builder->build($fixture);

        $this->assertSame('123 Main St', $payload['shippingAddress']['address1']);
        $this->assertSame('Springfield', $payload['shippingAddress']['city']);
        $this->assertSame('US', $payload['shippingAddress']['country']);
        $this->assertFalse($payload['requiresShipping']);
    }

    public function testCountryFallsBackToStoreDefault()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['shipping_address'] = array();
        $fixture['billing_address'] = array();
        $fixture['default_country'] = 'TN';

        $payload = $this->builder->build($fixture);

        $this->assertSame('TN', $payload['shippingAddress']['country']);
    }

    public function testOptionalDatesOmittedWhenNull()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['updated_at'] = null;
        $fixture['paid_at'] = null;

        $payload = $this->builder->build($fixture);

        $this->assertArrayNotHasKey('updatedAt', $payload);
        $this->assertArrayNotHasKey('paidAt', $payload);
        $this->assertArrayHasKey('createdAt', $payload);
        $this->assertSame('2025-01-01T00:00:00+00:00', $payload['createdAt']);
    }

    public function testInvalidEnumValuesAreDropped()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['financial_status'] = 'mystery_status';
        $fixture['fulfillment_status'] = 'mystery_status';

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

    public function testPhoneFallbackStripsSeparators()
    {
        $fixture = vocify_test_order_fixture();
        $fixture['customer']['phone'] = '202.555.1234';
        vocify_test_unset($fixture, 'shipping_address.phone');
        vocify_test_unset($fixture, 'customer.mobile_phone');

        $payload = $this->builder->build($fixture);
        $phone = $payload['customer']['phone'];

        // libphonenumber may be present (E.164) or not (stripped digits) —
        // both must be phone-like and never contain separators.
        $this->assertMatchesRegularExpression('/^\+?\d+$/', $phone);
        $this->assertStringNotContainsString('.', $phone);
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