<?php

namespace App\Jobs;

use App\Mail\ScheduledAppointmentMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class ScheduledAppointmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $email;
    protected $data;

    public function __construct($email, $data)
    {
        $this->email = $email;
        $this->data = $data;
    }

    public function handle()
    {
        Mail::to($this->email)->send(new ScheduledAppointmentMail($this->data));
    }
}