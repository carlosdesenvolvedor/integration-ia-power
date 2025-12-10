<?php

declare(strict_types=1);

namespace App\Service\Driver;

use App\Contract\LlmDriverInterface;
use GuzzleHttp\Client;

class OllamaDriver implements LlmDriverInterface
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function generateSql(string $prompt): string
    {
        return $this->callGenerate([
            'model' => getenv('OLLAMA_MODEL') ?: 'llama3',
            'prompt' => "You are a SQL expert. Generate a MySQL CREATE TABLE statement for the following description. Output ONLY the SQL code, nothing else. \nIMPORTANT: Always create indices (KEY or INDEX) for any foreign keys and for columns that are likely to be used in WHERE clauses or joins.\nDescription: " . $prompt,
            'stream' => false,
        ]);
    }

    public function generateSelectSql(string $question, string $schema): string
    {
        $prompt = "You are a MySQL expert. Generate a SELECT statement to answer the question: \"{$question}\".\n\nThe table schema is:\n{$schema}\n\nIMPORTANT: Always use 'SELECT *' to retrieve full context for the answer, unless specific columns are explicitly requested. Do not limit columns unnecessarily.\nOutput ONLY the SQL code, nothing else. Do not include markdown formatting.";

        return $this->callGenerate([
            'model' => getenv('OLLAMA_MODEL') ?: 'llama3',
            'prompt' => $prompt,
            'stream' => false,
        ]);
    }

    public function generateManipulationSql(string $command, string $schema): string
    {
        $prompt = "Given the following database schema:\n\n" . $schema . "\n\nGenerate a MySQL INSERT, UPDATE, or DELETE statement to execute the following command: \"" . $command . "\". \n\nIMPORTANT RULES:\n1. Output ONLY the SQL code.\n2. Do NOT use complex subqueries, loops, or stored procedures for INSERT.\n3. Use standard 'INSERT INTO table (columns) VALUES (val1), (val2)...' syntax for multiple rows.\n4. If generating dummy data, use explicit hardcoded values in a single INSERT statement with multiple rows.\n5. DO NOT provide values for columns marked as 'auto_increment' in the schema.\n6. CRITICAL: Ensure EVERY row in the VALUES list has the EXACT same number of parameters as the target columns list.\n7. Verify that the LAST item in the VALUES list does NOT have a trailing comma.\n8. Do not formatting with markdown.";

        $sql = $this->callGenerate([
            'model' => getenv('OLLAMA_MODEL') ?: 'llama3',
            'prompt' => $prompt,
            'stream' => false,
        ]);
        
        // Remove trailing commas in VALUES list like `... (1, 'A'),;` or `... (1, 'A'),`
        // We look for a comma followed by whitespace and then EOF or semicolon
        $sql = preg_replace('/,\s*$/', '', trim($sql));
        $sql = preg_replace('/,\s*;$/', ';', $sql);
        
        return $sql;
    }

    public function generateCode(string $prompt): string
    {
        return $this->callGenerate([
            'model' => getenv('OLLAMA_MODEL') ?: 'llama3',
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.2,
            ]
        ]);
    }

    public function generateInsight(string $question, array $data): string
    {
        // Limit data size to avoid massive payloads (slowness)
        // Keep only first 15 items to ensure prompt fits in context window and processes quickly
        if (count($data) > 15) {
            $data = array_slice($data, 0, 15);
            $note = "[Note: Data truncated to first 15 rows for speed.]";
        } else {
            $note = "";
        }

        // Ensure we handle 'tables' structure if present (multi-table insight)
        if (isset($data['tables']) && is_array($data['tables'])) {
            foreach ($data['tables'] as &$table) {
                if (isset($table['data']['rows']) && count($table['data']['rows']) > 10) {
                     $table['data']['rows'] = array_slice($table['data']['rows'], 0, 10);
                }
            }
        }

        $dataJson = json_encode($data);
        // Truncate huge JSON strings if they still exceed limits (safety net)
        if (strlen($dataJson) > 6000) {
            $dataJson = substr($dataJson, 0, 6000) . "... [TRUNCATED]";
        }

        $prompt = "Analyze the following data: {$dataJson}\n\n{$note}\nTo answer the question: \"{$question}\".\n\nAnswer in Portuguese. Be concise and direct.";

        return $this->callGenerate([
            'model' => getenv('OLLAMA_MODEL') ?: 'llama3',
            'prompt' => $prompt,
            'stream' => false,
        ]);
    }

    public function chat(string $message): string
    {
        return $this->callGenerate([
            'model' => getenv('OLLAMA_MODEL') ?: 'llama3',
            'prompt' => "You are a senior data analyst. Answer in Portuguese. Seja direta e objetiva, no máximo 2 frases. Responda apenas ao que foi perguntado, sem explicações extras.\n\nPergunta: " . $message,
            'stream' => false,
        ]);
    }

    public function chatStream(string $message): \Generator
    {
        $response = $this->client->post('/api/generate', [
            'json' => [
                'model' => getenv('OLLAMA_MODEL') ?: 'llama3',
                'prompt' => "You are a senior data analyst. Responda em Português, direto e conciso, no máximo 2 frases. Responda apenas ao que foi perguntado, sem explicações extras.\nPergunta: " . $message,
                'stream' => true,
            ],
            'stream' => true,
            'timeout' => 300,
        ]);

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            if ($chunk === '') {
                if ($body->eof()) break;
                // Avoid tight loop if stream stalls
                usleep(1000); // 1ms
                continue;
            }
            
            $buffer .= $chunk;
            
            // Only process if we have a newline
            if (strpos($buffer, "\n") !== false) {
                $lines = explode("\n", $buffer);
                // The last element is the incomplete remainder of the buffer
                $buffer = array_pop($lines);
                
                foreach ($lines as $line) {
                    if (trim($line) === '') continue;
                    
                    $data = json_decode($line, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                         if (isset($data['response'])) {
                             yield $data['response'];
                         }
                         if (isset($data['done']) && $data['done'] === true) {
                             $buffer = ''; // Clear buffer on done
                             return; 
                         }
                    }
                }
            }
        }
    }

    private function callGenerate(array $json): string
    {
        try {
            $response = $this->client->post('/api/generate', [
                'json' => $json,
                'timeout' => 300, // 5 minutes timeout for heavy generations
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return $body['response'] ?? '';
        } catch (\Throwable $e) {
            // Throw exception to be handled by Controller instead of returning error string causing SQL injection false positive
            throw new \RuntimeException("Erro Ollama: " . $e->getMessage()); 
        }
    }
}
