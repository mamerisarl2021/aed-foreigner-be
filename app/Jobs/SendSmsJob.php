<?php

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $phoneNumber;
    public $msg;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $retryAfter = 60; // 60 seconds

    /**
     * Create a new job instance.
     *
     * @param string $phoneNumber
     * @param string $msg
     * @return void
     */
    public function __construct(string $phoneNumber, string $msg)
    {
        $this->phoneNumber = $phoneNumber;
        $this->msg = $msg;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // try {
        //     $response = Http::withHeaders([
        //         'Content-Type' => 'application/x-www-form-urlencoded',
        //         'Cookie' => 'SERVERID=A'
        //     ])->asForm()->post('https://api-public-2.mtarget.fr/messages', [
        //         'username' => 'username',
        //         'password' => 'password',
        //         'msisdn' => $this->phoneNumber,
        //         'msg' => $this->msg
        //     ]);

        //     if (!$response->successful()) {
        //         Log::error('Failed to send SMS: ' . $response->body());
        //     }
        // } catch (\Exception $e) {
        //     Log::error('Failed to send SMS: ' . $e->getMessage());
        //     throw $e;
        // }
        $sid = env('TWILIO_SID');
        $token = env('TWILIO_TOKEN');
        $fromNumber = env('TWILIO_FROM');

        // $sid = "ACf8ca0c4dc229b691d304635f89ddd649";
        // $token = "4d2868737443d5b2653132448ead290a";
        // $fromNumber = "+15744061397";


        try {
            $client = new Client($sid, $token);
            $client->messages->create("+229" . $this->phoneNumber, [
                'from' => $fromNumber,
                'body' => $this->msg
            ]);
            return 'SMS Sent Successfully.';
        } catch (Exception $e) {
            Log::error('Failed to send SMS: ' . $e);
            throw $e->getMessage();
        }
    }
}
