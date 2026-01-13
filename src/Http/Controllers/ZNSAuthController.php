<?php

namespace CPP\ZNSPackage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use CPP\ZNSPackage\Facades\ZNS;
use CPP\ZNSPackage\Exceptions\ZNSException;
use Illuminate\Support\Facades\Log;

class ZNSAuthController extends Controller
{
    /**
     * Handle OA callback webhook from Zalo
     * URL pattern: /zalo/auth/redirect?code=XXX&oa_id=YYY
     * This webhook receives the code, exchanges it for tokens, and stores them in DB
     */
    public function handleAuthRedirect(Request $request)
    {
        // Collect the authorization code and OA ID
        $code = $request->get('code');
        $oaId = $request->get('oa_id');
        $error = $request->get('error');

        // Check for errors
        if ($error) {
            Log::error('Zalo OA authentication error', [
                'error' => $error,
                'error_description' => $request->get('error_description')
            ]);

            return response()->json([
                'success' => false,
                'error' => $request->get('error_description', 'Authentication failed')
            ], 400);
        }

        // Validate required parameters
        if (!$code || !$oaId) {
            Log::error('Missing required parameters', [
                'has_code' => !empty($code),
                'has_oa_id' => !empty($oaId)
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Missing required parameters: code or oa_id'
            ], 400);
        }

        try {
            // Exchange code for OA access token and store in database
            $tokenData = ZNS::getOAAccessToken($code, $oaId);

            // Return success response
            return response()->json([
                'success' => true,
                'message' => 'Token stored successfully',
                'oa_id' => $oaId
            ], 200);

        } catch (ZNSException $e) {
            Log::error('Failed to store OA token', [
                'oa_id' => $oaId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error storing OA token', [
                'oa_id' => $oaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error'
            ], 500);
        }
    }
}