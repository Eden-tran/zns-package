<?php

namespace CPP\ZNSPackage\Console\Commands;

use Illuminate\Console\Command;
use CPP\ZNSPackage\Models\ZnsTemplate;
use CPP\ZNSPackage\Models\ZaloOAToken;
use CPP\ZNSPackage\Exceptions\ZNSException;

class SyncZnsTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zns:sync-templates
                            {oa_id? : The Zalo Official Account ID (optional, will use from stored token)}
                            {--status= : Filter by status (1:active, 2:pending, 3:rejected, 4:inactive)}
                            {--template-id= : Sync specific template by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync ZNS templates from Zalo API to database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get OA ID from argument or from stored token
        $oaId = $this->argument('oa_id');

        if (!$oaId) {
            // Try to get from stored tokens
            $token = ZaloOAToken::first();

            if (!$token) {
                $this->error('No OA ID provided and no stored token found. Please authenticate first or provide OA ID.');
                $this->line('Run: php artisan zalo:auth or provide OA ID as argument');
                return Command::FAILURE;
            }

            $oaId = $token->oa_id;
            $this->info("Using OA ID from stored token: {$oaId}");
        }

        $templateId = $this->option('template-id');
        $status = $this->option('status');

        $this->info("Starting ZNS template sync for OA ID: {$oaId}");

        try {
            $znsService = app('zns');

            if ($templateId) {
                // Sync specific template
                $this->syncSingleTemplate($znsService, $oaId, $templateId);
            } else {
                // Sync all templates
                $this->syncAllTemplates($znsService, $oaId, $status);
            }

            $this->info('✓ Sync completed successfully!');
            return Command::SUCCESS;

        } catch (ZNSException $e) {
            $this->error("ZNS Error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Sync a single template
     */
    protected function syncSingleTemplate($znsService, string $oaId, string $templateId): void
    {
        $this->info("Syncing template: {$templateId}");

        $template = $znsService->syncSingleTemplateToDatabase($oaId, $templateId, ZnsTemplate::class);

        $this->table(
            ['Field', 'Value'],
            [
                ['Template ID', $template->template_id],
                ['Template Name', $template->template_name],
                ['Status', $template->status_vi],
                ['Parameters', implode(', ', $template->getParameterNames())],
                ['Last Synced', $template->last_synced_at->format('Y-m-d H:i:s')],
            ]
        );

        $this->info("✓ Template synced: {$template->template_name}");
    }

    /**
     * Sync all templates
     */
    protected function syncAllTemplates($znsService, string $oaId, ?string $statusFilter): void
    {
        $statusMap = [
            '1' => 1, // Enable
            '2' => 2, // Pending
            '3' => 3, // Reject
            '4' => 4, // Disable
        ];

        $apiStatus = $statusFilter && isset($statusMap[$statusFilter]) ? $statusMap[$statusFilter] : null;

        if ($statusFilter) {
            $statusLabels = [
                '1' => 'Active',
                '2' => 'Pending',
                '3' => 'Rejected',
                '4' => 'Inactive',
            ];
            $this->info("Filtering by status: {$statusLabels[$statusFilter]}");
        }

        $this->info("Fetching templates from Zalo API...");

        $bar = $this->output->createProgressBar();
        $bar->start();

        // Call ZNS package sync method
        $result = $znsService->syncTemplatesToDatabase($oaId, ZnsTemplate::class, $apiStatus);

        $bar->advance($result['synced']);
        $bar->finish();
        $this->newLine(2);

        // Display results
        $this->info("✓ Synced {$result['synced']} / {$result['total']} templates");

        if (!empty($result['errors'])) {
            $this->warn("Failed to sync " . count($result['errors']) . " templates:");
            foreach ($result['errors'] as $error) {
                $this->line("  - {$error['template_id']}: {$error['error']}");
            }
        }

        // Display summary
        $this->displaySummary($oaId);
    }

    /**
     * Display sync summary
     */
    protected function displaySummary(string $oaId): void
    {
        $templates = ZnsTemplate::forOA($oaId)->get();

        $summary = [
            ['Total Templates', $templates->count()],
            ['Active', $templates->where('status', ZnsTemplate::STATUS_ACTIVE)->count()],
            ['Pending', $templates->where('status', ZnsTemplate::STATUS_PENDING)->count()],
            ['Rejected', $templates->where('status', ZnsTemplate::STATUS_REJECTED)->count()],
            ['Inactive', $templates->where('status', ZnsTemplate::STATUS_INACTIVE)->count()],
        ];

        $this->table(['Metric', 'Count'], $summary);

        // Show active templates
        $activeTemplates = $templates->where('status', ZnsTemplate::STATUS_ACTIVE);

        if ($activeTemplates->isNotEmpty()) {
            $this->newLine();
            $this->info('Active Templates:');

            $activeData = $activeTemplates->map(function ($template) {
                return [
                    $template->template_id,
                    $template->template_name,
                    count($template->template_params ?? []) . ' params',
                    $template->is_active ? '✓' : '✗',
                ];
            })->toArray();

            $this->table(
                ['Template ID', 'Name', 'Parameters', 'Enabled'],
                $activeData
            );
        }
    }
}
