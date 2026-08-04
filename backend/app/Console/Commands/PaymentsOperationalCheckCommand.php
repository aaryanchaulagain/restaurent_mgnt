<?php

namespace App\Console\Commands;

use App\Services\Operations\PaymentsOperationalCheckService;
use Illuminate\Console\Command;

class PaymentsOperationalCheckCommand extends Command
{
    protected $signature = 'payments:operational-check {--json : JSON output}';

    protected $description = 'Validate payment/webhook configuration without live API calls or secret output';

    public function handle(PaymentsOperationalCheckService $service): int
    {
        $result = $service->evaluate();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $result['exit_code'];
        }

        $report = $result['report'];
        $this->info('Payments operational check');
        $this->line('Driver: '.$report['payment_driver']);
        $this->line('Secret configured: '.($report['stripe_secret_configured'] ? 'yes' : 'no'));
        $this->line('Publishable configured: '.($report['stripe_publishable_configured'] ? 'yes' : 'no'));
        $this->line('Webhook secret configured: '.($report['webhook_secret_configured'] ? 'yes' : 'no'));
        $this->line('Webhook route: '.($report['webhook_route_registered'] ? 'yes' : 'no'));
        $this->line('Unprocessed/failed webhooks: '.$report['failed_or_unprocessed_webhook_count']);
        $this->line('Oldest unprocessed: '.($report['oldest_unprocessed_at'] ?? 'n/a'));
        $this->line('Live API calls: no');
        foreach ($report['warnings'] as $w) {
            $this->warn($w);
        }
        foreach ($report['failures'] as $f) {
            $this->error($f);
        }
        $this->line('Status: '.$result['status']);

        return $result['exit_code'];
    }
}
