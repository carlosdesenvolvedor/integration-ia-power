<?php

declare(strict_types=1);

namespace App\Service\Driver;

use App\Contract\LlmDriverInterface;
use GuzzleHttp\Client;

class OpenAiDriver implements LlmDriverInterface
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->apiKey = getenv('OPENAI_API_KEY') ?: '';
        $this->model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
        $this->baseUrl = getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1';
    }

    public function generateSql(string $prompt): string
    {
        return $this->callChat([
            ['role' => 'system', 'content' => "You are a SQL expert. Output ONLY the MySQL CREATE TABLE statement. \nSTRICT RULES:\n1. No markdown allowed.\n2. No preamble or explanation.\n3. Return raw SQL only.\n4. Always create indices for foreign keys and frequent search columns."],
            ['role' => 'user', 'content' => "Description: " . $prompt]
        ]);
    }

    public function generateSelectSql(string $question, string $schema): string
    {
        return $this->callChat([
            ['role' => 'system', 'content' => "You are a MySQL expert. Output ONLY the SQL SELECT statement. \nSTRICT RULES:\n1. No markdown allowed.\n2. No preamble or explanation.\n3. Return raw SQL only.\n4. Always select * unless specified."],
            ['role' => 'user', 'content' => "Schema: {$schema}\nQuestion: {$question}"]
        ]);
    }

    public function generateManipulationSql(string $command, string $schema): string
    {
        $sql = $this->callChat([
            ['role' => 'system', 'content' => "You are a MySQL expert. Output ONLY the INSERT/UPDATE/DELETE statement. \nIMPORTANT RULES:\n1. No markdown.\n2. No loops/subqueries for INSERT. Use 'INSERT INTO (...) VALUES (...), (...)' syntax.\n3. No auto_increment columns in INSERT.\n4. Ensure correctness."],
            ['role' => 'user', 'content' => "Schema: {$schema}\nCommand: {$command}"]
        ]);

        // Remove trailing commas
        $sql = preg_replace('/,\s*$/', '', trim($sql));
        $sql = preg_replace('/,\s*;$/', ';', $sql);

        return $sql;
    }

    public function generateCode(string $prompt): string
    {
        return $this->callChat([
            ['role' => 'system', 'content' => "You are a coding assistant. Return only the code requested."],
            ['role' => 'user', 'content' => $prompt]
        ], 0.2);
    }

    public function generateInsight(string $question, array $data): string
    {
        // For Groq/OpenAI, sending full dataset as requested by user.
        // Be aware that extremely large datasets might exceed context window.
        $note = "";

        // Remove array slice logic to allow full data analysis
        // if (count($data) > 200) ...

        $dataJson = json_encode($data);
        
        return $this->callChat([
            ['role' => 'system', 'content' => "You are a data analyst. Answer the user's question based on the provided JSON data. \nRULES:\n1. Answer in Portuguese.\n2. Be direct and concise.\n3. DO NOT write Python/Pandas code.\n4. Just give the final answer/insight.\n5. [IMPORTANT] Be mathematically precise. When comparing prices/values, compare the numbers meticulously. Double-check your logic: Is 4500 > 3500? yes. Is 4000 > 4500? no. Do not hallucinate values."],
            ['role' => 'user', 'content' => "Data: {$dataJson}\n{$note}\nQuestion: {$question}"]
        ], 0.1);
    }

    public function chat(string $message): string
    {
        return $this->callChat([
            ['role' => 'system', 'content' => "Você é um analista de dados sênior. Responda em Português, de forma direta e concisa, no máximo 2 frases. Responda apenas ao que foi perguntado, sem explicações extras."],
            ['role' => 'user', 'content' => $message]
        ]);
    }

    public function chatStream(string $message): \Generator
    {
        // Fallback for OpenAI (Non-streaming for now, or simulate locally)
        $full = $this->chat($message);
        // Simulate chunks
        $words = explode(' ', $full);
        foreach ($words as $word) {
            yield $word . ' ';
            usleep(50000); // 50ms delay
        }
    }

    private function callChat(array $messages, float $temperature = 0.7): string
    {
        if (empty($this->apiKey)) {
            return "Erro: OPENAI_API_KEY não configurada.";
        }

        try {
            $url = rtrim($this->baseUrl, '/') . '/chat/completions';
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $content = $body['choices'][0]['message']['content'] ?? '';
            
            // Extract code block if Present
            if (preg_match('/```(?:sql|mysql)?\s*(.*?)```/s', $content, $matches)) {
                $content = $matches[1];
            }
            
            // Remove markdown code blocks leftovers if distinct format
            $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
            
            return trim($content);
        } catch (\Throwable $e) {
            return "Erro OpenAI: " . $e->getMessage();
        }
    }
}
