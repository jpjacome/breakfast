<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Proves the mailbox credentials and the SMTP route actually work, without
 * needing to fill in the contact form or invite a real person.
 *
 *   php artisan mail:test alguien@ejemplo.com
 *
 * Run it on the server through the iFastNet terminal after uploading .env —
 * that is the only place the answer counts, since mail.vamosdebreakfast.com
 * resolves and authenticates differently from a laptop.
 */
final class MailHealthCheck extends Command
{
    protected $signature = 'mail:test {recipient? : Address to send to; defaults to CONTACT_INBOX}';

    protected $description = 'Send a test message using the configured mailer and report what happened';

    public function handle(): int
    {
        $mailer = config('mail.default');
        $from = config('mail.from.address');
        $inbox = config('mail.contact_inbox') ?: $from;
        $recipient = $this->argument('recipient') ?: $inbox;

        $this->line("Mailer:    <options=bold>{$mailer}</>");

        if ($mailer === 'smtp') {
            $this->line('Host:      '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
            $this->line('Scheme:    '.(config('mail.mailers.smtp.scheme') ?: '(unset — will not work on port 465)'));
            $this->line('Username:  '.(config('mail.mailers.smtp.username') ?: '(none)'));
            $this->line('Password:  '.(filled(config('mail.mailers.smtp.password')) ? 'set' : '(none)'));
        }

        $this->line("From:      {$from}");
        $this->line("Contact:   {$inbox}");
        $this->line("Sending to <options=bold>{$recipient}</>...");
        $this->newLine();

        try {
            Mail::raw(
                'Prueba de envío desde '.config('app.name').".\n\n".
                "Si estás leyendo esto, el correo saliente funciona.\n".
                'Mailer: '.$mailer."\n".
                'Enviado: '.now()->toDateTimeString(),
                fn ($message) => $message->to($recipient)->subject('Prueba de correo — '.config('app.name')),
            );
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($mailer === 'log') {
            $this->info('Written to storage/logs/laravel.log — the log mailer sends nothing.');

            return self::SUCCESS;
        }

        $this->info("Accepted by the server. Check {$recipient} (and its spam folder).");

        return self::SUCCESS;
    }
}
