<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\StaffPasswordGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StaffPasswordGeneratorTest extends TestCase
{
    #[Test]
    public function meets_typical_keycloak_password_policy(): void
    {
        $allowedSpecial = '!@#$%&*-_';

        for ($i = 0; $i < 40; $i++) {
            $password = StaffPasswordGenerator::generate('ada.koto@example.com');

            $this->assertSame(16, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/['.preg_quote($allowedSpecial, '/').']/', $password);
            $this->assertDoesNotMatchRegularExpression('/[\\\\\'"<>{}[\]|\/^~`,;:()]/', $password);
            $this->assertStringNotContainsStringIgnoringCase('ada.koto', $password);
            $this->assertStringNotContainsStringIgnoringCase('example', $password);
        }
    }
}
