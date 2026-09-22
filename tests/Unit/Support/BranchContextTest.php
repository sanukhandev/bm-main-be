<?php

namespace Tests\Unit\Support;

use App\Models\Branch;
use App\Support\Branch\BranchContext;
use LogicException;
use PHPUnit\Framework\TestCase;

class BranchContextTest extends TestCase
{
    public function test_context_starts_unresolved(): void
    {
        $context = new BranchContext;

        $this->assertFalse($context->isResolved());
        $this->expectException(LogicException::class);
        $context->id();
    }

    public function test_context_exposes_only_the_verified_branch(): void
    {
        $context = new BranchContext;
        $branch = new Branch;
        $branch->setRawAttributes(['id' => 42, 'code' => 'TEST']);
        $context->set($branch);

        $this->assertTrue($context->isResolved());
        $this->assertSame(42, $context->id());
        $this->assertSame($branch, $context->branch());
    }
}
