<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class SecurityQuestions
{
    /**
     * @param  array<int, array<string, mixed>>  $pairs
     * @return list<array{question: string, answer_hash: string}>
     */
    public static function persist(array $pairs): array
    {
        $stored = [];

        foreach ($pairs as $pair) {
            $question = self::stringValue($pair['question'] ?? null);
            $answer = self::stringValue($pair['answer'] ?? null);

            if ($question === '') {
                throw new InvalidArgumentException('Les questions de sécurité ne peuvent pas être vides.');
            }

            $normalized = self::normalizeAnswer($answer);
            if ($normalized === '') {
                throw new InvalidArgumentException('Les réponses de sécurité ne peuvent pas être vides.');
            }

            $stored[] = [
                'question' => $question,
                'answer_hash' => Hash::make($normalized),
            ];
        }

        return $stored;
    }

    /**
     * @param  array<int, mixed>|null  $stored
     * @return list<array{question: string}>
     */
    public static function questionsForDisplay(?array $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $questions = [];
        foreach ($stored as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = self::stringValue($item['question'] ?? null);
            if ($question !== '') {
                $questions[] = ['question' => $question];
            }
        }

        return $questions;
    }

    /**
     * @param  array<int, mixed>|null  $stored
     */
    public static function configured(?array $stored): bool
    {
        return self::questionsForDisplay($stored) !== [];
    }

    public static function answerMatches(string $plain, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return Hash::check(self::normalizeAnswer($plain), $hash);
    }

    /**
     * Rewrite a stored JSON payload to `{ question, answer_hash }` (idempotent).
     *
     * @param  list<mixed>|null  $stored
     * @return list<array{question: string, answer_hash: string}>|null
     */
    public static function migrateStored(?array $stored): ?array
    {
        if (! is_array($stored) || $stored === []) {
            return $stored;
        }

        $migrated = [];
        foreach ($stored as $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = self::migrateStoredItem($item);
            if ($row !== null) {
                $migrated[] = $row;
            }
        }

        return $migrated === [] ? null : $migrated;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{question: string, answer_hash: string}|null
     */
    private static function migrateStoredItem(array $item): ?array
    {
        $question = self::stringValue($item['question'] ?? null);
        if ($question === '') {
            return null;
        }

        $existingHash = self::stringValue($item['answer_hash'] ?? null);
        if ($existingHash !== '') {
            return [
                'question' => $question,
                'answer_hash' => $existingHash,
            ];
        }

        $answer = self::stringValue($item['answer'] ?? null);
        if ($answer === '') {
            return null;
        }

        if (Hash::isHashed($answer)) {
            return [
                'question' => $question,
                'answer_hash' => $answer,
            ];
        }

        $normalized = self::normalizeAnswer($answer);
        if ($normalized === '') {
            return null;
        }

        return [
            'question' => $question,
            'answer_hash' => Hash::make($normalized),
        ];
    }

    public static function normalizeAnswer(string $plain): string
    {
        return mb_strtolower(trim($plain), 'UTF-8');
    }

    private static function stringValue(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }
}
