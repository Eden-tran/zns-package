<?php

namespace CPP\ZNSPackage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getOAAccessToken(string $authCode, string $oaId)
 * @method static array refreshOAToken(string $refreshToken, string $oaId)
 * @method static string|null getStoredOAToken(string $oaId)
 * @method static array sendZNSMessage(string $oaId, array $messageData)
 * @method static array sendDevelopmentMessage(string $oaId, array $messageData)
 * @method static array sendProductionMessage(string $oaId, array $messageData)
 * @method static array getMessageStatus(string $oaId, string $messageId)
 * @method static array getMessageStatusByTrackingId(string $oaId, string $trackingId)
 * @method static array getQuotaInfo(string $oaId)
 * @method static bool checkQuotaAvailable(string $oaId, int $messageCount = 1)
 * @method static array getTemplateList(string $oaId, int $offset = 0, int $limit = 10, ?int $status = null)
 * @method static array getTemplateInfo(string $oaId, string $templateId)
 *
 * @see \CPP\ZNSPackage\Services\ZNSService
 */
class ZNS extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'zns';
    }
}