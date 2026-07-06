<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConsulRegister extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'consul-register';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = env('CONSUL_SERVICE_NAME', 'portal-id-example');
        $ip = env('CONSUL_SERVICE_IP', gethostbyname(gethostname()));
        $port = (int)env('CONSUL_SERVICE_PORT', 8000);
        $id = $name.'-'.\Str::random(8);

        // token ACL Consul (déjà obtenu via keycloack)
        $token = env('CONSUL_HTTP_TOKEN', "");
        $base = env("CONSUL_URL", "http://localhost:8500");

        \Http::withHeaders(['X-Consul-Token' => $token])
            -> put("$base/v1/agent/service/register",[
                'ID' => $id,
                'Name' => $name,
                'Address' => $ip,
                'Port' => $port,
                'Tags' => ['asin', 'v1', 'laravel'],
                'Check' => [
                    'HTTP' => "http://$ip:$port/api/health",
                    'interval' => '10s', 'Timeout' => '2s',
                    'DeregisterCriticalServiceAfter'=>'1m'],
            ]);
        $this->info("Enregistré: $id");
    }
}
