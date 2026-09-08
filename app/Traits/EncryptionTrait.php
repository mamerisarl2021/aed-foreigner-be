<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\Storage;
use phpseclib3\Crypt\RSA;
use RuntimeException;

trait EncryptionTrait
{
    /**
     * The storage location of the encryption keys.
     */
    public static ?string $keyPath = null;

    /**
     * The location of the encryption keys.
     */
    public static function keyPath(string $file): string
    {
        $file = ltrim($file, '/\\');

        return static::$keyPath
            ? rtrim(static::$keyPath, '/\\').DIRECTORY_SEPARATOR.$file
            : storage_path($file);
    }

    public function generate(): int
    {
        [$publicKey, $privateKey] = [
            $this->keyPath('aed-public.key'),
            $this->keyPath('aed-private.key'),
        ];
        $key = RSA::createKey(4096);

        file_put_contents($publicKey, (string) $key->getPublicKey());
        file_put_contents($privateKey, (string) $key);

        return 0;
    }

    public function getKey(): int
    {
        return $this->generate();
    }

    public function storeEncFile(string $fileName, string $filePath, string $folder): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read file: {$filePath}");
        }

        $key = $this->encryptionKey();
        $method = 'AES-256-CBC';
        $iv = random_bytes(16);
        $encryptedData = openssl_encrypt($content, $method, $key, OPENSSL_RAW_DATA, $iv);
        if ($encryptedData === false) {
            throw new RuntimeException('Unable to encrypt file.');
        }

        $path = Storage::path("public/$folder/$fileName");
        file_put_contents($path, $iv.$encryptedData);

        return $fileName;
    }

    public function storeFile(string $fileName, string $filePath, string $folder): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read file: {$filePath}");
        }

        $path = Storage::path("public/$folder/$fileName");
        file_put_contents($path, $content);

        return $fileName;
    }

    public function getEncFile(string $filename, string $folder): string
    {
        $encryptedFileFullPath = Storage::path("public/$folder/$filename");
        $encryptedContent = file_get_contents($encryptedFileFullPath);
        if ($encryptedContent === false) {
            return '';
        }

        $key = $this->encryptionKey();
        $method = 'AES-256-CBC';

        if (strlen($encryptedContent) > 16) {
            $iv = substr($encryptedContent, 0, 16);
            $decryptedContent = openssl_decrypt(substr($encryptedContent, 16), $method, $key, OPENSSL_RAW_DATA, $iv);
            if ($decryptedContent !== false) {
                return base64_encode($decryptedContent);
            }
        }

        $legacyIv = (string) config('encryption.legacy_cbc_iv');
        if (strlen($legacyIv) !== 16) {
            return base64_encode('');
        }

        $decryptedContent = openssl_decrypt($encryptedContent, $method, $key, OPENSSL_RAW_DATA, $legacyIv);

        return base64_encode($decryptedContent === false ? '' : $decryptedContent);
    }

    private function encryptionKey(): string
    {
        $key = file_get_contents(storage_path('aed-public.key'));
        if ($key === false) {
            throw new RuntimeException('Encryption key missing.');
        }

        return $key;
    }

    public function deleteDirectory(string $folderTemporary): bool
    {
        return Storage::deleteDirectory("public/$folderTemporary");
    }
}
