<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlmCheckCommandTest extends TestCase
{
    #[Test]
    public function refuses_without_a_key(): void
    {
        config(['ecostation.classifier.deepseek.api_key' => null]);

        $exit = Artisan::call('llm-check');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('DEEPSEEK_API_KEY', Artisan::output());
    }

    #[Test]
    public function reports_ok_on_a_faked_live_call(): void
    {
        config(['ecostation.classifier.deepseek.api_key' => 'test-key']);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'material_class' => 'plastic',
                    'confidence' => 0.93,
                    'is_bottle' => true,
                    'is_recyclable' => true,
                ])]]],
            ], 200),
        ]);

        $exit = Artisan::call('llm-check');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('OK:', $output);
        $this->assertStringContainsString('json_object + thinking:disabled + imagen: compatible', $output);
    }
}
