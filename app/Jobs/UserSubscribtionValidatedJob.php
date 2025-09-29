<?php

namespace App\Jobs;

use App\Mail\UserSubscribtionValidated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class UserSubscribtionValidatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;
    public $processId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user,$processId)
    {
        $this->user = $user;
        $this->processId = $processId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Mail::to($this->user)->queue(new UserSubscribtionValidated($this->user,$this->processId));
    }
}
