<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class RecoveryMail
{
    /** Check transport availability for both registered and unknown addresses. */
    public function ensureReady(): void
    {
        $name = config('mail.default');
        $driver = config("mail.mailers.{$name}.transport");

        if (in_array($driver, ['log', 'array'], true) && ! app()->environment('testing')) {
            throw new RuntimeException('Password recovery requires a real email transport.');
        }

        $transport = Mail::mailer()->getSymfonyTransport();
        if ($transport instanceof SmtpTransport) {
            // Authenticate before looking up the account, so an SMTP outage
            // produces the same response regardless of account existence.
            $transport->start();
        }
    }
}
