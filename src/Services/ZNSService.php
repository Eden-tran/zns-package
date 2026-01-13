<?php

namespace CPP\ZNSPackage\Services;

use GuzzleHttp\Client;
use CPP\ZNSPackage\Exceptions\ZNSException;
use CPP\ZNSPackage\Models\ZaloOAToken;
use CPP\ZNSPackage\Models\ZnsSendingLog;
use Illuminate\Support\Facades\Log;

class ZNSService
{
    const ACCESS_TOKEN_TTL = 90000; // 25 hours in seconds
    const REFRESH_TOKEN_TTL = 7776000; // 90 days in seconds

    private Client $client;
    private string $appId;
    private string $appSecret;
    private array $config;

    public function __construct(string $appId, string $appSecret, array $config)
    {
        if (empty($appId) || empty($appSecret)) {
            throw new ZNSException('App ID and App Secret are required');
        }

        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->config = $config;
        $this->client = new Client([
            'timeout' => 30,
            'verify' => app()->environment('production'), // Enable SSL verification in production
        ]);
    }

    /**
     * Get OA (Official Account) Access Token
     * Exchanges authorization code for access token and stores in database
     *
     * @param string $authCode The authorization code from callback
     * @param string $oaId The Official Account ID
     * @return array Token response including access_token and refresh_token
     */
    public function getOAAccessToken(string $authCode, string $oaId): array
    {
        try {
            // Call Zalo API to exchange code for tokens
            $response = $this->client->post($this->config['oa_access_token_url'], [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'secret_key' => $this->appSecret,
                ],
                'form_params' => [
                    'code' => $authCode,
                    'app_id' => $this->appId,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new ZNSException('Error getting OA access token: ' . ($data['error_description'] ?? $data['error']));
            }

            // Validate response has required fields
            if (!isset($data['access_token']) || !isset($data['refresh_token'])) {
                throw new ZNSException('Invalid response from Zalo: missing access_token or refresh_token');
            }

            // Add OA ID and app_id to the response for tracking
            $data['oa_id'] = $oaId;
            $data['app_id'] = $this->appId;

            // Calculate actual expiry timestamp (expires_in is in seconds)
            $data['expires_at'] = time() + (int)($data['expires_in'] ?? self::ACCESS_TOKEN_TTL);

            // Store tokens in database
            ZaloOAToken::storeToken($oaId, $data);

            return $data;
        } catch (\Exception $e) {
            Log::error('Failed to get OA access token', [
                'oa_id' => $oaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to get OA access token: ' . $e->getMessage());
        }
    }

    /**
     * Refresh OA access token using refresh token
     *
     * @param string $refreshToken The refresh token
     * @param string $oaId The Official Account ID
     * @return array New token data including new access_token and refresh_token
     */
    public function refreshOAToken(string $refreshToken, string $oaId): array
    {
        try {
            $response = $this->client->post($this->config['oa_access_token_url'], [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'secret_key' => $this->appSecret,
                ],
                'form_params' => [
                    'app_id' => $this->appId,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new ZNSException('Error refreshing OA token: ' . ($data['error_description'] ?? $data['error']));
            }

            // Validate response has required fields
            if (!isset($data['access_token'])) {
                throw new ZNSException('Invalid refresh response: missing access_token');
            }

            // Add OA ID to the response
            $data['oa_id'] = $oaId;
            $data['app_id'] = $this->appId;

            // Calculate actual expiry timestamp
            $data['expires_at'] = time() + (int)($data['expires_in'] ?? self::ACCESS_TOKEN_TTL);

            // Update tokens in database
            ZaloOAToken::storeToken($oaId, $data);

            return $data;
        } catch (\Exception $e) {
            Log::error('Failed to refresh OA token', [
                'oa_id' => $oaId,
                'error' => $e->getMessage()
            ]);

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to refresh OA token: ' . $e->getMessage());
        }
    }

    /**
     * Get OA access token from database
     */
    public function getStoredOAToken(string $oaId): ?string
    {
        // Get token from database
        $tokenRecord = ZaloOAToken::findByOAId($oaId);

        if ($tokenRecord && !$tokenRecord->isExpired()) {
            return $tokenRecord->access_token;
        }

        return null;
    }

    /**
     * Send ZNS message using stored OA token
     *
     * @param string $oaId The Official Account ID
     * @param array $messageData Message data containing:
     *   - phone: recipient phone number (required)
     *   - template_id: ZNS template ID (required)
     *   - template_data: template parameters (required)
     *   - tracking_id: unique tracking ID (optional)
     */
    public function sendZNSMessage(string $oaId, array $messageData): array
    {
        $accessToken = $this->getStoredOAToken($oaId);

        if (!$accessToken) {
            throw new ZNSException('Access token not found for OA ID: ' . $oaId);
        }

        // Validate required fields
        $this->validateMessageData($messageData);

        // Build the request payload according to ZNS API spec
        $payload = $this->buildZNSPayload($messageData);

        // Create sending log
        $log = ZnsSendingLog::createLog([
            'oa_id' => $oaId,
            'phone' => $messageData['phone'],
            'template_id' => $messageData['template_id'],
            'template_data' => $messageData['template_data'],
            'tracking_id' => $payload['tracking_id'],
            'mode' => $messageData['mode'] ?? 'production',
            'request' => $payload,
            'related_id' => $messageData['related_id'] ?? null,
            'related_type' => $messageData['related_type'] ?? null,
        ]);

        try {
            $response = $this->client->post($this->config['zns_api_url'] . $this->config['zns_message_endpoint'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'access_token' => $accessToken,
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Check for API errors
            if (isset($data['error']) && $data['error'] !== 0) {
                $errorMsg = $data['message'] ?? $data['error_description'] ?? $data['error'];
                Log::error('ZNS API error response', [
                    'error_code' => $data['error'] ?? null,
                    'error_message' => $errorMsg,
                    'oa_id' => $oaId
                ]);

                $result = [
                    'status' => 'failed',
                    'error' => $data['error'],
                    'message' => $errorMsg,
                    'data' => null
                ];

                // Update log with failed response
                $log->updateWithResponse($result);

                return $result;
            }

            // Success response
            $result = [
                'status' => 'success',
                'error' => 0,
                'message' => $data['message'] ?? 'Success',
                'data' => $data['data'] ?? [
                    'msg_id' => $data['msg_id'] ?? null,
                    'sent_time' => $data['sent_time'] ?? null,
                    'sending_mode' => $data['sending_mode'] ?? null,
                    'quota' => $data['quota'] ?? null
                ]
            ];

            // Update log with success response
            $log->updateWithResponse($result);

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to send ZNS message', [
                'oa_id' => $oaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update log with exception
            $log->markAsFailed($e->getMessage());

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to send ZNS message: ' . $e->getMessage());
        }
    }

    /**
     * Validate message data has required fields
     */
    private function validateMessageData(array $data): void
    {
        $required = ['phone', 'template_id', 'template_data'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new ZNSException('Missing required fields: ' . implode(', ', $missing));
        }

        // Validate phone format (Vietnam phone number)
        if (!preg_match('/^(84|0)[0-9]{9,10}$/', $data['phone'])) {
            throw new ZNSException('Invalid phone number format. Must be Vietnam phone number.');
        }

        // Validate template_data is array/object
        if (!is_array($data['template_data'])) {
            throw new ZNSException('template_data must be an array');
        }
    }

    /**
     * Build ZNS message payload according to API specification
     */
    private function buildZNSPayload(array $data): array
    {
        // Generate tracking ID if not provided
        $trackingId = $data['tracking_id'] ?? uniqid('zns_' . time() . '_');

        // Build the standard ZNS payload
        $payload = [
            'phone' => $this->normalizePhoneNumber($data['phone']),
            'template_id' => $data['template_id'],
            'template_data' => $data['template_data'],
            'tracking_id' => $trackingId
        ];

        // Add optional fields if present
        if (isset($data['mode'])) {
            $payload['mode'] = $data['mode'];
        }

        return $payload;
    }

    /**
     * Normalize phone number to ZNS format
     * Converts 0xxxxxxxxx to 84xxxxxxxxx
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Convert 0xxx to 84xxx
        if (str_starts_with($phone, '0')) {
            $phone = '84' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Send ZNS message in development mode (for testing)
     *
     * @param string $oaId The Official Account ID
     * @param array $messageData Message data
     * @return array Response with status (success/failed), error code, message, and data
     */
    public function sendDevelopmentMessage(string $oaId, array $messageData): array
    {
        // Add development mode to the message data
        $messageData['mode'] = 'development';

        return $this->sendZNSMessage($oaId, $messageData);
    }

    /**
     * Send ZNS message in production mode (actual delivery)
     *
     * @param string $oaId The Official Account ID
     * @param array $messageData Message data
     * @return array Response with status (success/failed), error code, message, and data
     */
    public function sendProductionMessage(string $oaId, array $messageData): array
    {
        // Ensure production mode
        $messageData['mode'] = 'production';

        return $this->sendZNSMessage($oaId, $messageData);
    }

    /**
     * Get ZNS message status by message ID
     *
     * @param string $oaId The Official Account ID
     * @param string $messageId The message ID returned from sending
     */
    public function getMessageStatus(string $oaId, string $messageId): array
    {
        $accessToken = $this->getStoredOAToken($oaId);

        if (!$accessToken) {
            throw new ZNSException('Access token not found for OA ID: ' . $oaId);
        }

        try {
            $response = $this->client->get($this->config['zns_api_url'] . $this->config['zns_status_endpoint'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'access_token' => $accessToken,
                ],
                'query' => [
                    'message_id' => $messageId
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                $errorMsg = $data['message'] ?? $data['error_description'] ?? $data['error'];
                Log::error('ZNS status API error', [
                    'error_code' => $data['error'] ?? null,
                    'error_message' => $errorMsg,
                    'message_id' => $messageId
                ]);
                throw new ZNSException('Failed to get message status: ' . $errorMsg);
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Failed to get ZNS message status', [
                'oa_id' => $oaId,
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to get message status: ' . $e->getMessage());
        }
    }

    /**
     * Get ZNS message status by tracking ID
     *
     * @param string $oaId The Official Account ID
     * @param string $trackingId The tracking ID provided when sending
     */
    public function getMessageStatusByTrackingId(string $oaId, string $trackingId): array
    {
        $accessToken = $this->getStoredOAToken($oaId);

        if (!$accessToken) {
            throw new ZNSException('Access token not found for OA ID: ' . $oaId);
        }

        try {
            $response = $this->client->get($this->config['zns_api_url'] . $this->config['zns_status_endpoint'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'access_token' => $accessToken,
                ],
                'query' => [
                    'tracking_id' => $trackingId
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                $errorMsg = $data['message'] ?? $data['error_description'] ?? $data['error'];
                Log::error('ZNS status API error', [
                    'error_code' => $data['error'] ?? null,
                    'error_message' => $errorMsg,
                    'tracking_id' => $trackingId
                ]);
                throw new ZNSException('Failed to get message status: ' . $errorMsg);
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Failed to get ZNS message status by tracking ID', [
                'oa_id' => $oaId,
                'tracking_id' => $trackingId,
                'error' => $e->getMessage()
            ]);

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to get message status: ' . $e->getMessage());
        }
    }

    /**
     * Parse message status response
     */
    private function parseMessageStatus(array $data): array
    {
        $statusMap = [
            1 => 'pending',      // Message queued
            2 => 'processing',   // Message being processed
            3 => 'sent',         // Message sent successfully
            4 => 'delivered',    // Message delivered to recipient
            5 => 'failed',       // Message failed to send
            6 => 'expired'       // Message expired
        ];

        $status = $data['status'] ?? 0;
        $parsedStatus = $statusMap[$status] ?? 'unknown';

        return [
            'status' => $parsedStatus,
            'status_code' => $status,
            'delivery_status' => $data['delivery_status'] ?? null,
            'sent_time' => $data['sent_time'] ?? null,
            'delivered_time' => $data['delivered_time'] ?? null,
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null
        ];
    }

    /**
     * Get ZNS quota information for the Official Account
     *
     * @param string $oaId The Official Account ID
     * @return array Quota information including daily limit and remaining quota
     */
    public function getQuotaInfo(string $oaId): array
    {
        $accessToken = $this->getStoredOAToken($oaId);

        if (!$accessToken) {
            throw new ZNSException('Access token not found for OA ID: ' . $oaId);
        }

        try {
            $response = $this->client->get($this->config['zns_api_url'] . $this->config['zns_quota_endpoint'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'access_token' => $accessToken,
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error']) && $data['error'] !== 0) {
                $errorMsg = $data['message'] ?? $data['error_description'] ?? 'Unknown error';
                Log::error('ZNS quota API error', [
                    'error_code' => $data['error'],
                    'error_message' => $errorMsg,
                    'oa_id' => $oaId
                ]);
                throw new ZNSException('Failed to get quota information: ' . $errorMsg);
            }

            // Parse and enhance quota information
            $quotaInfo = $this->parseQuotaInfo($data['data'] ?? []);

            return $quotaInfo;
        } catch (\Exception $e) {
            Log::error('Failed to get ZNS quota information', [
                'oa_id' => $oaId,
                'error' => $e->getMessage()
            ]);

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to get quota information: ' . $e->getMessage());
        }
    }

    /**
     * Parse quota information response
     */
    private function parseQuotaInfo(array $data): array
    {
        $dailyQuota = (int)($data['dailyQuota'] ?? 0);
        $remainingQuota = (int)($data['remainingQuota'] ?? 0);
        $usedQuota = $dailyQuota - $remainingQuota;
        $usagePercentage = $dailyQuota > 0 ? round(($usedQuota / $dailyQuota) * 100, 2) : 0;

        return [
            'daily_quota' => $dailyQuota,
            'remaining_quota' => $remainingQuota,
            'used_quota' => $usedQuota,
            'usage_percentage' => $usagePercentage,
            'quota_status' => $this->getQuotaStatus($remainingQuota, $dailyQuota),
            'can_send' => $remainingQuota > 0,
            'reset_time' => $this->getQuotaResetTime(),
            'raw_data' => $data
        ];
    }

    /**
     * Get quota status based on remaining quota
     */
    private function getQuotaStatus(int $remaining, int $daily): string
    {
        if ($remaining <= 0) {
            return 'exhausted';
        }

        $percentage = ($remaining / $daily) * 100;

        if ($percentage > 50) {
            return 'healthy';
        } elseif ($percentage > 20) {
            return 'moderate';
        } elseif ($percentage > 10) {
            return 'low';
        } else {
            return 'critical';
        }
    }

    /**
     * Get quota reset time (typically midnight Vietnam time)
     */
    private function getQuotaResetTime(): string
    {
        // ZNS quotas typically reset at midnight Vietnam time (UTC+7)
        $vietnamTimezone = new \DateTimeZone('Asia/Ho_Chi_Minh');
        $resetTime = new \DateTime('tomorrow midnight', $vietnamTimezone);

        return $resetTime->format('Y-m-d H:i:s T');
    }

    /**
     * Check if quota is available before sending
     *
     * @param string $oaId The Official Account ID
     * @param int $messageCount Number of messages to send (default 1)
     * @return bool True if quota is available
     */
    public function checkQuotaAvailable(string $oaId, int $messageCount = 1): bool
    {
        try {
            $quotaInfo = $this->getQuotaInfo($oaId);
            return $quotaInfo['remaining_quota'] >= $messageCount;
        } catch (\Exception $e) {
            // Return true to allow sending attempt (fail at API level if quota exceeded)
            return true;
        }
    }

    /**
     * Get list of ZNS templates
     *
     * @param string $oaId The Official Account ID
     * @param int $offset Start index for pagination (default 0)
     * @param int $limit Number of templates to retrieve (default 10, max 100)
     * @param int|null $status Template status filter:
     *   - 1: Enable (active templates)
     *   - 2: Pending review
     *   - 3: Reject (rejected templates)
     *   - 4: Disable (inactive templates)
     *   - null: All templates
     * @return array Template list with pagination info
     */
    public function getTemplateList(string $oaId, int $offset = 0, int $limit = 10, ?int $status = null): array
    {
        $accessToken = $this->getStoredOAToken($oaId);

        if (!$accessToken) {
            throw new ZNSException('Access token not found for OA ID: ' . $oaId);
        }

        // Validate pagination parameters
        $limit = min(max(1, $limit), 100); // Ensure limit is between 1 and 100
        $offset = max(0, $offset); // Ensure offset is not negative

        // Validate status parameter (1-4 or null)
        if ($status !== null && !in_array($status, [1, 2, 3, 4])) {
            throw new ZNSException('Invalid status value. Must be 1 (Enable), 2 (Pending review), 3 (Reject), 4 (Disable), or null (All)');
        }

        // Build query parameters
        $queryParams = [
            'offset' => $offset,
            'limit' => $limit,
        ];

        // Add status filter if provided
        if ($status !== null) {
            $queryParams['status'] = $status;
        }

        $url = $this->config['zns_api_url'] . $this->config['zns_template_list_endpoint'];

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'access_token' => $accessToken,
                ],
                'query' => $queryParams
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error']) && $data['error'] !== 0) {
                $errorMsg = $data['message'] ?? $data['error_description'] ?? 'Unknown error';
                Log::error('ZNS template list API error', [
                    'error_code' => $data['error'],
                    'error_message' => $errorMsg,
                    'oa_id' => $oaId
                ]);
                throw new ZNSException('Failed to get template list: ' . $errorMsg);
            }

            // Parse and enhance template list
            $templates = $this->parseTemplateList($data['data'] ?? [], $data['metadata'] ?? []);

            return $templates;
        } catch (\Exception $e) {
            Log::error('Failed to get ZNS template list', [
                'oa_id' => $oaId,
                'error' => $e->getMessage()
            ]);

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to get template list: ' . $e->getMessage());
        }
    }

    /**
     * Parse template list response
     */
    private function parseTemplateList(array $data, array $metadata = []): array
    {
        // $data is the array of templates directly from API response
        $parsedTemplates = [];

        foreach ($data as $template) {
            $parsedTemplates[] = $this->parseTemplate($template);
        }

        return [
            'data' => $parsedTemplates,
            'total' => $metadata['total'] ?? count($parsedTemplates)
        ];
    }

    /**
     * Parse individual template
     */
    private function parseTemplate(array $template): array
    {
        $statusMap = [
            'ENABLE' => 'active',
            'DISABLE' => 'inactive',
            'PENDING_REVIEW' => 'pending',
            'REJECT' => 'rejected',
            'DELETE' => 'deleted'
        ];

        $status = $template['status'] ?? 'UNKNOWN';

        return [
            'template_id' => $template['templateId'] ?? $template['template_id'] ?? '',
            'template_name' => $template['templateName'] ?? $template['template_name'] ?? '',
            'status' => $statusMap[$status] ?? strtolower($status),
            'status_raw' => $status,
            'created_time' => $template['createdTime'] ?? $template['created_time'] ?? null,
            'preview_url' => $template['previewUrl'] ?? $template['preview_url'] ?? null,
            'template_tag' => $template['templateTag'] ?? $template['template_tag'] ?? null,
            'template_params' => $template['listParams'] ?? $template['template_params'] ?? [],
            'price' => $template['price'] ?? null,
            'apply_template_quota' => $template['applyTemplateQuota'] ?? false,
            'template_daily_quota' => $template['templateDailyQuota'] ?? null,
            'template_remaining_quota' => $template['templateRemainingQuota'] ?? null
        ];
    }

    /**
     * Get detailed information about a specific template
     *
     * @param string $oaId The Official Account ID
     * @param string $templateId The template ID to get details for
     * @return array Detailed template information
     */
    public function getTemplateInfo(string $oaId, string $templateId): array
    {
        $accessToken = $this->getStoredOAToken($oaId);

        if (!$accessToken) {
            throw new ZNSException('Access token not found for OA ID: ' . $oaId);
        }

        try {
            $response = $this->client->get($this->config['zns_api_url'] . $this->config['zns_template_info_endpoint'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'access_token' => $accessToken,
                ],
                'query' => [
                    'template_id' => $templateId
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error']) && $data['error'] !== 0) {
                $errorMsg = $data['message'] ?? $data['error_description'] ?? 'Unknown error';
                Log::error('ZNS template info API error', [
                    'error_code' => $data['error'],
                    'error_message' => $errorMsg,
                    'template_id' => $templateId
                ]);
                throw new ZNSException('Failed to get template info: ' . $errorMsg);
            }

            // Parse template details
            $templateInfo = $this->parseTemplateInfo($data['data'] ?? []);

            return $templateInfo;
        } catch (\Exception $e) {
            Log::error('Failed to get ZNS template details', [
                'oa_id' => $oaId,
                'template_id' => $templateId,
                'error' => $e->getMessage()
            ]);

            if ($e instanceof ZNSException) {
                throw $e;
            }

            throw new ZNSException('Failed to get template details: ' . $e->getMessage());
        }
    }

    /**
     * Parse template info response
     */
    private function parseTemplateInfo(array $data): array
    {
        $statusMap = [
            'ENABLE' => 'active',
            'DISABLE' => 'inactive',
            'PENDING_REVIEW' => 'pending',
            'REJECT' => 'rejected',
            'DELETE' => 'deleted'
        ];

        $status = $data['status'] ?? 'UNKNOWN';

        // Parse template parameters with detailed info
        $params = [];
        if (isset($data['listParams']) && is_array($data['listParams'])) {
            foreach ($data['listParams'] as $param) {
                $params[] = [
                    'name' => $param['name'] ?? $param['Name'] ?? '',
                    'type' => strtolower($param['type'] ?? $param['Type'] ?? 'string'),
                    'required' => $param['require'] ?? $param['Require'] ?? false,
                    'min_length' => $param['minLength'] ?? $param['MinLength'] ?? 0,
                    'max_length' => $param['maxLength'] ?? $param['MaxLength'] ?? null,
                    'accept_null' => $param['acceptNull'] ?? $param['AcceptNull'] ?? false
                ];
            }
        }

        return [
            'template_id' => $data['templateId'] ?? $data['TemplateId'] ?? '',
            'template_name' => $data['templateName'] ?? $data['TemplateName'] ?? '',
            'status' => $statusMap[$status] ?? strtolower($status),
            'status_raw' => $status,
            'template_content' => $data['templateContent'] ?? $data['content'] ?? '',
            'preview_url' => $data['previewUrl'] ?? $data['PreviewUrl'] ?? null,
            'template_tag' => $data['templateTag'] ?? $data['TemplateTag'] ?? null,
            'template_quality' => $data['templateQuality'] ?? $data['TemplateQuality'] ?? null,
            'template_params' => $params,
            'timeout' => $data['timeout'] ?? $data['Timeout'] ?? null,
            'apply_template_quota' => $data['applyTemplateQuota'] ?? $data['ApplyTemplateQuota'] ?? false,
            'template_daily_quota' => $data['templateDailyQuota'] ?? $data['TemplateDailyQuota'] ?? null,
            'template_remaining_quota' => $data['templateRemainingQuota'] ?? $data['TemplateRemainingQuota'] ?? null,
            'created_time' => $data['createdTime'] ?? null,
            'updated_time' => $data['updatedTime'] ?? null,
            'price' => $data['price'] ?? null
        ];
    }

    /**
     * Sync templates from Zalo API to database
     *
     * @param string $oaId The Official Account ID
     * @param string|null $modelClass The model class to use for storing (default: \App\Models\ZnsTemplate)
     * @param int|null $status Filter by status (1:Enable, 2:Pending, 3:Reject, 4:Disable)
     * @return array ['total' => int, 'synced' => int, 'errors' => array]
     */
    public function syncTemplatesToDatabase(string $oaId, ?string $modelClass = null, ?int $status = null): array
    {
        $modelClass = $modelClass ?? '\CPP\ZNSPackage\Models\ZnsTemplate';

        if (!class_exists($modelClass)) {
            throw new ZNSException("Model class {$modelClass} does not exist");
        }

        $offset = 0;
        $limit = 100;
        $totalSynced = 0;
        $errors = [];

        try {
            do {
                $result = $this->getTemplateList($oaId, $offset, $limit, $status);
                $templates = $result['data'] ?? [];

                foreach ($templates as $templateData) {
                    try {
                        // Get full template details to retrieve params
                        $templateId = $templateData['template_id'];
                        $fullTemplateData = $this->getTemplateInfo($oaId, $templateId);

                        // Store with full details
                        $this->storeTemplate($modelClass, $oaId, $fullTemplateData);
                        $totalSynced++;
                    } catch (\Exception $e) {
                        $errors[] = [
                            'template_id' => $templateData['template_id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                        Log::error('Failed to sync template', [
                            'template_id' => $templateData['template_id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $offset += $limit;
                $hasMore = count($templates) === $limit;
            } while ($hasMore);

            return [
                'total' => $totalSynced + count($errors),
                'synced' => $totalSynced,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            Log::error('Failed to sync templates', [
                'oa_id' => $oaId,
                'error' => $e->getMessage()
            ]);
            throw new ZNSException('Failed to sync templates: ' . $e->getMessage());
        }
    }

    /**
     * Sync a single template from Zalo API to database
     *
     * @param string $oaId The Official Account ID
     * @param string $templateId The template ID to sync
     * @param string|null $modelClass The model class to use for storing
     * @return mixed The stored model instance
     */
    public function syncSingleTemplateToDatabase(string $oaId, string $templateId, ?string $modelClass = null)
    {
        $modelClass = $modelClass ?? '\CPP\ZNSPackage\Models\ZnsTemplate';

        if (!class_exists($modelClass)) {
            throw new ZNSException("Model class {$modelClass} does not exist");
        }

        try {
            $templateData = $this->getTemplateInfo($oaId, $templateId);
            return $this->storeTemplate($modelClass, $oaId, $templateData);
        } catch (\Exception $e) {
            Log::error('Failed to sync single template', [
                'oa_id' => $oaId,
                'template_id' => $templateId,
                'error' => $e->getMessage()
            ]);
            throw new ZNSException('Failed to sync template: ' . $e->getMessage());
        }
    }

    /**
     * Store template to database using the model
     *
     * @param string $modelClass The model class name
     * @param string $oaId The Official Account ID
     * @param array $data Template data from Zalo API
     * @return mixed The stored model instance
     */
    protected function storeTemplate(string $modelClass, string $oaId, array $data)
    {
        $statusMap = [
            'active' => 1,
            'pending' => 2,
            'rejected' => 3,
            'inactive' => 4,
            'deleted' => 5,
        ];

        $status = $statusMap[$data['status']] ?? 4;

        return $modelClass::updateOrCreate(
            ['template_id' => $data['template_id']],
            [
                'oa_id' => $oaId,
                'template_name' => $data['template_name'] ?? '',
                'template_content' => $data['template_content'] ?? null,
                'status' => $status,
                'status_raw' => $data['status_raw'] ?? $data['status'],
                'preview_url' => $data['preview_url'] ?? null,
                'template_tag' => $data['template_tag'] ?? null,
                'template_quality' => $data['template_quality'] ?? null,
                'template_params' => $data['template_params'] ?? [],
                'timeout' => $data['timeout'] ?? null,
                'apply_template_quota' => $data['apply_template_quota'] ?? false,
                'template_daily_quota' => $data['template_daily_quota'] ?? null,
                'template_remaining_quota' => $data['template_remaining_quota'] ?? null,
                'price' => $data['price'] ?? null,
                'zalo_created_time' => isset($data['created_time']) && $data['created_time'] > 0
                    ? date('Y-m-d H:i:s', $data['created_time'])
                    : null,
                'zalo_updated_time' => isset($data['updated_time']) && $data['updated_time'] > 0
                    ? date('Y-m-d H:i:s', $data['updated_time'])
                    : null,
                'last_synced_at' => now(),
            ]
        );
    }

}