<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\JsonbText;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class JsonbTextTest extends TestCase
{
    #[Test]
    public function it_builds_a_single_key_postgres_text_expression(): void
    {
        $this->assertSame(
            "kyc_data->>'registration_number'",
            JsonbText::textExpression('kyc_data', '$.registration_number'),
        );
        $this->assertSame(
            "UPPER(TRIM(kyc_data->>'registration_number')) = ?",
            JsonbText::upperTrimEqualsSql('kyc_data', '$.registration_number'),
        );
    }

    #[Test]
    public function it_builds_a_nested_postgres_path(): void
    {
        $this->assertSame(
            "proof#>>'{company,registration_number}'",
            JsonbText::textExpression('proof', '$.company.registration_number'),
        );
    }

    #[Test]
    public function it_rejects_unknown_columns_and_paths(): void
    {
        $this->assertNull(JsonbText::textExpression('documents', '$.name'));
        $this->assertNull(JsonbText::textExpression('kyc_data', '$.oh-no'));
        $this->assertNull(JsonbText::upperTrimEqualsSql('kyc_data', '$'));
    }
}
