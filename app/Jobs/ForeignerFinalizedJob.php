<?php

namespace App\Jobs;

use App\Mail\ForeignerFinalized;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class ForeignerFinalizedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $type
    ) {
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new ForeignerFinalized($this->type));
    }
}
