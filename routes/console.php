<?php

use App\Services\RecoveryMail;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mail:check', function (RecoveryMail $recoveryMail) {
    try {
        $recoveryMail->ensureReady();
        $this->info('Mail transport connected and authenticated. No email was sent.');

        return 0;
    } catch (Throwable $error) {
        $this->error('Mail transport verification failed. Check SMTP settings and the Gmail app password in .env.');
        $this->line('Failure type: '.$error::class);

        return 1;
    }
})->purpose('Check recovery mail connectivity without displaying credentials or sending email');
