<?php

namespace App\Traits;

use Illuminate\Support\Facades\Storage;
use phpseclib3\Crypt\RSA;

trait EncryptionTrait
{
    /**
     * The storage location of the encryption keys.
     *
     * @var string
     */
    public static $keyPath;

    /**
     * The location of the encryption keys.
     *
     * @param  string  $file
     * @return string
     */
    public static function keyPath($file)
    {
        $file = ltrim($file, '/\\');

        return static::$keyPath
            ? rtrim(static::$keyPath, '/\\').DIRECTORY_SEPARATOR.$file
            : storage_path($file);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function generate()
    {
        [$publicKey, $privateKey] = [
            $this->keyPath('aed-public.key'),
            $this->keyPath('aed-private.key'),
        ];
        $key = RSA::createKey(4096);

        file_put_contents($publicKey, (string) $key->getPublicKey());
        file_put_contents($privateKey, (string) $key);

        echo 'Clés générées avec succès';

        return 0;
    }

    public function getKey()
    {
        return $this->generate();
    }

    public function storeEncFile($fileName, $filePath, $folder)
    {
        // Lire le contenu du fichier
        $content = file_get_contents($filePath);
        $publicKey = file_get_contents(storage_path('aed-public.key'));
        // The encryption method
        $method = 'AES-256-CBC';
        // The IV
        $iv = '4921a67c51de4c8b';
        // Créer une instance RSA ave// The encrypted data
        $encryptedData = openssl_encrypt($content, $method, $publicKey, OPENSSL_RAW_DATA, $iv);
        $path = Storage::path("public/$folder/$fileName");
        file_put_contents($path, $encryptedData);

        return $fileName;
    }

    public function storeFile($fileName, $filePath, $folder)
    {
        // Lire le contenu du fichier
        $content = file_get_contents($filePath);
        $path = Storage::path("public/$folder/$fileName");
        file_put_contents($path, $content);

        return $fileName;
    }

    public function getEncFile(string $filename, string $folder)
    {
        // Chemin complet vers le fichier chiffré
        $encryptedFileFullPath = Storage::path("public/$folder/$filename");
        // Lire le contenu chiffré du fichier
        $encryptedContent = file_get_contents($encryptedFileFullPath);

        // Charger la clé privée
        $privateKey = file_get_contents(storage_path('aed-public.key'));
        // Déchiffrer le contenu avec la clé privée
        $method = 'AES-256-CBC';
        // The IV
        $iv = '4921a67c51de4c8b';
        // Créer une instance RSA ave// The encrypted data
        $decryptedContent = openssl_decrypt($encryptedContent, $method, $privateKey, OPENSSL_RAW_DATA, $iv);

        // Encode the decrypted content to base64
        return base64_encode($decryptedContent);
    }

    public function deleteDirectory($folderTemporary)
    {
        return Storage::deleteDirectory("public/$folderTemporary");
    }
}
