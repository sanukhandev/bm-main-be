<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\IdentityVerificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IdentityVerificationServiceTest extends TestCase
{
    public function test_failed_create_can_release_nonce_for_a_safe_retry(): void
    {
        Cache::flush();
        $service = app(IdentityVerificationService::class);
        $user = new User;
        $user->id = 7;
        $token = $service->issue('784-1990-1234567-1', $user, 3);

        $service->assertValid($token, '784-1990-1234567-1', $user, 3);
        $service->release($token);
        $service->assertValid($token, '784-1990-1234567-1', $user, 3);

        $this->expectException(ValidationException::class);
        $service->assertValid($token, '784-1990-1234567-1', $user, 3);
    }
}
