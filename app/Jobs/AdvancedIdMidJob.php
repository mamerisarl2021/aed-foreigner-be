<?php

namespace App\Jobs;

use App\Mail\AdvancedIdMid;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class AdvancedIdMidJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $email;
    public $link;
    public $name;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($email, $name, $link)
    {
        $this->email = $email;
        $this->link = $link;
        $this->name = $name;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Mail::to($this->email)->queue(new AdvancedIdMid($this->email, $this->name,  $this->link));
    }
}