<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\LlmDriverInterface;
use App\Service\Driver\OllamaDriver;
use App\Service\Driver\OpenAiDriver;
use Hyperf\Guzzle\ClientFactory;

class OllamaService
{
    private LlmDriverInterface $driver;

    public function __construct(ClientFactory $clientFactory)
    {
        // Defaut to 'openai' which we will use for Groq
        $driverType = getenv('LLM_DRIVER') ?: 'openai';

        if ($driverType === 'openai') {
            // OpenAI Client (Configured for Groq via .env)
            $client = $clientFactory->create([
                'timeout' => 120,
            ]);
            $this->driver = new OpenAiDriver($client);
        } else {
            // BLOCK LOCAL OLLAMA AS REQUESTED
            // Ollama Client
            // $client = $clientFactory->create([
            //     'base_uri' => 'http://host.docker.internal:11434',
            //     'timeout' => 120,
            // ]);
            // $this->driver = new OllamaDriver($client);
            
            // Fallback to OpenAI/Groq even if not specified, effectively disabling Ollama
             $client = $clientFactory->create([
                'timeout' => 120,
            ]);
            $this->driver = new OpenAiDriver($client);
        }
    }

    public function generateSql(string $prompt): string
    {
        return $this->driver->generateSql($prompt);
    }

    public function generateSelectSql(string $question, string $schema): string
    {
        return $this->driver->generateSelectSql($question, $schema);
    }

    public function generateManipulationSql(string $command, string $schema): string
    {
        return $this->driver->generateManipulationSql($command, $schema);
    }

    public function generateCode(string $prompt): string
    {
        return $this->driver->generateCode($prompt);
    }

    public function generateInsight(string $question, array $data): string
    {
        return $this->driver->generateInsight($question, $data);
    }

    public function chat(string $message): string
    {
        return $this->driver->chat($message);
    }

    public function chatStream(string $message): \Generator
    {
        return $this->driver->chatStream($message);
    }
}
