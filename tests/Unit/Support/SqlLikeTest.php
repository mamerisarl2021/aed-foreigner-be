<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SqlLike;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SqlLikeTest extends TestCase
{
    #[Test]
    public function it_wraps_a_plain_substring(): void
    {
        $this->assertSame('%KOTO%', SqlLike::contains('KOTO'));
    }

    #[Test]
    public function it_escapes_like_wildcards_and_backslashes(): void
    {
        $this->assertSame('%100\\%%', SqlLike::contains('100%'));
        $this->assertSame('%a\\_b%', SqlLike::contains('a_b'));
        $this->assertSame('%c\\\\d%', SqlLike::contains('c\\d'));
    }
}
