<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class IdentityVerificationService
{
    public function issue(?string $identityNo, User $user, int $branchId): ?string
    {
        if (! is_string($identityNo) || trim($identityNo) === '') {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'identity_hash' => hash('sha256', $this->normalize($identityNo)),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function assertValid(?string $token, ?string $identityNo, User $user, int $branchId): void
    {
        if (! $token || ! $identityNo) {
            throw ValidationException::withMessages(['identity_no' => 'A verified Emirates ID scan is required for this verification.']);
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['identity_no' => 'The identity verification has expired. Scan the document again.']);
        }

        $valid = ($payload['user_id'] ?? null) === $user->id
            && ($payload['branch_id'] ?? null) === $branchId
            && ($payload['expires_at'] ?? 0) >= now()->timestamp
            && hash_equals((string) ($payload['identity_hash'] ?? ''), hash('sha256', $this->normalize($identityNo)));

        if (! $valid) {
            throw ValidationException::withMessages(['identity_no' => 'The Emirates ID value does not match the verified scan.']);
        }
    }

    private function normalize(string $identityNo): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($identityNo)) ?? trim($identityNo));
    }
}
