<?php

namespace Tests\Unit\Services;

use App\Services\Zaakiy\IntentAnalyzer;
use App\Services\Zaakiy\SensitiveDataFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZaakiyIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_and_outstanding_question_creates_a_multi_skill_frame(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('Which tenant agreements expire in the next 30 days and still owe money?');

        $this->assertSame('agreement.expiring_with_outstanding', $frame->intent);
        $this->assertSame(['agreements', 'accounts'], $frame->modules);
        $this->assertSame(['from' => now()->toDateString(), 'to' => now()->addDays(30)->toDateString()], $frame->timeRange);
    }

    public function test_follow_up_uses_previous_question_only_for_intent_resolution(): void
    {
        $frame = app(IntentAnalyzer::class)->analyze('What about next month?', [
            ['role' => 'user', 'text' => 'Which tenant agreements expire this month?'],
        ]);

        $this->assertSame('agreement.expiring', $frame->intent);
        $this->assertContains('agreements', $frame->modules);
    }

    public function test_sensitive_evidence_fields_are_removed(): void
    {
        $clean = app(SensitiveDataFilter::class)->clean([
            'amount' => '100.00',
            'password' => 'hidden',
            'metadata' => ['api_token' => 'hidden', 'label' => 'safe'],
        ]);

        $this->assertSame(['amount' => '100.00', 'metadata' => ['label' => 'safe']], $clean);
    }
}
