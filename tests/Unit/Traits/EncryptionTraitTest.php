<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Traits\EncryptionTrait;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EncryptionTraitTest extends TestCase
{
    #[Test]
    public function get_enc_file_decrypts_random_iv_prefixed_ciphertext(): void
    {
        $helper = $this->trait();
        $filename = 'enc-prefixed-'.Str::random(8).'.bin';
        $tmp = $this->writePlainTmp('prefixed-plain');

        try {
            $helper->storeEncFile($filename, $tmp, 'docs');

            $this->assertSame(
                base64_encode('prefixed-plain'),
                $helper->getEncFile($filename, 'docs'),
            );
        } finally {
            @unlink($tmp);
            @unlink($this->docsPath($filename));
        }
    }

    #[Test]
    public function get_enc_file_decrypts_legacy_ciphertext_with_configured_iv(): void
    {
        $this->putEncryptionKey();
        $helper = $this->trait();
        $filename = 'enc-legacy-'.Str::random(8).'.bin';
        $legacyIv = (string) config('encryption.legacy_cbc_iv');
        $this->assertSame(16, strlen($legacyIv));

        $cipher = openssl_encrypt(
            'legacy-plain',
            'AES-256-CBC',
            (string) file_get_contents(storage_path('aed-public.key')),
            OPENSSL_RAW_DATA,
            $legacyIv,
        );
        $this->assertNotFalse($cipher);

        $this->ensureDocsDir();
        file_put_contents($this->docsPath($filename), $cipher);

        try {
            $this->assertSame(
                base64_encode('legacy-plain'),
                $helper->getEncFile($filename, 'docs'),
            );
        } finally {
            @unlink($this->docsPath($filename));
        }
    }

    private function trait(): object
    {
        $this->putEncryptionKey();
        $this->ensureDocsDir();

        return new class
        {
            use EncryptionTrait;
        };
    }

    private function putEncryptionKey(): void
    {
        file_put_contents(storage_path('aed-public.key'), str_repeat('a', 32));
    }

    private function ensureDocsDir(): void
    {
        $docsDir = dirname($this->docsPath('x'));
        if (! is_dir($docsDir)) {
            mkdir($docsDir, 0775, true);
        }
    }

    private function docsPath(string $filename): string
    {
        return Storage::path('public/docs/'.$filename);
    }

    private function writePlainTmp(string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'enc');
        file_put_contents($tmp, $contents);

        return $tmp;
    }
}
