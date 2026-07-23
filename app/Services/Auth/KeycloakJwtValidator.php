<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class KeycloakJwtValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('JWT malformé.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);

        if (! is_array($header) || ! is_array($payload)) {
            throw new RuntimeException('JWT illisible.');
        }

        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new RuntimeException('JWT sans kid.');
        }

        $publicKey = $this->resolvePublicKey($kid);
        $signed = $headerB64.'.'.$payloadB64;
        $signature = $this->base64UrlDecode($signatureB64);

        $verified = openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Signature JWT invalide.');
        }

        $now = time();
        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            throw new RuntimeException('JWT expiré.');
        }

        $issuer = config('consul.keycloak.issuer');
        if ($issuer && isset($payload['iss']) && $payload['iss'] !== $issuer) {
            throw new RuntimeException('Émetteur JWT invalide.');
        }

        $audience = config('consul.keycloak.audience');
        if ($audience) {
            $aud = $payload['aud'] ?? null;
            $audiences = is_array($aud) ? $aud : [$aud];
            if (! in_array($audience, $audiences, true)) {
                throw new RuntimeException('Audience JWT invalide.');
            }
        }

        return $payload;
    }

    private function resolvePublicKey(string $kid): \OpenSSLAsymmetricKey
    {
        $jwksUri = config('consul.keycloak.jwks_uri');
        if (! is_string($jwksUri) || $jwksUri === '') {
            throw new RuntimeException('KC_INFRA_JWKS non configuré.');
        }

        $jwks = Cache::remember('keycloak.jwks', now()->addHour(), function () use ($jwksUri): array {
            $response = Http::get($jwksUri);
            $response->throw();

            return $response->json();
        });

        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) !== $kid) {
                continue;
            }

            $pem = $this->jwkToPem($key);
            $publicKey = openssl_pkey_get_public($pem);
            if ($publicKey === false) {
                break;
            }

            return $publicKey;
        }

        Cache::forget('keycloak.jwks');
        throw new RuntimeException('Clé JWKS introuvable.');
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function jwkToPem(array $jwk): string
    {
        $n = $this->base64UrlDecode((string) ($jwk['n'] ?? ''));
        $e = $this->base64UrlDecode((string) ($jwk['e'] ?? ''));

        $modulus = $this->encodeInteger($n);
        $exponent = $this->encodeInteger($e);
        $sequence = $this->encodeSequence($modulus.$exponent);
        $bitString = "\x00".$sequence;
        $bitStringEncoded = "\x03".$this->encodeLength(strlen($bitString)).$bitString;
        $rsaOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $null = "\x05\x00";
        $algorithmIdentifier = $this->encodeSequence($rsaOid.$null);
        $subjectPublicKeyInfo = $this->encodeSequence($algorithmIdentifier.$bitStringEncoded);
        $pemBody = chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$pemBody}-----END PUBLIC KEY-----\n";
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Décodage base64url impossible.');
        }

        return $decoded;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function encodeInteger(string $value): string
    {
        if ($value !== '' && (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->encodeLength(strlen($value)).$value;
    }

    private function encodeSequence(string $value): string
    {
        return "\x30".$this->encodeLength(strlen($value)).$value;
    }
}
