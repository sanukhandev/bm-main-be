+<?php

use App\Support\IdentityNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('audit_logs')->select(['id', 'before_json', 'after_json', 'metadata_json'])->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $updates = [];
                foreach (['before_json', 'after_json', 'metadata_json'] as $column) {
                    if ($row->{$column} === null) {
                        continue;
                    }
                    try {
                        $decoded = is_array($row->{$column}) ? $row->{$column} : json_decode((string) $row->{$column}, true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        throw new RuntimeException("Invalid audit JSON at audit_logs.{$row->id}.{$column}.");
                    }
                    if (! is_array($decoded)) {
                        throw new RuntimeException("Unexpected audit JSON shape at audit_logs.{$row->id}.{$column}.");
                    }
                    $clean = $this->redact($decoded);
                    if ($clean !== $decoded) {
                        $updates[$column] = json_encode($clean, JSON_THROW_ON_ERROR);
                    }
                }
                if ($updates !== []) {
                    DB::table('audit_logs')->where('id', $row->id)->update($updates);
                }
            }
        });
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (in_array(strtolower((string) $key), ['identity_no', 'emirates_id'], true) && is_string($value)) {
                $data[$key] = IdentityNumber::mask($value);
            }
        }

        return $data;
    }

    public function down(): void
    {
        // Redacted identity values cannot be restored.
    }
};
