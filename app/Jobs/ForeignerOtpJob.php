<?php

namespace App\Jobs;

use App\Mail\ForeignerOtp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class ForeignerOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $otp,
        public int $ttlMinutes = 5
    ) {
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new ForeignerOtp($this->otp, $this->ttlMinutes));
    }
}
