<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Traits\EncryptionTrait;
use Illuminate\Console\Command;

class GenerateKeyPair extends Command
{
    use EncryptionTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pair:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cette commande génère les pairs de clés';

    public function handle(): int
    {
        $result = $this->generate();
        $this->info('Clés générées avec succès');

        return $result;
    }
}
