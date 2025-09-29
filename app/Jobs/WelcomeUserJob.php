<?php

namespace App\Jobs;

use App\Mail\WelcomeUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class WelcomeUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $email;
    public $user;
    public $hasAccount = false;
    public $activationUrl;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($email, $user, $activationUrl, $hasAccount = false)
    {
        $this->hasAccount = $hasAccount;
        $this->email = $email;
        $this->activationUrl = $activationUrl;
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Mail::to($this->email)->queue(new WelcomeUser($this->user, $this->activationUrl, $this->hasAccount));
    }
}
