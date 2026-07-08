<?php

namespace App\Support;

use InvalidArgumentException;

final class NotificationRecipient
{
    public static function resolveEmail(mixed $recipient): string
    {
        if (is_string($recipient)) {
            return $recipient;
        }

        if (is_object($recipient) && method_exists($recipient, 'routeNotificationForMail')) {
            $email = $recipient->routeNotificationForMail();

            if (is_string($email) && $email !== '') {
                return $email;
            }
        }

        if (is_object($recipient) && isset($recipient->email) && is_string($recipient->email)) {
            return $recipient->email;
        }

        throw new InvalidArgumentException('Impossible de déterminer le destinataire de l\'e-mail de notification.');
    }

    public static function email(mixed $recipient, array $personalVariables = []): array
    {
        $entry = ['email' => self::resolveEmail($recipient)];

        if ($personalVariables !== []) {
            $entry['personalVariables'] = $personalVariables;
        }

        return $entry;
    }

    public static function phone(string $phoneNumber, array $personalVariables = []): array
    {
        $entry = ['phoneNumber' => $phoneNumber];

        if ($personalVariables !== []) {
            $entry['personalVariables'] = $personalVariables;
        }

        return $entry;
    }
}
