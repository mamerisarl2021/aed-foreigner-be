<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EnrollmentRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnrollmentRequestContactVerificationTest extends TestCase
{
    #[Test]
    public function physique_counts_as_verified_even_without_timestamps(): void
    {
        $enrollment = new EnrollmentRequest(['type' => 'PERSONNE_PHYSIQUE']);

        $this->assertTrue($enrollment->isEmailVerified());
        $this->assertTrue($enrollment->isPhoneVerified());
        $this->assertFalse($enrollment->isContactVerificationComplete());
    }

    #[Test]
    public function morale_requires_timestamps(): void
    {
        $enrollment = new EnrollmentRequest(['type' => 'PERSONNE_MORALE']);

        $this->assertFalse($enrollment->isEmailVerified());
        $this->assertFalse($enrollment->isPhoneVerified());

        $enrollment->email_verified_at = now();
        $enrollment->phone_verified_at = now();

        $this->assertTrue($enrollment->isEmailVerified());
        $this->assertTrue($enrollment->isPhoneVerified());
        $this->assertTrue($enrollment->isContactVerificationComplete());
    }
}
