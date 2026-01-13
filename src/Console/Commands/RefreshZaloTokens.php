<?php

namespace CPP\ZNSPackage\Console\Commands;

use Illuminate\Console\Command;
use CPP\ZNSPackage\Models\ZaloOAToken;
use CPP\ZNSPackage\Services\ZNSService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RefreshZaloTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zalo:refresh-tokens
                            {--force : Force refresh all tokens regardless of expiry}
                            {--oa-id= : Refresh token for specific OA ID only}
                            {--dry-run : Preview what tokens would be refreshed without actually refreshing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh Zalo OA access tokens (runs daily to refresh tokens expiring within 3 hours)';

    private ZNSService $znsService;

    /**
     * Execute the console command.
     *
     * This command is designed to run every 24 hours as a scheduled job.
     * Zalo access tokens are valid for 25 hours, so we refresh them when
     * they have less than 3 hours remaining to ensure continuous availability.
     */
    public function handle(): int
    {
        $this->znsService = app('zns');

        $force = $this->option('force');
        $specificOaId = $this->option('oa-id');
        $dryRun = $this->option('dry-run');

        $this->info('===============================================');
        $this->info('Zalo Token Refresh Batch Job');
        $this->info('Started at: ' . now()->format('Y-m-d H:i:s'));
        $this->info('===============================================');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No tokens will actually be refreshed');
        }

        // Get tokens to refresh
        $query = ZaloOAToken::query();

        if ($specificOaId) {
            $query->where('oa_id', $specificOaId);
            $this->info("Filtering for OA ID: {$specificOaId}");
        }

        if (!$force) {
            // For daily batch job: refresh tokens expiring in the next 3 hours
            // This gives us a safety buffer since tokens are valid for 25 hours
            // and we run this job every 24 hours
            $expiryThreshold = Carbon::now()->addHours(3);
            $query->where('expires_at', '<=', $expiryThreshold);

            $this->info("Looking for tokens expiring before: {$expiryThreshold->format('Y-m-d H:i:s')}");
        } else {
            $this->warn('FORCE MODE - Refreshing all tokens regardless of expiry');
        }

        // Also exclude tokens that have already expired more than 90 days ago
        // (refresh tokens are only valid for 90 days)
        $refreshTokenExpiry = Carbon::now()->subDays(90);
        $query->where('updated_at', '>', $refreshTokenExpiry);

        $tokens = $query->orderBy('expires_at', 'asc')->get();

        if ($tokens->isEmpty()) {
            $this->info('✓ No tokens need refreshing at this time.');
            return Command::SUCCESS;
        }

        $this->info("Found {$tokens->count()} token(s) to refresh:");
        $this->newLine();

        // Display tokens that will be refreshed
        $this->table(
            ['OA ID', 'Expires At', 'Time Remaining'],
            $tokens->map(function ($token) {
                $remaining = $token->expires_at ?
                    Carbon::now()->diffForHumans($token->expires_at, true) :
                    'Unknown';
                return [
                    $token->oa_id,
                    $token->expires_at ? $token->expires_at->format('Y-m-d H:i:s') : 'Unknown',
                    $remaining
                ];
            })->toArray()
        );

        if ($dryRun) {
            $this->info('Dry run complete. Exiting without refreshing tokens.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('Starting token refresh process...');
        $this->newLine();

        $successCount = 0;
        $failureCount = 0;
        $errors = [];

        $bar = $this->output->createProgressBar($tokens->count());
        $bar->start();

        foreach ($tokens as $token) {
            try {
                $bar->advance();

                // Add a small delay to avoid rate limiting
                usleep(500000); // 0.5 seconds

                // Call the refresh method
                $newTokenData = $this->znsService->refreshOAToken(
                    $token->refresh_token,
                    $token->oa_id
                );

                if (isset($newTokenData['access_token'])) {
                    $successCount++;
                } else {
                    $failureCount++;
                    $errors[] = [
                        'oa_id' => $token->oa_id,
                        'error' => 'No access token in response'
                    ];
                }
            } catch (\Exception $e) {
                $failureCount++;
                $errors[] = [
                    'oa_id' => $token->oa_id,
                    'error' => $e->getMessage()
                ];

                Log::error('Failed to refresh Zalo token', [
                    'oa_id' => $token->oa_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Display results
        $this->info('===============================================');
        $this->info('Token Refresh Summary');
        $this->info('===============================================');
        $this->info("✓ Successfully refreshed: {$successCount} token(s)");

        if ($failureCount > 0) {
            $this->error("✗ Failed to refresh: {$failureCount} token(s)");

            if (!empty($errors)) {
                $this->newLine();
                $this->error('Error Details:');
                $this->table(
                    ['OA ID', 'Error Message'],
                    array_map(function ($error) {
                        return [
                            $error['oa_id'],
                            substr($error['error'], 0, 80) . (strlen($error['error']) > 80 ? '...' : '')
                        ];
                    }, $errors)
                );
            }
        }

        $this->info('Completed at: ' . now()->format('Y-m-d H:i:s'));

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}