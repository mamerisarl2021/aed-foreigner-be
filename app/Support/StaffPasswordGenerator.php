<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Passwords accepted by typical Keycloak realm policies (length, mixed case,
 * digit, special) without characters that often fail validation or copy/paste.
 */
final class StaffPasswordGenerator
{
    private const LENGTH = 16;

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    private const SPECIAL = '!@#$%&*-_';

    public static function generate(?string $email = null): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $chars = [
                self::pick(self::UPPER),
                self::pick(self::LOWER),
                self::pick(self::DIGITS),
                self::pick(self::SPECIAL),
            ];
            $pool = self::UPPER.self::LOWER.self::DIGITS.self::SPECIAL;
            while (count($chars) < self::LENGTH) {
                $chars[] = self::pick($pool);
            }

            $password = implode('', self::shuffle($chars));
            if ($email === null || ! self::containsIdentity($password, $email)) {
                return $password;
            }
        }

        throw new RuntimeException('Impossible de générer un mot de passe compatible Keycloak.');
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    /**
     * @param  list<string>  $chars
     * @return list<string>
     */
    private static function shuffle(array $chars): array
    {
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return $chars;
    }

    private static function containsIdentity(string $password, string $email): bool
    {
        $haystack = strtolower($password);
        $local = strtolower((string) strstr($email, '@', true));
        if (strlen($local) >= 3 && str_contains($haystack, $local)) {
            return true;
        }

        $domain = strtolower((string) strstr($email, '@'));
        $domain = ltrim($domain, '@');
        $domainLabel = explode('.', $domain)[0];

        return strlen($domainLabel) >= 3 && str_contains($haystack, $domainLabel);
    }
}
