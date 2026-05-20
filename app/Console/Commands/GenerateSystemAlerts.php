<?php

namespace App\Console\Commands;

use App\Services\SystemAlertService;
use Illuminate\Console\Command;

class GenerateSystemAlerts extends Command
{
    protected $signature   = 'system:generate-alerts';
    protected $description = 'Scan the system and generate admin alerts for anomalies';

    public function handle(SystemAlertService $service): int
    {
        $this->info('Generating system alerts...');

        try {
            $service->generate();
            $summary = $service->summary();

            $this->table(
                ['Type', 'Count'],
                [
                    ['Critical', $summary['critical']],
                    ['Warning',  $summary['warning']],
                    ['Info',     $summary['info']],
                    ['Total',    $summary['total']],
                ]
            );

            $this->info('Done.');
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}