<?php

namespace App\Services\Zaakiy;

class SensitiveDataFilter
{
    private const BLOCKED = ['password', 'password_confirmation', 'remember_token', 'token', 'api_key', 'secret', 'authorization', 'cookie', 'stack_trace'];

    public function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $normalized = mb_strtolower((string) $key);
                if (collect(self::BLOCKED)->contains(fn (string $blocked) => str_contains($normalized, $blocked))) {
                    continue;
                }
                $clean[$key] = $this->clean($item);
            }

            return $clean;
        }
        if ($value instanceof \JsonSerializable) {
            return $this->clean($value->jsonSerialize());
        }

        return is_scalar($value) || $value === null ? $value : (string) $value;
    }
}
