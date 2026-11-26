<?php

namespace Tests\Unit\Support;

use App\Support\SqlLike;
use PHPUnit\Framework\TestCase;

class SqlLikeTest extends TestCase
{
    public function test_contains_escapes_like_metacharacters(): void
    {
        $this->assertSame('%100!%!_!!%', SqlLike::contains('100%_!'));
    }
}
