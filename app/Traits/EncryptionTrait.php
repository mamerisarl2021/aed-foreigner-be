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
        $content = file_get_contents($filePath);
        $key = $this->encryptionKey();
        $method = 'AES-256-CBC';
        // Random IV per file, prepended to the ciphertext (new format).
        $iv = random_bytes(16);
        $encryptedData = openssl_encrypt($content, $method, $key, OPENSSL_RAW_DATA, $iv);
        $path = Storage::path("public/$folder/$fileName");
        file_put_contents($path, $iv.$encryptedData);

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
        $encryptedFileFullPath = Storage::path("public/$folder/$filename");
        $encryptedContent = file_get_contents($encryptedFileFullPath);

        $key = $this->encryptionKey();
        $method = 'AES-256-CBC';

        // New format: the first 16 bytes are the random IV.
        if (strlen($encryptedContent) > 16) {
            $iv = substr($encryptedContent, 0, 16);
            $decryptedContent = openssl_decrypt(substr($encryptedContent, 16), $method, $key, OPENSSL_RAW_DATA, $iv);
            if ($decryptedContent !== false) {
                return base64_encode($decryptedContent);
            }
        }

        // Legacy format: whole file encrypted with a fixed IV (files stored before the crypto fix).
        $decryptedContent = openssl_decrypt($encryptedContent, $method, $key, OPENSSL_RAW_DATA, '4921a67c51de4c8b');

        return base64_encode($decryptedContent === false ? '' : $decryptedContent);
    }

    private function encryptionKey(): string
    {
        return (string) file_get_contents(storage_path('aed-public.key'));
    }

    public function deleteDirectory($folderTemporary)
    {
        return Storage::deleteDirectory("public/$folderTemporary");
    }
}
