# Vocify AI - E-commerce CMS Plugins Specification

**Version:** 1.0.0
**Last Updated:** 2025-11-08
**Status:** Production Ready

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Supported Platforms](#supported-platforms)
4. [Authentication & Security](#authentication--security)
5. [Unified Webhook Endpoint](#unified-webhook-endpoint)
6. [Standardized Payload Format](#standardized-payload-format)
7. [Platform-Specific Implementation Guides](#platform-specific-implementation-guides)
8. [Testing & Validation](#testing--validation)
9. [Error Handling & Retry Logic](#error-handling--retry-logic)
10. [Deployment & Distribution](#deployment--distribution)

---

## Overview

### Purpose

This document specifies the requirements for developing e-commerce CMS plugins/extensions that integrate with the **Vocify AI Platform**. These plugins enable automated AI voice confirmation calls for online orders placed through supported e-commerce platforms.

### Goals

- **Single Unified Endpoint**: All plugins send standardized payloads to one webhook endpoint
- **Simplified Infrastructure**: No platform-specific transformations needed on the platform side
- **Consistent Security**: API key authentication + HMAC signature verification across all platforms
- **Plug-and-Play**: Easy installation and configuration for merchants
- **Reliable Delivery**: Automatic retries, deduplication, and error reporting

### Target Platforms

1. **Shopify** - App/Plugin for Shopify stores
2. **WooCommerce** - WordPress plugin for WooCommerce
3. **PrestaShop** - Module for PrestaShop stores
4. **Magento** - Extension for Magento 2.x stores

---

## Architecture

### High-Level Flow

```
┌─────────────────┐         ┌──────────────────┐         ┌─────────────────────┐
│                 │         │                  │         │                     │
│  E-commerce     │  Order  │  CMS Plugin      │ Webhook │  Vocify AI Platform │
│  Platform       │ ──────> │  (Shopify/WC/    │ ──────> │  /api/webhooks/     │
│  (Store)        │  Event  │   PrestaShop/    │  HTTPS  │   ecommerce         │
│                 │         │   Magento)       │         │                     │
└─────────────────┘         └──────────────────┘         └─────────────────────┘
                                     │                              │
                                     │                              │
                                     ▼                              ▼
                           ┌─────────────────┐         ┌──────────────────────┐
                           │ Transform order │         │ Create call job &    │
                           │ to unified      │         │ schedule AI voice    │
                           │ payload format  │         │ confirmation call    │
                           └─────────────────┘         └──────────────────────┘
```

### Plugin Responsibilities

Each CMS plugin must:

1. **Listen to platform events** (order creation, order status changes)
2. **Extract order data** from platform-specific format
3. **Transform to unified payload** following the standardized schema
4. **Authenticate requests** using API key and HMAC signature
5. **Send webhook** to Vocify AI platform endpoint
6. **Handle responses** and log errors appropriately
7. **Provide admin UI** for configuration (API key, domain, settings)

---

## Supported Platforms

### 1. Shopify

- **Type**: Shopify App (Public or Custom)
- **Event Hook**: `orders/create`, `orders/updated`
- **API Access**: Shopify Admin REST API / GraphQL API
- **Distribution**: Shopify App Store or manual installation
- **Language**: Node.js (recommended) or Ruby

### 2. WooCommerce

- **Type**: WordPress Plugin
- **Event Hook**: `woocommerce_new_order`, `woocommerce_order_status_changed`
- **API Access**: WooCommerce REST API + WordPress hooks
- **Distribution**: WordPress.org Plugin Directory or manual installation
- **Language**: PHP

### 3. PrestaShop

- **Type**: PrestaShop Module
- **Event Hook**: `actionValidateOrder`, `actionOrderStatusPostUpdate`
- **API Access**: PrestaShop WebService API + Module Hooks
- **Distribution**: PrestaShop Addons Marketplace or manual installation
- **Language**: PHP

### 4. Magento

- **Type**: Magento 2 Extension
- **Event Hook**: `sales_order_place_after`, `sales_order_save_after`
- **API Access**: Magento REST API + Event Observers
- **Distribution**: Magento Marketplace or Composer package
- **Language**: PHP

---

## Authentication & Security

### API Key Authentication

**Format**: `vcf_live_XXXXXXXXXXXXXXXXXXXX` (prefix: `vcf_live_`, 20+ alphanumeric characters)

**Obtaining API Key**:
1. Merchant creates an Agent in Vocify AI Dashboard
2. Navigates to Agent → Integrations → Create Integration → Select Platform
3. Platform generates and displays unique API key (shown once, must be copied)
4. Merchant configures plugin with API key in their e-commerce admin panel

**Usage**: Sent in `X-API-Key` HTTP header with every webhook request

### HMAC Signature Verification

**Purpose**: Ensures payload integrity and prevents tampering

**Algorithm**: HMAC-SHA256

**Implementation**:

```javascript
// Pseudocode (adapt to your language)
const crypto = require('crypto');

function generateSignature(payload, apiKey) {
  const rawBody = JSON.stringify(payload); // Exact payload body
  const signature = crypto
    .createHmac('sha256', apiKey)
    .update(rawBody, 'utf8')
    .digest('hex');

  return signature;
}

// Usage:
const signature = generateSignature(orderPayload, API_KEY);
// Send as X-Signature header
```

**Security Best Practices**:

- ✅ Store API keys encrypted in database
- ✅ Use HTTPS for all webhook requests
- ✅ Implement signature verification (HMAC)
- ✅ Log authentication failures for monitoring
- ❌ Never log API keys in plain text
- ❌ Never expose API keys in client-side code
- ❌ Never include API keys in URLs

---

## Unified Webhook Endpoint

### Endpoint URL

```
POST https://app.vocify-ai.com/api/webhooks/ecommerce
```

**Environment**:
- Production: `https://app.vocify-ai.com`
- Staging: `https://staging.vocify-ai.com` (for testing)

### Required HTTP Headers

| Header | Type | Required | Description | Example |
|--------|------|----------|-------------|---------|
| `X-Platform` | String | ✅ Yes | Platform identifier | `SHOPIFY`, `WOOCOMMERCE`, `PRESTASHOP`, `MAGENTO` |
| `X-API-Key` | String | ✅ Yes | Agent API key for authentication | `vcf_live_abc123...` |
| `X-Domain` | String | ✅ Yes | Store domain (for validation) | `mystore.com`, `mystore.myshopify.com` |
| `X-Signature` | String | 🟡 Recommended | HMAC-SHA256 signature of payload | `a1b2c3d4e5f6...` |
| `X-Timestamp` | String | 🟡 Optional | ISO 8601 timestamp (replay prevention) | `2025-11-08T14:30:00Z` |
| `Content-Type` | String | ✅ Yes | Must be `application/json` | `application/json` |

### Platform Values

- Shopify: `SHOPIFY`
- WooCommerce: `WOOCOMMERCE`
- PrestaShop: `PRESTASHOP`
- Magento: `MAGENTO`

**Important**: Platform value must match EXACTLY (case-sensitive)

### Example Request

```http
POST /api/webhooks/ecommerce HTTP/1.1
Host: app.vocify-ai.com
Content-Type: application/json
X-Platform: WOOCOMMERCE
X-API-Key: vcf_live_abc123xyz456def789
X-Domain: mystore.com
X-Signature: a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
X-Timestamp: 2025-11-08T14:30:00Z

{
  "orderId": "12345",
  "orderNumber": "#WC-12345",
  "status": "processing",
  ...
}
```

### Response Format

**Success Response (201 Created)**:

```json
{
  "success": true,
  "orderId": "uuid-generated-by-vocify",
  "externalId": "12345",
  "orderNumber": "#WC-12345",
  "status": "QUEUED",
  "scheduledFor": "2025-11-08T15:00:00Z",
  "jobId": "job_abc123xyz",
  "processingTime": 145
}
```

**Error Responses**:

| Status Code | Meaning | Example Response |
|-------------|---------|------------------|
| 400 | Bad Request (invalid payload) | `{"error": "Invalid payload structure", "details": {...}}` |
| 401 | Unauthorized (invalid API key) | `{"error": "Invalid API key", "code": "INVALID_API_KEY"}` |
| 403 | Forbidden (domain mismatch) | `{"error": "Domain validation failed"}` |
| 429 | Rate Limit Exceeded | `{"error": "Rate limit exceeded", "retryAfter": 60}` |
| 500 | Internal Server Error | `{"error": "Internal server error"}` |

**Idempotency**: The endpoint handles duplicate orders gracefully. If the same order is sent twice (identified by `orderId` + `platform`), the second request returns 200 OK with the existing order status:

```json
{
  "message": "Order already processed",
  "orderId": "uuid-existing-order",
  "status": "QUEUED"
}
```

### Health Check Endpoint

```http
GET /api/webhooks/ecommerce
```

**Response**:

```json
{
  "status": "healthy",
  "webhook": "ecommerce",
  "version": "1.0.0",
  "timestamp": "2025-11-08T14:30:00Z",
  "supportedPlatforms": ["SHOPIFY", "WOOCOMMERCE", "PRESTASHOP", "MAGENTO"],
  "requiredHeaders": ["X-Platform", "X-API-Key", "X-Domain"],
  "optionalHeaders": ["X-Signature", "X-Timestamp"]
}
```

---

## Standardized Payload Format

### Complete Schema (JSON)

All plugins must send order data in this exact format:

```json
{
  // ========================================
  // Order Identification
  // ========================================
  "orderId": "string (required)",
  "orderNumber": "string (required)",
  "orderKey": "string (optional)",

  // ========================================
  // Order Status
  // ========================================
  "status": "string (required)",
  "financialStatus": "pending|authorized|partially_paid|paid|partially_refunded|refunded|voided (optional)",
  "fulfillmentStatus": "unfulfilled|partial|fulfilled|restocked (optional)",

  // ========================================
  // Customer Information
  // ========================================
  "customer": {
    "id": "string (optional)",
    "firstName": "string (required)",
    "lastName": "string (required)",
    "email": "email (required)",
    "phone": "string (required)",
    "mobilePhone": "string (optional)",
    "isNewCustomer": "boolean (optional)",
    "totalOrders": "number (optional)",
    "totalSpent": "number (optional)"
  },

  // ========================================
  // Order Items
  // ========================================
  "items": [
    {
      "id": "string (required)",
      "name": "string (required)",
      "sku": "string (optional)",
      "quantity": "number (required, positive integer)",
      "price": "number (required, >= 0)",
      "total": "number (required, >= 0)",
      "image": "url (optional)",
      "attributes": {
        "size": "M",
        "color": "Blue"
      }
    }
  ],

  // ========================================
  // Order Totals
  // ========================================
  "totals": {
    "subtotal": "number (required, >= 0)",
    "discount": "number (default: 0, >= 0)",
    "shipping": "number (default: 0, >= 0)",
    "tax": "number (default: 0, >= 0)",
    "total": "number (required, >= 0)",
    "shippingTax": "number (optional, >= 0)",
    "handlingFee": "number (optional, >= 0)",
    "refunded": "number (optional, >= 0)"
  },
  "currency": "string (required, ISO 4217, 3 chars, uppercase)",

  // ========================================
  // Addresses
  // ========================================
  "shippingAddress": {
    "firstName": "string (optional)",
    "lastName": "string (optional)",
    "company": "string (optional)",
    "address1": "string (required)",
    "address2": "string (optional)",
    "city": "string (required)",
    "state": "string (optional)",
    "zip": "string (optional)",
    "country": "string (required, ISO 3166-1 alpha-2, 2 chars)",
    "phone": "string (optional)"
  },
  "billingAddress": {
    // Same structure as shippingAddress (optional)
  },

  // ========================================
  // Payment Information
  // ========================================
  "paymentMethod": "string (optional)",
  "paymentMethodTitle": "string (optional)",
  "transactionId": "string (optional)",

  // ========================================
  // Dates (ISO 8601 format)
  // ========================================
  "createdAt": "string (required, ISO 8601 datetime)",
  "updatedAt": "string (optional, ISO 8601 datetime)",
  "paidAt": "string (optional, ISO 8601 datetime)",

  // ========================================
  // Notes & Messages
  // ========================================
  "customerNote": "string (optional)",
  "merchantNote": "string (optional)",
  "giftMessage": "string (optional)",

  // ========================================
  // Additional Flags
  // ========================================
  "requiresShipping": "boolean (default: true)",
  "isGift": "boolean (default: false)",
  "taxIncluded": "boolean (default: false)",

  // ========================================
  // Platform-Specific Metadata
  // ========================================
  "metadata": {
    // Platform-specific fields
    // Examples:
    "shopifyTags": ["VIP", "Express"],
    "woocommerceMetaData": {...},
    "prestashopCarrierId": "5",
    "magentoCustomerGroup": "wholesale"
  }
}
```

### Field Descriptions

#### Order Identification

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `orderId` | string | ✅ | Platform-specific unique order ID (e.g., Shopify order ID, WooCommerce order ID) |
| `orderNumber` | string | ✅ | Human-readable order number shown to customer (e.g., "#1234", "WC-5678") |
| `orderKey` | string | 🟡 | Unique order key/token for additional verification (WooCommerce order key, etc.) |

**Important**: `orderId` is used for deduplication. Ensure it's truly unique per platform.

#### Customer Information

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `customer.firstName` | string | ✅ | Customer's first name |
| `customer.lastName` | string | ✅ | Customer's last name |
| `customer.email` | string (email) | ✅ | Customer's email address |
| `customer.phone` | string | ✅ | **Primary phone number for AI call** (E.164 format preferred) |
| `customer.mobilePhone` | string | 🟡 | Alternative mobile number (if different from primary) |

**Critical**: `customer.phone` must be a valid, dialable phone number. AI calls will fail if this field is empty or invalid.

**Phone Number Format**:
- Preferred: E.164 format (e.g., `+12025551234`)
- Acceptable: National format (e.g., `(202) 555-1234`) - platform will attempt to parse
- **Must include country code** or configure default country in integration settings

#### Order Items

Each item in the `items` array represents a product/line item:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | ✅ | Platform product/variant ID |
| `name` | string | ✅ | Product name as shown to customer |
| `quantity` | number | ✅ | Quantity ordered (positive integer) |
| `price` | number | ✅ | Unit price (per item, ≥ 0) |
| `total` | number | ✅ | Line total (price × quantity, ≥ 0) |
| `sku` | string | 🟡 | Stock Keeping Unit |
| `image` | string (URL) | 🟡 | Product image URL (for rich UI) |
| `attributes` | object | 🟡 | Product variations (size, color, etc.) |

#### Order Totals

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `totals.subtotal` | number | ✅ | Sum of all item totals (before tax/shipping) |
| `totals.discount` | number | 🟡 | Total discount amount (default: 0) |
| `totals.shipping` | number | 🟡 | Shipping cost (default: 0) |
| `totals.tax` | number | 🟡 | Total tax amount (default: 0) |
| `totals.total` | number | ✅ | **Final amount paid by customer** |
| `currency` | string | ✅ | ISO 4217 currency code (3 uppercase letters, e.g., "USD", "EUR") |

**Formula**: `total = subtotal + tax + shipping - discount`

#### Addresses

**Shipping Address** (required):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `address1` | string | ✅ | Street address line 1 |
| `city` | string | ✅ | City name |
| `country` | string | ✅ | ISO 3166-1 alpha-2 country code (2 letters, e.g., "US", "FR") |
| `address2` | string | 🟡 | Street address line 2 |
| `state` | string | 🟡 | State/province code |
| `zip` | string | 🟡 | Postal/ZIP code |
| `phone` | string | 🟡 | Address-specific phone (fallback if customer.phone is empty) |

**Billing Address** (optional): Same structure as shipping address. Omit if same as shipping.

#### Dates

All dates must use **ISO 8601 format** with timezone:

- Example: `"2025-11-08T14:30:00Z"` (UTC)
- Example: `"2025-11-08T14:30:00-05:00"` (with timezone offset)

| Field | Required | Description |
|-------|----------|-------------|
| `createdAt` | ✅ | Order creation timestamp |
| `updatedAt` | 🟡 | Last update timestamp |
| `paidAt` | 🟡 | Payment completion timestamp |

### Metadata Field

Use `metadata` for **platform-specific data** that doesn't fit the standard schema:

**Examples**:

```json
{
  "metadata": {
    // Shopify-specific
    "shopifyTags": ["VIP", "Express Shipping"],
    "shopifyOrderId": "gid://shopify/Order/12345",

    // WooCommerce-specific
    "woocommerceOrderKey": "wc_order_abc123xyz",
    "woocommerceCustomFields": {...},

    // PrestaShop-specific
    "prestashopCarrierId": "5",
    "prestashopCurrentState": "2",

    // Magento-specific
    "magentoCustomerGroup": "wholesale",
    "magentoStoreView": "en_us"
  }
}
```

---

## Platform-Specific Implementation Guides

### 1. Shopify Plugin

#### Technology Stack

- **Language**: Node.js (TypeScript recommended) or Ruby
- **Framework**: Shopify App (use Shopify CLI)
- **Webhooks**: Shopify Admin API webhooks
- **Distribution**: Shopify App Store or custom app

#### Event Hooks

```javascript
// Subscribe to these webhooks in Shopify Admin API:
const webhooks = [
  'ORDERS_CREATE',        // New order placed
  'ORDERS_UPDATED',       // Order status changed
];
```

#### Data Transformation

**Phone Number Priority**:
1. `order.customer.phone`
2. `order.phone`
3. `order.shipping_address.phone`
4. `order.billing_address.phone`

**Example Code** (Node.js):

```javascript
const axios = require('axios');
const crypto = require('crypto');

async function handleShopifyOrder(shopifyOrder, apiKey, storeDomain) {
  // Transform to unified format
  const payload = {
    orderId: shopifyOrder.id.toString(),
    orderNumber: `#${shopifyOrder.order_number}`,
    status: shopifyOrder.financial_status || 'pending',
    financialStatus: shopifyOrder.financial_status,
    fulfillmentStatus: shopifyOrder.fulfillment_status || 'unfulfilled',

    customer: {
      id: shopifyOrder.customer?.id?.toString(),
      firstName: shopifyOrder.customer?.first_name || '',
      lastName: shopifyOrder.customer?.last_name || '',
      email: shopifyOrder.customer?.email || shopifyOrder.email,
      phone: shopifyOrder.customer?.phone || shopifyOrder.phone ||
             shopifyOrder.shipping_address?.phone || '',
      totalOrders: shopifyOrder.customer?.orders_count,
      totalSpent: parseFloat(shopifyOrder.customer?.total_spent || 0),
    },

    items: shopifyOrder.line_items.map(item => ({
      id: item.id.toString(),
      name: item.name,
      sku: item.sku,
      quantity: item.quantity,
      price: parseFloat(item.price),
      total: parseFloat(item.price) * item.quantity,
      image: item.image?.src,
    })),

    totals: {
      subtotal: parseFloat(shopifyOrder.subtotal_price),
      discount: parseFloat(shopifyOrder.total_discounts || 0),
      shipping: parseFloat(shopifyOrder.total_shipping_price_set?.shop_money?.amount || 0),
      tax: parseFloat(shopifyOrder.total_tax),
      total: parseFloat(shopifyOrder.total_price),
    },
    currency: shopifyOrder.currency,

    shippingAddress: shopifyOrder.shipping_address ? {
      firstName: shopifyOrder.shipping_address.first_name,
      lastName: shopifyOrder.shipping_address.last_name,
      company: shopifyOrder.shipping_address.company,
      address1: shopifyOrder.shipping_address.address1,
      address2: shopifyOrder.shipping_address.address2,
      city: shopifyOrder.shipping_address.city,
      state: shopifyOrder.shipping_address.province_code,
      zip: shopifyOrder.shipping_address.zip,
      country: shopifyOrder.shipping_address.country_code,
      phone: shopifyOrder.shipping_address.phone,
    } : null,

    paymentMethod: shopifyOrder.payment_gateway_names?.[0],
    transactionId: shopifyOrder.transactions?.[0]?.id?.toString(),

    createdAt: shopifyOrder.created_at,
    updatedAt: shopifyOrder.updated_at,

    customerNote: shopifyOrder.note,

    requiresShipping: shopifyOrder.shipping_lines?.length > 0,
    taxIncluded: shopifyOrder.taxes_included,

    metadata: {
      shopifyOrderId: shopifyOrder.admin_graphql_api_id,
      shopifyTags: shopifyOrder.tags?.split(', ') || [],
      shopifyCheckoutToken: shopifyOrder.checkout_token,
    },
  };

  // Generate HMAC signature
  const rawBody = JSON.stringify(payload);
  const signature = crypto
    .createHmac('sha256', apiKey)
    .update(rawBody, 'utf8')
    .digest('hex');

  // Send to Vocify AI
  try {
    const response = await axios.post(
      'https://app.vocify-ai.com/api/webhooks/ecommerce',
      payload,
      {
        headers: {
          'Content-Type': 'application/json',
          'X-Platform': 'SHOPIFY',
          'X-API-Key': apiKey,
          'X-Domain': storeDomain,
          'X-Signature': signature,
          'X-Timestamp': new Date().toISOString(),
        },
      }
    );

    console.log('✅ Order sent successfully:', response.data);
    return response.data;
  } catch (error) {
    console.error('❌ Failed to send order:', error.response?.data || error.message);
    throw error;
  }
}
```

#### Admin UI Configuration

**Settings Fields**:
1. **API Key** (text input, required) - From Vocify AI dashboard
2. **Store Domain** (auto-detected, display-only) - e.g., `mystore.myshopify.com`
3. **Enable/Disable Toggle** - Turn integration on/off
4. **Test Connection** button - Sends test webhook to verify setup
5. **Status Indicator** - Shows connection status (green = active, red = error)

#### Error Handling

- Log all webhook failures in Shopify app dashboard
- Implement retry logic: 3 attempts with exponential backoff (1s, 2s, 4s)
- Display error messages in admin UI for troubleshooting

---

### 2. WooCommerce Plugin

#### Technology Stack

- **Language**: PHP 7.4+
- **Framework**: WordPress Plugin API
- **Hooks**: WooCommerce action hooks
- **Distribution**: WordPress.org Plugin Directory

#### Event Hooks

```php
<?php
// Hook into WooCommerce order events
add_action('woocommerce_new_order', 'vocify_send_order_webhook', 10, 1);
add_action('woocommerce_order_status_changed', 'vocify_send_order_status_webhook', 10, 4);
```

#### Data Transformation

**Phone Number Priority**:
1. `$order->get_billing_phone()`
2. `$order->get_shipping_phone()` (if available)
3. Customer meta phone field

**Example Code** (PHP):

```php
<?php
function vocify_send_order_webhook($order_id) {
    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    // Get plugin settings
    $api_key = get_option('vocify_api_key');
    $store_domain = parse_url(get_site_url(), PHP_URL_HOST);

    if (empty($api_key)) {
        error_log('Vocify: API key not configured');
        return;
    }

    // Transform to unified format
    $items = array();
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $items[] = array(
            'id' => (string)$item->get_product_id(),
            'name' => $item->get_name(),
            'sku' => $product ? $product->get_sku() : '',
            'quantity' => $item->get_quantity(),
            'price' => (float)$item->get_subtotal() / $item->get_quantity(),
            'total' => (float)$item->get_total(),
            'image' => $product ? wp_get_attachment_url($product->get_image_id()) : '',
            'attributes' => $item->get_meta_data(),
        );
    }

    $payload = array(
        'orderId' => (string)$order->get_id(),
        'orderNumber' => $order->get_order_number(),
        'orderKey' => $order->get_order_key(),
        'status' => $order->get_status(),
        'financialStatus' => vocify_map_wc_payment_status($order),

        'customer' => array(
            'id' => (string)$order->get_customer_id(),
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ),

        'items' => $items,

        'totals' => array(
            'subtotal' => (float)$order->get_subtotal(),
            'discount' => (float)$order->get_total_discount(),
            'shipping' => (float)$order->get_shipping_total(),
            'tax' => (float)$order->get_total_tax(),
            'total' => (float)$order->get_total(),
        ),
        'currency' => $order->get_currency(),

        'shippingAddress' => array(
            'firstName' => $order->get_shipping_first_name(),
            'lastName' => $order->get_shipping_last_name(),
            'company' => $order->get_shipping_company(),
            'address1' => $order->get_shipping_address_1(),
            'address2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'zip' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
            'phone' => $order->get_billing_phone(), // WooCommerce doesn't have separate shipping phone
        ),

        'billingAddress' => array(
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name(),
            'company' => $order->get_billing_company(),
            'address1' => $order->get_billing_address_1(),
            'address2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'zip' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'phone' => $order->get_billing_phone(),
        ),

        'paymentMethod' => $order->get_payment_method(),
        'paymentMethodTitle' => $order->get_payment_method_title(),
        'transactionId' => $order->get_transaction_id(),

        'createdAt' => $order->get_date_created()->format('c'),
        'updatedAt' => $order->get_date_modified()->format('c'),
        'paidAt' => $order->get_date_paid() ? $order->get_date_paid()->format('c') : null,

        'customerNote' => $order->get_customer_note(),

        'requiresShipping' => $order->needs_shipping_address(),
        'taxIncluded' => wc_prices_include_tax(),

        'metadata' => array(
            'woocommerceOrderKey' => $order->get_order_key(),
            'woocommerceMetaData' => $order->get_meta_data(),
        ),
    );

    // Generate HMAC signature
    $raw_body = json_encode($payload);
    $signature = hash_hmac('sha256', $raw_body, $api_key);

    // Send webhook
    $response = wp_remote_post('https://app.vocify-ai.com/api/webhooks/ecommerce', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Platform' => 'WOOCOMMERCE',
            'X-API-Key' => $api_key,
            'X-Domain' => $store_domain,
            'X-Signature' => $signature,
            'X-Timestamp' => gmdate('c'),
        ),
        'body' => $raw_body,
        'timeout' => 30,
    ));

    if (is_wp_error($response)) {
        error_log('Vocify: Failed to send webhook - ' . $response->get_error_message());
        // Store in failed webhooks table for retry
        vocify_log_failed_webhook($order_id, $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 201) {
            error_log('Vocify: Order sent successfully - Order #' . $order->get_order_number());
        } else {
            error_log('Vocify: Webhook failed with status ' . $code . ' - ' . $body);
            vocify_log_failed_webhook($order_id, $body);
        }
    }
}

function vocify_map_wc_payment_status($order) {
    switch ($order->get_status()) {
        case 'pending':
        case 'on-hold':
            return 'pending';
        case 'processing':
        case 'completed':
            return 'paid';
        case 'refunded':
            return 'refunded';
        case 'failed':
        case 'cancelled':
            return 'voided';
        default:
            return 'pending';
    }
}
```

#### Admin UI Configuration

Create WordPress admin page under **WooCommerce → Settings → Integration → Vocify AI**:

**Settings Fields**:
1. **API Key** (password field) - From Vocify AI dashboard
2. **Store Domain** (display-only) - Auto-detected from WordPress site URL
3. **Enable/Disable** (checkbox) - Toggle integration
4. **Test Connection** (button) - Sends test order
5. **Webhook Logs** (table) - Recent webhook attempts with status
6. **Debug Mode** (checkbox) - Enable verbose logging

#### Error Handling & Retry

```php
<?php
function vocify_log_failed_webhook($order_id, $error_message) {
    global $wpdb;

    $wpdb->insert(
        $wpdb->prefix . 'vocify_failed_webhooks',
        array(
            'order_id' => $order_id,
            'error_message' => $error_message,
            'retry_count' => 0,
            'created_at' => current_time('mysql'),
        )
    );
}

// Retry failed webhooks (run via WP Cron every 5 minutes)
add_action('vocify_retry_failed_webhooks', 'vocify_retry_webhooks');

function vocify_retry_webhooks() {
    global $wpdb;

    $failed = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}vocify_failed_webhooks
         WHERE retry_count < 3
         ORDER BY created_at ASC
         LIMIT 10"
    );

    foreach ($failed as $row) {
        // Retry sending webhook
        vocify_send_order_webhook($row->order_id);

        // Update retry count
        $wpdb->update(
            $wpdb->prefix . 'vocify_failed_webhooks',
            array('retry_count' => $row->retry_count + 1),
            array('id' => $row->id)
        );
    }
}
```

---

### 3. PrestaShop Module

#### Technology Stack

- **Language**: PHP 7.1+
- **Framework**: PrestaShop Module System
- **Hooks**: `actionValidateOrder`, `actionOrderStatusPostUpdate`
- **Distribution**: PrestaShop Addons Marketplace

#### Event Hooks

```php
<?php
class VocifyAI extends Module
{
    public function __construct()
    {
        // Module setup
        $this->name = 'vocifyai';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Vocify AI';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Vocify AI Order Calls');
        $this->description = $this->l('Automate order confirmation calls with AI voice technology');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function hookActionValidateOrder($params)
    {
        $this->sendOrderWebhook($params['order']);
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->sendOrderWebhook($params['newOrderStatus'], $params['id_order']);
    }
}
```

#### Data Transformation

**Phone Number Priority**:
1. Customer `phone_mobile`
2. Customer `phone`
3. Delivery address `phone_mobile`
4. Delivery address `phone`

**Example Code** (PHP):

```php
<?php
private function sendOrderWebhook($order)
{
    $api_key = Configuration::get('VOCIFY_API_KEY');
    $store_url = Tools::getShopDomainSsl(true);
    $store_domain = parse_url($store_url, PHP_URL_HOST);

    if (empty($api_key)) {
        PrestaShopLogger::addLog('Vocify: API key not configured', 3);
        return;
    }

    // Fetch full order details
    $customer = new Customer($order->id_customer);
    $address_delivery = new Address($order->id_address_delivery);
    $currency = new Currency($order->id_currency);
    $order_state = new OrderState($order->current_state, $this->context->language->id);

    // Get order products
    $products = $order->getProducts();
    $items = array();
    foreach ($products as $product) {
        $items[] = array(
            'id' => (string)$product['product_id'],
            'name' => $product['product_name'],
            'sku' => $product['product_reference'],
            'quantity' => (int)$product['product_quantity'],
            'price' => (float)$product['unit_price_tax_incl'],
            'total' => (float)$product['total_price_tax_incl'],
            'image' => $this->context->link->getImageLink(
                $product['link_rewrite'],
                $product['id_image'],
                ImageType::getFormattedName('large')
            ),
        );
    }

    // Extract phone number
    $phone = $customer->phone_mobile ?: $customer->phone;
    if (empty($phone)) {
        $phone = $address_delivery->phone_mobile ?: $address_delivery->phone;
    }

    $payload = array(
        'orderId' => (string)$order->id,
        'orderNumber' => $order->reference,
        'status' => $order_state->name,

        'customer' => array(
            'id' => (string)$customer->id,
            'firstName' => $customer->firstname,
            'lastName' => $customer->lastname,
            'email' => $customer->email,
            'phone' => $phone,
        ),

        'items' => $items,

        'totals' => array(
            'subtotal' => (float)$order->total_products,
            'discount' => (float)$order->total_discounts,
            'shipping' => (float)$order->total_shipping,
            'tax' => (float)$order->total_paid_tax_incl - $order->total_paid_tax_excl,
            'total' => (float)$order->total_paid,
        ),
        'currency' => $currency->iso_code,

        'shippingAddress' => array(
            'firstName' => $address_delivery->firstname,
            'lastName' => $address_delivery->lastname,
            'company' => $address_delivery->company,
            'address1' => $address_delivery->address1,
            'address2' => $address_delivery->address2,
            'city' => $address_delivery->city,
            'state' => State::getNameById($address_delivery->id_state),
            'zip' => $address_delivery->postcode,
            'country' => Country::getIsoById($address_delivery->id_country),
            'phone' => $address_delivery->phone_mobile ?: $address_delivery->phone,
        ),

        'paymentMethod' => $order->payment,
        'transactionId' => $order->id_carrier,

        'createdAt' => date('c', strtotime($order->date_add)),
        'updatedAt' => date('c', strtotime($order->date_upd)),

        'requiresShipping' => true,
        'taxIncluded' => Group::getPriceDisplayMethod($customer->id_default_group) == PS_TAX_INC,

        'metadata' => array(
            'prestashopOrderReference' => $order->reference,
            'prestashopCurrentState' => (string)$order->current_state,
            'prestashopCarrierId' => (string)$order->id_carrier,
        ),
    );

    // Generate HMAC signature
    $raw_body = json_encode($payload);
    $signature = hash_hmac('sha256', $raw_body, $api_key);

    // Send webhook using cURL
    $ch = curl_init('https://app.vocify-ai.com/api/webhooks/ecommerce');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw_body,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'X-Platform: PRESTASHOP',
            'X-API-Key: ' . $api_key,
            'X-Domain: ' . $store_domain,
            'X-Signature: ' . $signature,
            'X-Timestamp: ' . gmdate('c'),
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 201) {
        PrestaShopLogger::addLog('Vocify: Order sent successfully - Order #' . $order->reference, 1);
    } else {
        PrestaShopLogger::addLog('Vocify: Webhook failed with status ' . $http_code . ' - ' . $response, 3);
    }
}
```

#### Admin UI Configuration

Create configuration page in PrestaShop admin:

```php
<?php
public function getContent()
{
    $output = '';

    // Handle form submission
    if (Tools::isSubmit('submitVocifyConfig')) {
        Configuration::updateValue('VOCIFY_API_KEY', Tools::getValue('VOCIFY_API_KEY'));
        Configuration::updateValue('VOCIFY_ENABLED', Tools::getValue('VOCIFY_ENABLED'));

        $output .= $this->displayConfirmation($this->l('Settings updated'));
    }

    // Display configuration form
    $helper = new HelperForm();
    $helper->submit_action = 'submitVocifyConfig';

    $fields_form = array(
        'form' => array(
            'legend' => array(
                'title' => $this->l('Vocify AI Configuration'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('API Key'),
                    'name' => 'VOCIFY_API_KEY',
                    'required' => true,
                    'desc' => $this->l('Enter your Vocify AI API key from the dashboard'),
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Enable Integration'),
                    'name' => 'VOCIFY_ENABLED',
                    'values' => array(
                        array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')),
                        array('id' => 'active_off', 'value' => 0, 'label' => $this->l('No')),
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
            ),
        ),
    );

    $helper->fields_value['VOCIFY_API_KEY'] = Configuration::get('VOCIFY_API_KEY');
    $helper->fields_value['VOCIFY_ENABLED'] = Configuration::get('VOCIFY_ENABLED');

    return $output . $helper->generateForm(array($fields_form));
}
```

---

### 4. Magento Extension

#### Technology Stack

- **Language**: PHP 7.4+
- **Framework**: Magento 2.x Module System
- **Events**: `sales_order_place_after`, `sales_order_save_after`
- **Distribution**: Magento Marketplace or Composer

#### Event Observers

**etc/events.xml**:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="sales_order_place_after">
        <observer name="vocify_order_place" instance="Vocify\AI\Observer\OrderPlaceObserver"/>
    </event>
</config>
```

**Observer/OrderPlaceObserver.php**:

```php
<?php
namespace Vocify\AI\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Vocify\AI\Helper\WebhookHelper;

class OrderPlaceObserver implements ObserverInterface
{
    protected $webhookHelper;

    public function __construct(WebhookHelper $webhookHelper)
    {
        $this->webhookHelper = $webhookHelper;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $this->webhookHelper->sendOrderWebhook($order);
    }
}
```

#### Data Transformation

**Helper/WebhookHelper.php**:

```php
<?php
namespace Vocify\AI\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;

class WebhookHelper extends AbstractHelper
{
    protected $curl;
    protected $logger;

    public function sendOrderWebhook($order)
    {
        $apiKey = $this->scopeConfig->getValue(
            'vocify/general/api_key',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($apiKey)) {
            $this->logger->error('Vocify: API key not configured');
            return;
        }

        $storeDomain = parse_url($order->getStore()->getBaseUrl(), PHP_URL_HOST);

        // Get order items
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $items[] = [
                'id' => (string)$item->getProductId(),
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'quantity' => (int)$item->getQtyOrdered(),
                'price' => (float)$item->getPrice(),
                'total' => (float)$item->getRowTotal(),
            ];
        }

        // Get addresses
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $payload = [
            'orderId' => (string)$order->getEntityId(),
            'orderNumber' => $order->getIncrementId(),
            'status' => $order->getStatus(),
            'financialStatus' => $this->mapMagentoPaymentStatus($order),

            'customer' => [
                'id' => (string)$order->getCustomerId(),
                'firstName' => $order->getCustomerFirstname(),
                'lastName' => $order->getCustomerLastname(),
                'email' => $order->getCustomerEmail(),
                'phone' => $billingAddress->getTelephone(),
            ],

            'items' => $items,

            'totals' => [
                'subtotal' => (float)$order->getSubtotal(),
                'discount' => abs((float)$order->getDiscountAmount()),
                'shipping' => (float)$order->getShippingAmount(),
                'tax' => (float)$order->getTaxAmount(),
                'total' => (float)$order->getGrandTotal(),
            ],
            'currency' => $order->getOrderCurrencyCode(),

            'shippingAddress' => $shippingAddress ? [
                'firstName' => $shippingAddress->getFirstname(),
                'lastName' => $shippingAddress->getLastname(),
                'company' => $shippingAddress->getCompany(),
                'address1' => $shippingAddress->getStreetLine(1),
                'address2' => $shippingAddress->getStreetLine(2),
                'city' => $shippingAddress->getCity(),
                'state' => $shippingAddress->getRegionCode(),
                'zip' => $shippingAddress->getPostcode(),
                'country' => $shippingAddress->getCountryId(),
                'phone' => $shippingAddress->getTelephone(),
            ] : null,

            'billingAddress' => [
                'firstName' => $billingAddress->getFirstname(),
                'lastName' => $billingAddress->getLastname(),
                'company' => $billingAddress->getCompany(),
                'address1' => $billingAddress->getStreetLine(1),
                'address2' => $billingAddress->getStreetLine(2),
                'city' => $billingAddress->getCity(),
                'state' => $billingAddress->getRegionCode(),
                'zip' => $billingAddress->getPostcode(),
                'country' => $billingAddress->getCountryId(),
                'phone' => $billingAddress->getTelephone(),
            ],

            'paymentMethod' => $order->getPayment()->getMethod(),
            'paymentMethodTitle' => $order->getPayment()->getMethodInstance()->getTitle(),
            'transactionId' => $order->getPayment()->getLastTransId(),

            'createdAt' => date('c', strtotime($order->getCreatedAt())),
            'updatedAt' => date('c', strtotime($order->getUpdatedAt())),

            'customerNote' => $order->getCustomerNote(),

            'requiresShipping' => !$order->getIsVirtual(),
            'taxIncluded' => $this->scopeConfig->getValue(
                'tax/calculation/price_includes_tax',
                ScopeInterface::SCOPE_STORE
            ),

            'metadata' => [
                'magentoOrderId' => (string)$order->getEntityId(),
                'magentoCustomerGroup' => (string)$order->getCustomerGroupId(),
                'magentoStoreView' => $order->getStore()->getCode(),
            ],
        ];

        // Generate HMAC signature
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $apiKey);

        // Send webhook
        $this->curl->setHeaders([
            'Content-Type' => 'application/json',
            'X-Platform' => 'MAGENTO',
            'X-API-Key' => $apiKey,
            'X-Domain' => $storeDomain,
            'X-Signature' => $signature,
            'X-Timestamp' => gmdate('c'),
        ]);

        $this->curl->post(
            'https://app.vocify-ai.com/api/webhooks/ecommerce',
            $rawBody
        );

        $status = $this->curl->getStatus();

        if ($status === 201) {
            $this->logger->info('Vocify: Order sent successfully - Order #' . $order->getIncrementId());
        } else {
            $response = $this->curl->getBody();
            $this->logger->error('Vocify: Webhook failed with status ' . $status . ' - ' . $response);
        }
    }

    private function mapMagentoPaymentStatus($order)
    {
        $state = $order->getState();

        switch ($state) {
            case \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT:
                return 'pending';
            case \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW:
                return 'authorized';
            case \Magento\Sales\Model\Order::STATE_PROCESSING:
            case \Magento\Sales\Model\Order::STATE_COMPLETE:
                return 'paid';
            case \Magento\Sales\Model\Order::STATE_CLOSED:
                return $order->getTotalRefunded() > 0 ? 'refunded' : 'paid';
            case \Magento\Sales\Model\Order::STATE_CANCELED:
                return 'voided';
            default:
                return 'pending';
        }
    }
}
```

---

## Testing & Validation

### Test Checklist

Before releasing a plugin, verify the following:

#### 1. Installation & Configuration

- [ ] Plugin installs without errors
- [ ] Configuration page is accessible
- [ ] API key can be saved and retrieved
- [ ] Store domain is auto-detected correctly
- [ ] Enable/disable toggle works

#### 2. Webhook Delivery

- [ ] Order creation triggers webhook
- [ ] Webhook payload matches unified schema
- [ ] All required fields are populated
- [ ] Phone number is extracted correctly
- [ ] Currency code is 3 uppercase letters (e.g., "USD")
- [ ] Country codes are 2 letters (e.g., "US")
- [ ] Dates are in ISO 8601 format

#### 3. Authentication

- [ ] X-Platform header is correct
- [ ] X-API-Key is sent
- [ ] X-Domain matches store domain
- [ ] HMAC signature is generated correctly
- [ ] Invalid API key returns 401 error
- [ ] Domain mismatch returns 403 error

#### 4. Error Handling

- [ ] Network failures are logged
- [ ] Failed webhooks are retried (up to 3 times)
- [ ] Error messages are displayed in admin UI
- [ ] 400 errors (invalid payload) are logged with details
- [ ] 429 errors (rate limit) are retried after delay

#### 5. Edge Cases

- [ ] Orders without phone numbers are handled gracefully
- [ ] Virtual products (no shipping) work correctly
- [ ] Refunded orders are handled
- [ ] Partial refunds are reflected in totals
- [ ] Guest checkout orders (no customer ID) work
- [ ] Multi-currency orders use correct currency code

### Testing Tools

#### Webhook Testing Service

Use **webhook.site** or **ngrok** to test webhook delivery locally:

```bash
# Start ngrok tunnel
ngrok http 3000

# Use ngrok URL as test endpoint:
# https://abc123.ngrok.io/api/webhooks/ecommerce
```

#### Sample Test Order

Create a test order with these characteristics:
- Customer phone: `+12025551234`
- 2 products in cart
- Shipping address with all fields populated
- Discount code applied
- Payment completed

Verify webhook payload matches the unified schema.

#### Validation Script

Use this Node.js script to validate payloads:

```javascript
const { unifiedOrderPayloadSchema } = require('./unified-webhook.schema');

function validatePayload(payload) {
  const result = unifiedOrderPayloadSchema.safeParse(payload);

  if (result.success) {
    console.log('✅ Payload is valid');
    return true;
  } else {
    console.error('❌ Validation failed:');
    console.error(JSON.stringify(result.error.format(), null, 2));
    return false;
  }
}

// Test your payload
const testPayload = require('./test-order.json');
validatePayload(testPayload);
```

---

## Error Handling & Retry Logic

### Retry Strategy

All plugins should implement exponential backoff retry logic:

```
Attempt 1: Immediate
Attempt 2: Wait 2 seconds
Attempt 3: Wait 4 seconds
Max Attempts: 3
```

**Pseudocode**:

```javascript
async function sendWebhookWithRetry(payload, headers, maxRetries = 3) {
  let lastError;

  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      const response = await fetch(WEBHOOK_URL, {
        method: 'POST',
        headers,
        body: JSON.stringify(payload),
      });

      if (response.ok) {
        return await response.json(); // Success
      }

      // Don't retry client errors (400, 401, 403, 404)
      if (response.status >= 400 && response.status < 500) {
        throw new Error(`Client error: ${response.status}`);
      }

      lastError = new Error(`Server error: ${response.status}`);
    } catch (error) {
      lastError = error;
    }

    // Wait before retry (exponential backoff)
    if (attempt < maxRetries) {
      const delay = Math.pow(2, attempt) * 1000; // 2s, 4s
      await sleep(delay);
    }
  }

  // All retries failed
  throw new Error(`Failed after ${maxRetries} attempts: ${lastError.message}`);
}
```

### Error Codes & Actions

| Status Code | Meaning | Action |
|-------------|---------|--------|
| 200 | Order already processed | Log as duplicate, no retry |
| 201 | Success | Mark as sent, clear errors |
| 400 | Bad Request (invalid payload) | Log error, **do not retry**, notify admin |
| 401 | Unauthorized (invalid API key) | Log error, **do not retry**, notify admin to check API key |
| 403 | Forbidden (domain mismatch) | Log error, **do not retry**, notify admin |
| 429 | Rate Limit Exceeded | Retry after `retryAfter` seconds |
| 500-599 | Server Error | Retry with exponential backoff |
| Network Error | Connection failed | Retry with exponential backoff |

### Logging Best Practices

**What to Log**:
- ✅ Webhook sent successfully (order ID, response time)
- ✅ Webhook failed (error code, error message, order ID)
- ✅ Retry attempts (attempt number, delay)
- ✅ Configuration changes (API key updated, integration enabled/disabled)
- ❌ DO NOT log: Full API keys, customer PII (phone numbers, emails)

**Log Levels**:
- **INFO**: Successful webhook delivery
- **WARNING**: Retrying after failure
- **ERROR**: Webhook failed after all retries, configuration errors

**Example Log Messages**:

```
[INFO] Vocify: Order #1234 sent successfully (jobId: job_abc123, scheduledFor: 2025-11-08T15:00:00Z)
[WARNING] Vocify: Webhook failed for order #1234 (attempt 1/3), retrying in 2s - Error: 500 Internal Server Error
[ERROR] Vocify: Webhook failed for order #1234 after 3 attempts - Error: Connection timeout
[ERROR] Vocify: API key validation failed - Please check your API key in settings
```

---

## Deployment & Distribution

### Plugin Versioning

Follow **Semantic Versioning** (SemVer):

```
MAJOR.MINOR.PATCH
1.0.0
```

- **MAJOR**: Breaking changes (e.g., schema changes)
- **MINOR**: New features (e.g., additional event hooks)
- **PATCH**: Bug fixes

### Distribution Channels

#### Shopify

- **Shopify App Store**: Submit app for review
- **Custom App**: Direct installation for individual merchants
- **Installation URL**: `https://apps.shopify.com/your-app-slug`

#### WooCommerce

- **WordPress.org Plugin Directory**: Free, public listing
- **WooCommerce Marketplace**: Paid or freemium listing
- **Manual Installation**: ZIP file upload

#### PrestaShop

- **PrestaShop Addons**: Official marketplace
- **GitHub Release**: Direct download link
- **Packagist**: Composer package (optional)

#### Magento

- **Magento Marketplace**: Official extension marketplace
- **Composer Repository**: `composer require vocify/magento-integration`
- **GitHub Release**: Direct download

### Documentation Requirements

Each plugin repository must include:

1. **README.md**: Installation instructions, screenshots
2. **CHANGELOG.md**: Version history and changes
3. **LICENSE**: Open-source license (MIT recommended)
4. **User Guide**: Step-by-step configuration with screenshots
5. **API Reference**: Link to this specification document

### Support & Maintenance

- **Issue Tracking**: GitHub Issues or platform-specific support forum
- **Updates**: Security patches within 48 hours, feature updates monthly
- **Compatibility**: Support latest platform version + 2 previous major versions
- **Testing**: Automated CI/CD tests for each commit

---

## Appendix

### A. Sample Payloads

#### Complete Order Payload Example

```json
{
  "orderId": "12345",
  "orderNumber": "#WC-12345",
  "orderKey": "wc_order_abc123xyz",
  "status": "processing",
  "financialStatus": "paid",
  "fulfillmentStatus": "unfulfilled",

  "customer": {
    "id": "67890",
    "firstName": "Jane",
    "lastName": "Doe",
    "email": "jane.doe@example.com",
    "phone": "+12025551234",
    "mobilePhone": "+12025555678",
    "isNewCustomer": false,
    "totalOrders": 5,
    "totalSpent": 487.50
  },

  "items": [
    {
      "id": "101",
      "name": "Premium T-Shirt",
      "sku": "TSH-PRM-001",
      "quantity": 2,
      "price": 29.99,
      "total": 59.98,
      "image": "https://store.com/images/tshirt.jpg",
      "attributes": {
        "size": "M",
        "color": "Navy Blue"
      }
    },
    {
      "id": "202",
      "name": "Cotton Socks (3-Pack)",
      "sku": "SCK-COT-003",
      "quantity": 1,
      "price": 15.99,
      "total": 15.99
    }
  ],

  "totals": {
    "subtotal": 75.97,
    "discount": 7.60,
    "shipping": 8.50,
    "tax": 6.08,
    "total": 82.95
  },
  "currency": "USD",

  "shippingAddress": {
    "firstName": "Jane",
    "lastName": "Doe",
    "company": "Acme Corp",
    "address1": "123 Main Street",
    "address2": "Apt 4B",
    "city": "New York",
    "state": "NY",
    "zip": "10001",
    "country": "US",
    "phone": "+12025551234"
  },

  "billingAddress": {
    "firstName": "Jane",
    "lastName": "Doe",
    "address1": "123 Main Street",
    "address2": "Apt 4B",
    "city": "New York",
    "state": "NY",
    "zip": "10001",
    "country": "US",
    "phone": "+12025551234"
  },

  "paymentMethod": "stripe",
  "paymentMethodTitle": "Credit Card (Stripe)",
  "transactionId": "ch_3abc123xyz",

  "createdAt": "2025-11-08T14:30:00-05:00",
  "updatedAt": "2025-11-08T14:32:15-05:00",
  "paidAt": "2025-11-08T14:31:00-05:00",

  "customerNote": "Please leave package at back door",
  "merchantNote": "VIP customer - priority shipping",

  "requiresShipping": true,
  "isGift": false,
  "taxIncluded": false,

  "metadata": {
    "woocommerceOrderKey": "wc_order_abc123xyz",
    "source": "mobile_app",
    "marketingSource": "facebook_ads"
  }
}
```

### B. Phone Number Validation

**E.164 Format** (Recommended):

```
+[country code][subscriber number]
Examples:
  +12025551234 (US)
  +33123456789 (France)
  +442071234567 (UK)
  +21698765432 (Tunisia)
```

**Validation Libraries**:

- **JavaScript/Node.js**: `libphonenumber-js`
- **PHP**: `giggsey/libphonenumber-for-php`
- **Python**: `phonenumbers`

**Example Validation** (JavaScript):

```javascript
const { parsePhoneNumber } = require('libphonenumber-js');

function validatePhone(phoneString, defaultCountry = 'US') {
  try {
    const phoneNumber = parsePhoneNumber(phoneString, defaultCountry);

    if (phoneNumber.isValid()) {
      return phoneNumber.format('E.164'); // Returns +12025551234
    }
  } catch (error) {
    console.error('Invalid phone number:', error);
  }

  return null;
}
```

### C. Currency Code Reference

**Common ISO 4217 Currency Codes**:

| Code | Currency | Symbol |
|------|----------|--------|
| USD | US Dollar | $ |
| EUR | Euro | € |
| GBP | British Pound | £ |
| CAD | Canadian Dollar | C$ |
| AUD | Australian Dollar | A$ |
| JPY | Japanese Yen | ¥ |
| TND | Tunisian Dinar | DT |

**Full list**: https://www.iso.org/iso-4217-currency-codes.html

### D. Country Code Reference

**Common ISO 3166-1 Alpha-2 Country Codes**:

| Code | Country |
|------|---------|
| US | United States |
| CA | Canada |
| GB | United Kingdom |
| FR | France |
| DE | Germany |
| AU | Australia |
| TN | Tunisia |

**Full list**: https://www.iso.org/iso-3166-country-codes.html

### E. Contact & Support

- **Documentation**: https://docs.vocify-ai.com/cms-plugins
- **API Reference**: https://docs.vocify-ai.com/api
- **Developer Support**: developers@vocify-ai.com
- **GitHub**: https://github.com/vocify-ai
- **Slack Community**: https://vocify-ai.slack.com

---

## Revision History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2025-11-08 | Initial specification release |

---

**END OF SPECIFICATION**

*This document is maintained by the Vocify AI Platform Team. For updates or clarifications, please contact developers@vocify-ai.com.*
