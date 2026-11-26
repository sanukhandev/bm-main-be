<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdentityVerificationService
{
    public function issue(?string $identityNo, User $user, int $branchId, string $customerType = 'individual'): ?string
    {
        if (! is_string($identityNo) || trim($identityNo) === '') {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'jti' => (string) Str::uuid(),
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'customer_type' => $customerType,
            'identity_hash' => hash('sha256', $this->normalize($identityNo)),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function assertValid(?string $token, ?string $identityNo, User $user, int $branchId, string $customerType = 'individual'): void
    {
        if (! $token || ! $identityNo) {
            throw ValidationException::withMessages(['identity_no' => 'A verified identity document scan is required for this verification.']);
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['identity_no' => 'The document verification has expired. Scan the document again.']);
        }

        $valid = ($payload['user_id'] ?? null) === $user->id
            && ($payload['branch_id'] ?? null) === $branchId
            && ($payload['customer_type'] ?? null) === $customerType
            && ($payload['expires_at'] ?? 0) >= now()->timestamp
            && hash_equals((string) ($payload['identity_hash'] ?? ''), hash('sha256', $this->normalize($identityNo)));

        if (! $valid) {
            throw ValidationException::withMessages(['identity_no' => 'The identity value does not match the verified scan.']);
        }

        if (! Cache::add('identity_verify:'.$payload['jti'], true, now()->addMinutes(15))) {
            throw ValidationException::withMessages(['identity_no' => 'This document verification has already been used. Scan the document again.']);
        }
    }

    public function release(?string $token): void
    {
        if (! $token) {
            return;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        if (is_string($payload['jti'] ?? null)) {
            Cache::forget('identity_verify:'.$payload['jti']);
        }
    }

    private function normalize(string $identityNo): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($identityNo)) ?? trim($identityNo));
    }
}
