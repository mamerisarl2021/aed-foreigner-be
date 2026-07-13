<?php

namespace App\Jobs;

use App\Mail\SendInitLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendInitLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;
    public $code;
    public $type;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user, $code, $type)
    {
        $this->code = $code;
        $this->user = $user;
        $this->type = $type;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $code= $this->code;
        Mail::to($this->user)->queue(new SendInitLink($code, $this->user, $this->type));
    }
}
