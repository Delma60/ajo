<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Investment;

class InvestmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $samples = [
            [
                'title' => 'Solar Market Pool',
                'subtitle' => 'Community solar lending for traders',
                'description' => 'Short-term pooled loans to solar vendors. Expected monthly interest paid to investors. Capital returned at maturity.',
                'min_investment' => 1000,
                'status' => 'active',
                'raised' => 75000,
                'target' => 200000,
                'start_date' => now()->subDays(7),
                'end_date' => now()->addMonths(6),
                'apy' => 12,
                'duration' => 6,
                'risk' => 'low',
            ],
            [
                'title' => 'Agri Input Finance',
                'subtitle' => 'Seasonal input financing to smallholder groups',
                'description' => 'Funds finances fertilizer and seeds for pre-harvest sales. Returns depend on harvest; historical repays in 4 months.',
                'min_investment' => 500,
                'status' => 'active',
                'raised' => 90000,
                'target' => 120000,
                'start_date' => now()->subDays(14),
                'end_date' => now()->addMonths(4),
                'apy' => 18,
                'duration' => 4,
                'risk' => 'medium',
            ],
            [
                'title' => 'Market Stall Expansion',
                'subtitle' => 'Micro-loans for shop expansion in local markets',
                'description' => 'Longer term, stable merchants with documented track-record. Interest paid monthly, principal at maturity.',
                'min_investment' => 2000,
                'status' => 'active',
                'raised' => 150000,
                'target' => 300000,
                'start_date' => now()->subMonths(1),
                'end_date' => now()->addMonths(12),
                'apy' => 9,
                'duration' => 12,
                'risk' => 'low',
            ],
        ];

        foreach ($samples as $s) {
            Investment::updateOrCreate([
                'title' => $s['title']
            ], $s);
        }
    }
}
