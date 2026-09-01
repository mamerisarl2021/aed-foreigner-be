<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SecurityQuestions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityQuestionsTest extends TestCase
{
    #[Test]
    public function persist_stores_hashes_and_drops_plaintext_answers(): void
    {
        $stored = SecurityQuestions::persist([
            ['question' => 'Ville de naissance ?', 'answer' => 'Cotonou'],
            ['question' => 'Animal ?', 'answer' => 'chat'],
        ]);

        $this->assertCount(2, $stored);
        $this->assertSame('Ville de naissance ?', $stored[0]['question']);
        $this->assertArrayNotHasKey('answer', $stored[0]);
        $this->assertTrue(SecurityQuestions::answerMatches('Cotonou', $stored[0]['answer_hash']));
        $this->assertTrue(SecurityQuestions::answerMatches(' cotonou ', $stored[0]['answer_hash']));
        $this->assertFalse(SecurityQuestions::answerMatches('Porto-Novo', $stored[0]['answer_hash']));
    }

    #[Test]
    public function persist_rejects_blank_answers_after_trim(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Les réponses de sécurité ne peuvent pas être vides.');

        SecurityQuestions::persist([
            ['question' => 'Ville ?', 'answer' => '   '],
        ]);
    }

    #[Test]
    public function questions_for_display_omit_secrets(): void
    {
        $display = SecurityQuestions::questionsForDisplay([
            ['question' => 'Ville de naissance ?', 'answer' => 'Cotonou', 'answer_hash' => 'x'],
        ]);

        $this->assertSame([['question' => 'Ville de naissance ?']], $display);
        $this->assertTrue(SecurityQuestions::configured([
            ['question' => 'Ville de naissance ?', 'answer_hash' => 'x'],
        ]));
        $this->assertFalse(SecurityQuestions::configured(null));
        $this->assertFalse(SecurityQuestions::configured([]));
    }

    #[Test]
    public function migrate_stored_hashes_plaintext_and_is_idempotent(): void
    {
        $first = SecurityQuestions::migrateStored([
            ['question' => 'Ville de naissance ?', 'answer' => 'Cotonou'],
        ]);

        $this->assertNotNull($first);
        $this->assertArrayNotHasKey('answer', $first[0]);
        $this->assertTrue(SecurityQuestions::answerMatches('Cotonou', $first[0]['answer_hash']));

        $second = SecurityQuestions::migrateStored($first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function migrate_stored_renames_a_hashed_answer_key(): void
    {
        $alreadyHashed = SecurityQuestions::persist([
            ['question' => 'Ville ?', 'answer' => 'Cotonou'],
        ]);

        $legacy = [
            ['question' => 'Ville ?', 'answer' => $alreadyHashed[0]['answer_hash']],
        ];

        $migrated = SecurityQuestions::migrateStored($legacy);
        $this->assertNotNull($migrated);
        $this->assertSame($alreadyHashed[0]['answer_hash'], $migrated[0]['answer_hash']);
        $this->assertArrayNotHasKey('answer', $migrated[0]);
    }
}
