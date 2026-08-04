<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailOperationalCheckCommand extends Command
{
    protected $signature = 'mail:operational-check {--to= : Approved test address (required to send)} {--json : JSON output}';

    protected $description = 'Validate mail configuration; send a test only when --to is supplied';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');
        $frontend = filled(env('FRONTEND_URL'));
        $to = $this->option('to') ? (string) $this->option('to') : null;

        $warnings = [];
        $fails = [];

        if ($from === '') {
            $fails[] = 'MAIL_FROM_ADDRESS missing';
        }
        if (in_array($mailer, ['log', 'array'], true)) {
            $warnings[] = 'Mailer is '.$mailer.' — will not deliver externally.';
        }
        if (! $frontend) {
            $warnings[] = 'FRONTEND_URL missing — invitation/verification links may be wrong.';
        }

        $sent = false;
        if ($to !== null && $to !== '') {
            if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $fails[] = 'Invalid --to address.';
            } else {
                try {
                    Mail::raw('Suvakamana mail operational check. No credentials included.', function ($message) use ($to, $fromName) {
                        $message->to($to)->subject('['.$fromName.'] Mail operational check');
                    });
                    $sent = true;
                } catch (Throwable $e) {
                    report($e);
                    $fails[] = 'Failed to send test mail (details in logs; secrets not printed).';
                }
            }
        }

        $report = [
            'mailer_configured' => $mailer !== '',
            'from_configured' => $from !== '',
            'from_name_configured' => $fromName !== '',
            'frontend_url_configured' => $frontend,
            'test_sent' => $sent,
            'spf_dkim_dmarc' => 'Configure SPF/DKIM/DMARC on the sending domain (infrastructure).',
            'warnings' => $warnings,
            'failures' => $fails,
        ];

        $status = $fails !== [] ? 'FAIL' : ($warnings !== [] ? 'WARN' : 'PASS');
        $exit = $fails !== [] ? 1 : 0;

        if ($this->option('json')) {
            $this->line(json_encode(['status' => $status, 'exit_code' => $exit, 'report' => $report], JSON_PRETTY_PRINT));

            return $exit;
        }

        $this->info('Mail operational check');
        $this->line('Mailer present: yes');
        $this->line('From configured: '.($report['from_configured'] ? 'yes' : 'no'));
        $this->line('FRONTEND_URL configured: '.($frontend ? 'yes' : 'no'));
        $this->line('Test sent: '.($sent ? 'yes' : 'no'));
        foreach ($warnings as $w) {
            $this->warn($w);
        }
        foreach ($fails as $f) {
            $this->error($f);
        }
        $this->comment($report['spf_dkim_dmarc']);
        $this->line('Status: '.$status);

        return $exit;
    }
}
