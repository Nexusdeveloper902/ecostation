<?php

namespace App\Console\Commands;

use App\Services\Recycling\ClassificationException;
use App\Services\Recycling\Drivers\DeepSeekClassifier;
use Illuminate\Console\Command;

/**
 * One live DeepSeek call: settles whether json_object + thinking:disabled
 * combine with image input, and names any configuration failure.
 */
class LlmCheckCommand extends Command
{
    protected $signature = 'llm-check';

    protected $description = 'Hace una llamada real a DeepSeek y diagnostica la configuración';

    public function handle(): int
    {
        $apiKey = config('ecostation.classifier.deepseek.api_key');

        if (empty($apiKey)) {
            $this->error('DEEPSEEK_API_KEY no está configurado. Pon una clave en .env y vuelve a intentar.');

            return 1;
        }

        $driver = new DeepSeekClassifier(
            (string) $apiKey,
            (string) config('ecostation.classifier.deepseek.model', 'deepseek-flash'),
            (float) config('ecostation.classifier.deepseek.timeout', 15),
        );

        try {
            $result = $driver->classify(base_path('tests/Fixtures/bottle.jpg'));
        } catch (ClassificationException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info("OK: {$result['material_class']} ({$result['confidence']})");
        $this->line('json_object + thinking:disabled + imagen: compatible');

        return 0;
    }
}
