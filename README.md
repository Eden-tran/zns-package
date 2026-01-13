# ZNS Package - Zalo Notification Service Integration

A focused Laravel package for handling Zalo Official Account (OA) OAuth webhooks and token management.

## Overview

This package handles:
- Zalo OA webhook for receiving authorization codes
- Automatic token exchange and storage
- Daily token refresh to maintain service continuity
- ZNS message sending capability

## Installation

1. Add package to composer.json:
```json
"repositories": [
    {
        "type": "path",
        "url": "./packages/cpp/zns-package"
    }
],
"require": {
    "cpp/zns-package": "dev-main"
}
```

2. Install package:
```bash
composer update cpp/zns-package
```

3. Publish configuration:
```bash
php artisan vendor:publish --tag=zns-config
```

4. Run migrations:
```bash
php artisan migrate
```

5. Add to `.env`:
```env
ZALO_APP_ID=your_app_id
ZALO_APP_SECRET=your_app_secret
```

## Token Management System

### Token Lifecycle

```
Timeline Example:
─────────────────────────────────────────────────────────────────
Day 1, 10:00 - Initial token received (expires Day 2, 11:00)
Day 2, 03:00 - Auto-refresh triggered (new expiry Day 3, 04:00)
Day 3, 03:00 - Auto-refresh triggered (new expiry Day 4, 04:00)
─────────────────────────────────────────────────────────────────

Token Validity:
- Access Token: 25 hours (90,000 seconds)
- Refresh Token: 90 days
- Refresh Schedule: Daily at 3:00 AM
- Safety Buffer: 3 hours before expiry
```

### Automatic Refresh Logic

The package includes a scheduled command that runs every 24 hours:

1. **Daily Schedule** (3:00 AM)
   - Checks all tokens expiring within 3 hours
   - Refreshes tokens one by one with 0.5s delay (rate limiting)
   - Logs success/failure for each token
   - Sends critical alert if any failures occur

2. **Why 3-hour buffer?**
   - Tokens valid for 25 hours
   - Job runs every 24 hours
   - 3-hour buffer ensures tokens never expire
   - Provides time to handle failures manually

### Laravel Schedule Configuration

Already configured in `app/Console/Kernel.php`:
```php
$schedule->command('zalo:refresh-tokens')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Failed to refresh Zalo tokens via scheduled task');
    });
```

Add this cron entry to your server:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## ZNS Message API

### Request Structure

The ZNS API expects the following payload structure:

```json
{
    "phone": "84912345678",
    "template_id": "abc123",
    "template_data": {
        "key1": "value1",
        "key2": "value2"
    },
    "tracking_id": "unique_tracking_id",
    "mode": "development"  // Optional: "development" or "production"
}
```

### Phone Number Format
- Accepts both formats: `0912345678` or `84912345678`
- Automatically normalizes to `84xxx` format
- Must be valid Vietnam phone number

### Template Data
- Must match the parameters defined in your approved ZNS template
- All required template parameters must be provided
- Values are inserted into the template placeholders

### Development vs Production Mode
- **Development Mode**: Messages are validated but not delivered (for testing)
- **Production Mode**: Messages are actually sent to recipients
- If not specified, defaults to production mode

## Webhook Endpoint

### `/zalo/auth/redirect`

Receives authorization codes from Zalo:

```
GET /zalo/auth/redirect?code=AUTH_CODE&oa_id=OA_ID
```

Response:
```json
{
    "success": true,
    "message": "Token stored successfully",
    "oa_id": "OA_ID"
}
```

## Commands

### Refresh Tokens

```bash
# Preview what tokens need refresh (no actual refresh)
php artisan zalo:refresh-tokens --dry-run

# Refresh specific OA
php artisan zalo:refresh-tokens --oa-id=YOUR_OA_ID

# Force refresh all tokens
php artisan zalo:refresh-tokens --force

# Normal operation (refreshes expiring tokens)
php artisan zalo:refresh-tokens
```

### Command Output Example

```
===============================================
Zalo Token Refresh Batch Job
Started at: 2024-01-15 03:00:00
===============================================
Looking for tokens expiring before: 2024-01-15 06:00:00
Found 2 token(s) to refresh:

+----------------+---------------------+----------------+
| OA ID          | Expires At          | Time Remaining |
+----------------+---------------------+----------------+
| 1234567890     | 2024-01-15 05:30:00 | 2 hours        |
| 0987654321     | 2024-01-15 05:45:00 | 2 hours        |
+----------------+---------------------+----------------+

Starting token refresh process...
 2/2 [============================] 100%

===============================================
Token Refresh Summary
===============================================
✓ Successfully refreshed: 2 token(s)
Completed at: 2024-01-15 03:00:01
```

## Usage Examples

### Send ZNS Message

#### Standard Message Sending
```php
use CPP\ZNSPackage\Facades\ZNS;

$messageData = [
    'phone' => '84912345678',          // Required: Vietnam phone number
    'template_id' => 'your_template_id', // Required: Approved ZNS template ID
    'template_data' => [                 // Required: Template parameters
        'customer_name' => 'Nguyen Van A',
        'amount' => '1,000,000',
        'due_date' => '25/12/2024'
    ],
    'tracking_id' => 'order_12345'      // Optional: Your tracking ID
];

try {
    $response = ZNS::sendZNSMessage('your_oa_id', $messageData);
    // Response contains msg_id for tracking
} catch (\CPP\ZNSPackage\Exceptions\ZNSException $e) {
    Log::error('Failed to send ZNS: ' . $e->getMessage());
}
```

#### Development Mode (Testing)
```php
// Send test message - won't be delivered to actual recipient
$response = ZNS::sendDevelopmentMessage('your_oa_id', [
    'phone' => '0912345678',  // Supports both 09xxx and 849xxx formats
    'template_id' => 'test_template',
    'template_data' => [
        'test_param' => 'test_value'
    ]
]);
```

#### Production Mode (Explicit)
```php
// Explicitly send in production mode
$response = ZNS::sendProductionMessage('your_oa_id', $messageData);
```

#### With Custom Mode
```php
// Specify mode in message data
$messageData['mode'] = 'development'; // or 'production'
$response = ZNS::sendZNSMessage('your_oa_id', $messageData);
```

### Check Message Status

#### Get Status by Message ID
```php
// Using the msg_id returned when sending
$response = ZNS::sendZNSMessage('your_oa_id', $messageData);
$msgId = $response['data']['msg_id'];

// Check status later
$status = ZNS::getMessageStatus('your_oa_id', $msgId);
/*
Returns:
{
    "data": {
        "status": 4,           // Status code (1-6)
        "delivery_status": "delivered",
        "sent_time": "2024-01-15 10:00:00",
        "delivered_time": "2024-01-15 10:00:05"
    }
}
*/
```

#### Get Status by Tracking ID
```php
// Using your custom tracking ID
$trackingId = 'order_12345';
$status = ZNS::getMessageStatusByTrackingId('your_oa_id', $trackingId);
```

#### Status Codes
- `1` - **Pending**: Message queued for sending
- `2` - **Processing**: Message being processed
- `3` - **Sent**: Message sent successfully
- `4` - **Delivered**: Message delivered to recipient
- `5` - **Failed**: Message failed to send
- `6` - **Expired**: Message expired before delivery

### Check Quota Information

#### Get Current Quota Status
```php
$quota = ZNS::getQuotaInfo('your_oa_id');

/*
Returns:
{
    "daily_quota": 5000,           // Total daily limit
    "remaining_quota": 4250,       // Messages remaining today
    "used_quota": 750,              // Messages sent today
    "usage_percentage": 15.0,       // Percentage used
    "quota_status": "healthy",      // Status: healthy/moderate/low/critical/exhausted
    "can_send": true,               // Whether sending is possible
    "reset_time": "2024-01-16 00:00:00 +07"  // When quota resets
}
*/
```

#### Check Before Sending
```php
// Check if quota available before sending
if (ZNS::checkQuotaAvailable('your_oa_id', 10)) {
    // Safe to send 10 messages
    foreach ($recipients as $recipient) {
        ZNS::sendZNSMessage('your_oa_id', $messageData);
    }
} else {
    Log::warning('Insufficient quota for sending messages');
}
```

#### Quota Status Levels
- **Healthy**: >50% remaining
- **Moderate**: 20-50% remaining
- **Low**: 10-20% remaining
- **Critical**: <10% remaining
- **Exhausted**: No quota remaining

### Get Template List

#### List All Templates
```php
// Get templates with pagination
$templates = ZNS::getTemplateList('your_oa_id', 0, 10); // offset 0, limit 10

/*
Returns:
{
    "templates": [
        {
            "template_id": "123456",
            "template_name": "Payment Reminder",
            "status": "active",        // active/inactive/pending/rejected/deleted
            "status_raw": "ENABLE",
            "created_time": "2024-01-01 10:00:00",
            "preview_url": "https://...",
            "template_tag": "PAYMENT",
            "template_params": ["customer_name", "amount", "due_date"],
            "price": 50,
            "apply_template_quota": true,
            "template_daily_quota": 1000,
            "template_remaining_quota": 950
        },
        // ... more templates
    ],
    "total": 25,
    "offset": 0,
    "limit": 10,
    "has_more": true
}
*/
```

#### Pagination Example
```php
// Get all templates using pagination
$allTemplates = [];
$offset = 0;
$limit = 50; // Max 50 per request

do {
    $response = ZNS::getTemplateList('your_oa_id', $offset, $limit);
    $allTemplates = array_merge($allTemplates, $response['templates']);
    $offset += $limit;
} while ($response['has_more']);

echo "Total templates: " . count($allTemplates);
```

#### Filter Active Templates
```php
$templates = ZNS::getTemplateList('your_oa_id', 0, 50);
$activeTemplates = array_filter($templates['templates'], function($template) {
    return $template['status'] === 'active';
});
```

#### Template Status Values
- **active** (ENABLE) - Template is approved and ready to use
- **inactive** (DISABLE) - Template is disabled
- **pending** (PENDING_REVIEW) - Awaiting approval from Zalo
- **rejected** (REJECT) - Template was rejected
- **deleted** (DELETE) - Template has been deleted

### Get Template Details

#### Get Detailed Template Information
```php
$templateInfo = ZNS::getTemplateInfo('your_oa_id', 'template_id_123456');

/*
Returns:
{
    "template_id": "123456",
    "template_name": "Payment Reminder",
    "status": "active",
    "created_time": "2024-01-01 10:00:00",
    "template_tag": "PAYMENT",
    "price": 50,
    "apply_template_quota": true,
    "template_daily_quota": 1000,
    "template_remaining_quota": 950,
    "content": "Dear {{customer_name}}, your payment of {{amount}} is due on {{due_date}}",
    "params": [
        {
            "name": "customer_name",
            "require": true,
            "min_length": 1,
            "max_length": 50,
            "type": "STRING",
            "accept_null": false
        },
        {
            "name": "amount",
            "require": true,
            "min_length": 1,
            "max_length": 20,
            "type": "STRING",
            "accept_null": false
        },
        {
            "name": "due_date",
            "require": true,
            "min_length": 10,
            "max_length": 10,
            "type": "DATE",
            "accept_null": false
        }
    ],
    "preview_url": "https://..."
}
*/
```

#### Validate Template Parameters
```php
// Get template details to understand required parameters
$template = ZNS::getTemplateInfo('your_oa_id', 'template_id');

// Check parameter requirements
foreach ($template['params'] as $param) {
    echo "Parameter: {$param['name']}\n";
    echo "  Required: " . ($param['require'] ? 'Yes' : 'No') . "\n";
    echo "  Type: {$param['type']}\n";
    echo "  Min Length: {$param['min_length']}\n";
    echo "  Max Length: {$param['max_length']}\n";
    echo "  Accepts Null: " . ($param['accept_null'] ? 'Yes' : 'No') . "\n\n";
}
```

#### Prepare Message Data Based on Template
```php
// Get template to see required parameters
$template = ZNS::getTemplateInfo('your_oa_id', 'payment_reminder_template');

// Build template data according to parameter requirements
$templateData = [];
foreach ($template['params'] as $param) {
    if ($param['require']) {
        // Add required parameter with proper validation
        switch ($param['name']) {
            case 'customer_name':
                $templateData['customer_name'] = substr($customerName, 0, $param['max_length']);
                break;
            case 'amount':
                $templateData['amount'] = number_format($amount);
                break;
            case 'due_date':
                $templateData['due_date'] = date('d/m/Y', strtotime($dueDate));
                break;
        }
    }
}

// Send message with validated data
$response = ZNS::sendZNSMessage('your_oa_id', [
    'phone' => '84912345678',
    'template_id' => $template['template_id'],
    'template_data' => $templateData
]);
```

#### Check Template Quota Before Sending
```php
// Get template info to check quota
$template = ZNS::getTemplateInfo('your_oa_id', 'bulk_sms_template');

if ($template['apply_template_quota']) {
    $available = $template['template_remaining_quota'];
    $recipientCount = count($recipients);

    if ($available >= $recipientCount) {
        // Safe to send to all recipients
        foreach ($recipients as $recipient) {
            ZNS::sendZNSMessage('your_oa_id', [
                'phone' => $recipient['phone'],
                'template_id' => $template['template_id'],
                'template_data' => $recipient['data']
            ]);
        }
    } else {
        Log::warning("Insufficient template quota. Available: {$available}, Needed: {$recipientCount}");
    }
}
```

### Manual Token Refresh

```php
use CPP\ZNSPackage\Services\ZNSService;
use CPP\ZNSPackage\Models\ZaloOAToken;

$token = ZaloOAToken::findByOAId('your_oa_id');
$service = app('zns');

try {
    $newTokenData = $service->refreshOAToken(
        $token->refresh_token,
        $token->oa_id
    );
} catch (\Exception $e) {
    Log::error('Manual refresh failed: ' . $e->getMessage());
}
```

## Monitoring

### Database Check

```sql
-- Tokens needing refresh
SELECT oa_id, expires_at,
       TIMESTAMPDIFF(HOUR, NOW(), expires_at) as hours_remaining
FROM zalo_oa_tokens
WHERE expires_at < DATE_ADD(NOW(), INTERVAL 3 HOUR);

-- Token refresh history
SELECT oa_id, expires_at, updated_at,
       TIMESTAMPDIFF(DAY, updated_at, NOW()) as days_since_refresh
FROM zalo_oa_tokens
ORDER BY updated_at DESC;
```

### Log Monitoring

Monitor these log entries:
- `[info] Zalo token refresh batch started`
- `[info] Successfully refreshed Zalo token`
- `[error] Failed to refresh Zalo token`
- `[critical] Zalo token refresh batch had failures`

## Security Features

- **Encrypted Storage**: Tokens encrypted in database using Laravel's encryption
- **SSL Verification**: Enabled in production environment
- **Input Validation**: All webhook inputs validated
- **Error Handling**: Comprehensive try-catch blocks
- **Rate Limiting**: 0.5s delay between API calls in batch refresh
- **Direct Database Access**: No caching layer, tokens retrieved directly from database

## Troubleshooting

### Token Not Refreshing

1. **Check Scheduler**
   ```bash
   php artisan schedule:list
   ```

2. **Verify Token Status**
   ```bash
   php artisan zalo:refresh-tokens --dry-run
   ```

3. **Check Refresh Token Age**
   - Refresh tokens expire after 90 days
   - Tokens older than 90 days cannot be refreshed

4. **Manual Test**
   ```bash
   php artisan zalo:refresh-tokens --oa-id=YOUR_OA_ID --force
   ```

### Common Issues

| Issue | Solution |
|-------|----------|
| "No tokens need refreshing" | Tokens still valid for >3 hours, use `--force` to test |
| "Invalid refresh token" | Refresh token expired (>90 days), need new auth |
| "Rate limit exceeded" | Too many requests, wait and retry |
| Scheduler not running | Add cron entry, verify with `crontab -l` |

## Support

For issues, check logs at `storage/logs/laravel.log` or contact development team.