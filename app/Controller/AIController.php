<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DatabaseManagerService;
use App\Service\OllamaService;
use App\Model\Context;
use Psr\SimpleCache\CacheInterface;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

#[Controller]
class AIController
{
    public function __construct(
        private OllamaService $ollamaService,
        private DatabaseManagerService $dbManager,
        private CacheInterface $cache
    ) {}

    #[PostMapping(path: '/ai/create-table')]
    public function createTable(RequestInterface $request, ResponseInterface $response)
    {
        $description = $request->input('description');

        if (!$description) {
            return $response->json(['error' => 'Description is required'])->withStatus(400);
        }

        try {
            // 1. Gerar SQL via Ollama
            $sql = $this->ollamaService->generateSql($description);
            
            // Limpar markdown code blocks se houver
            $sql = preg_replace('/^```sql\s*|```$/', '', trim($sql));

            // 2. Executar SQL no Banco
            $this->dbManager->executeSql($sql);

            return $response->json([
                'message' => 'Table created successfully',
                'sql_executed' => $sql
            ]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/query')]
    public function query(RequestInterface $request, ResponseInterface $response)
    {
        $question = $request->input('question');

        if (!$question) {
            return $response->json(['error' => 'Question is required'])->withStatus(400);
        }

        try {
            // 1. Obter Schema
            $schema = $this->dbManager->getSchema();

            // 2. Gerar SQL via Ollama com contexto
            $sql = $this->ollamaService->generateSelectSql($question, $schema);
            
            // Limpar markdown code blocks se houver
            $sql = preg_replace('/^```sql\s*|```$/', '', trim($sql));

            // 3. Executar SQL no Banco
            $results = $this->dbManager->select($sql);

            return $response->json([
                'question' => $question,
                'sql_generated' => $sql,
                'results' => $results
            ]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/command')]
    public function command(RequestInterface $request, ResponseInterface $response)
    {
        $command = $request->input('command');

        if (!$command) {
            return $response->json(['error' => 'Command is required'])->withStatus(400);
        }

        $warning = null;
        
        // INTELLIGENT ROUTING: Detect if user wants to CREATE TABLE but is in Command Mode
        if (preg_match('/^(?:crie|criar|create|nova)\s+(?:uma\s+)?tabela/i', trim($command))) {
            try {
                // 1. Generate SQL for Table Creation (using the specific DDL prompt)
                $sql = $this->ollamaService->generateSql($command);
                $sql = preg_replace('/^```sql\s*|```$/', '', trim($sql));

                // 2. Execute
                $this->dbManager->executeSql($sql);

                return $response->json([
                    'message' => 'Table created successfully (detected from command)',
                    'command' => $command,
                    'sql_executed' => $sql,
                    'warning' => "Notice: You were in 'Command' mode, but I detected a 'Create Table' request and handled it accordingly."
                ]);
            } catch (\Throwable $e) {
                return $response->json(['error' => $e->getMessage()])->withStatus(500);
            }
        }

        // Check for high volume requests (e.g. "create 50 products", "generate 100 rows")
        if (preg_match('/(create|generate|insert|criar|gerar|inserir)\s+(\d+)\s+/', strtolower($command), $matches)) {
            $amount = (int)$matches[2];
            if ($amount > 10) {
                // Replace the large number with 10 for performance safety
                $command = preg_replace('/\b' . $amount . '\b/', '10', $command);
                $warning = "Limit of 10 items applied due to local AI performance constraints (Requested: $amount).";
            }
        }

        try {
            // 1. Obter Schema
            $schema = $this->dbManager->getSchema();

            if (preg_match('/Considerando as tabelas\s*\[(.*?)\]/i', $command, $ctxMatches)) {
                $ctxTables = array_map('trim', explode(',', $ctxMatches[1]));
                $dataContext = "";
                
                foreach ($ctxTables as $ctxTable) {
                    try {
                        // 1. Get PK Name
                        $pkDetails = $this->dbManager->getPrimaryKeyDetails($ctxTable);
                        if ($pkDetails) {
                            $pkCol = $pkDetails['column'];
                            // 2. Fetch ALL IDs (limit 50) to give AI variety without hallucination
                            $idsResult = \Hyperf\DbConnection\Db::select("SELECT {$pkCol} FROM {$ctxTable} LIMIT 50");
                            $validIds = array_column(array_map(fn($r) => (array)$r, $idsResult), $pkCol);
                            $idsList = implode(', ', $validIds);
                            
                            $dataContext .= "VALID IDs for table '{$ctxTable}' (PK Column: '{$pkCol}'): [{$idsList}]\n";
                        }
                    } catch (\Throwable $e) {
                         // Fallback to full row sample if PK fails
                         try {
                             $sample = $this->dbManager->getTableData($ctxTable, 3);
                             $dataContext .= "Sample for '{$ctxTable}': " . json_encode($sample['rows']) . "\n";
                         } catch (\Throwable $ex) {}
                    }
                }
                
                if (!empty($dataContext)) {
                    $command .= "\n\n[STRICT DATA CONSTRAINTS]:\n" . $dataContext;
                    $command .= "IMPORTANT RULES:\n";
                    $command .= "1. CONTEXT TABLES ARE READ-ONLY: DO NOT INSERT INTO [ " . implode(', ', $ctxTables) . " ]. Only insert into the target table requested by user.\n";
                    $command .= "2. FOR FOREIGN KEYS: You MUST use ONLY the IDs listed above in [VALID IDs]. DO NOT invent new IDs.\n";
                    $command .= "3. REUSE IDS: It is OK to repeat the same ClientID or InstrumentID multiple times.\n";
                }
            }

            // DETECT TABLE NAME from command to check specific PK rules
            $targetTable = null;
            
            // Priority 1: Context Table (if user selected one explicitly)
            if (!empty($ctxTables)) {
                $targetTable = $ctxTables[0];
            }

            // Priority 2: Regex Detection (only overrides if context is empty or we want to be specific, 
            // but for safety let's use regex to find a table ONLY if we verify it exists)
            if (preg_match('/(?:into|table|tabela)\s+(?:de\s+|da\s+|do\s+|na\s+|no\s+)?[\'"`]?([a-zA-Z0-9_]+)[\'"`]?/i', $command, $tMatches)) {
                 $candidate = $tMatches[1];
                 $allTables = $this->dbManager->getTables();
                 
                 // CRITICAL FIX: Only accept the regex match if it is a VALID table. 
                 // This prevents capturing "com" (preposition) as a table name.
                 if (in_array($candidate, $allTables)) {
                     $targetTable = $candidate;
                 }
            }
            
            // Fallback: search for any known table name in the string if we still don't have one
            if (!$targetTable) {
                 $allTables = $this->dbManager->getTables();
                 foreach ($allTables as $t) {
                     // Check with boundaries to match whole words only
                     if (preg_match('/\b' . preg_quote($t, '/') . '\b/', $command)) {
                         $targetTable = $t;
                         break;
                     }
                 }
            }

            // If table found, check PK strategy AND ENFORCE SCHEMA
            if ($targetTable) {
                // 1. Get PK Details
                $pkDetails = $this->dbManager->getPrimaryKeyDetails($targetTable);
                // WE REMOVED THE CHECK for '!auto_increment'.
                // Reason: AI often fails to skip the ID column. By forcing explicit IDs (using MAX+1),
                // we guarantee unique IDs even if auto_increment exists (MySQL allows explicit overrides).
                if ($pkDetails && $pkDetails['is_numeric']) {
                     // Get current Max ID
                     $result = \Hyperf\DbConnection\Db::select("SELECT MAX({$pkDetails['column']}) as max_id FROM {$targetTable}");
                     $maxId = $result[0]->max_id ?? 0;
                     $nextId = $maxId + 1;
                     
                     // Append rigorous instruction
                     $command .= "\n\n[SYSTEM CONTEXT]: The table '{$targetTable}' has a numeric primary key '{$pkDetails['column']}'. The current maximum ID is {$maxId}. You MUST generate explicit IDs starting from {$nextId} for the new records (e.g. {$nextId}, " . ($nextId+1) . "...). Do NOT start from 1.";
                }

                // 2. ENFORCE COLUMN NAMES
                try {
                    $tableData = $this->dbManager->getTableData($targetTable, 1);
                    $columnsList = implode(', ', $tableData['columns']);
                    $command .= "\n\n[CRITICAL SCHEMA RULE]: The target table '{$targetTable}' has exactly these columns: [{$columnsList}].\n";
                    $command .= "You MUST use these exact column names in your INSERT statement. Do NOT invent columns like 'id' or 'produto' if they are not in the list.\n";
                    $command .= "For Foreign Keys, use the integer IDs from the sample data provided above (not names like 'Guitarra').";
                } catch (\Throwable $e) {
                    // Ignore schema fetch error
                }
            }

            // 2. Gerar SQL via Ollama com contexto
            $sql = $this->ollamaService->generateManipulationSql($command, $schema);
            
            // Limpar markdown code blocks se houver
            $sql = preg_replace('/^```sql\s*|```$/', '', trim($sql));

            // 3. Executar SQL no Banco
            $this->dbManager->executeSql($sql);

            return $response->json([
                'message' => 'Command executed successfully',
                'command' => $command,
                'sql_executed' => $sql,
                'warning' => $warning
            ]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/generate-crud')]
    public function generateCrud(RequestInterface $request, ResponseInterface $response)
    {
        $tableName = $request->input('table');

        if (!$tableName) {
            return $response->json(['error' => 'Table name is required'])->withStatus(400);
        }

        try {
            // Injeção manual do serviço de geração (idealmente via construtor, mas para simplificar aqui)
            $generator = \Hyperf\Context\ApplicationContext::getContainer()->get(\App\Service\CrudGeneratorService::class);
            
            $files = $generator->generateCrud($tableName);

            return $response->json([
                'message' => 'CRUD generated successfully. Please restart the server to apply changes.',
                'files' => $files
            ]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/analyze-query')]
    public function analyzeQuery(RequestInterface $request, ResponseInterface $response)
    {
        $question = $request->input('question');
        $contextTables = $request->input('context_tables', []);
        $contextId = $request->input('context_id');

        if (!$question) return $response->json(['error' => 'Question is required'])->withStatus(400);

        // Load context if provided
        if ($contextId) {
            $context = Context::find($contextId);
            if ($context && !empty($context->content['tables'])) {
                $ctxTables = $context->content['tables'];
                if (is_array($ctxTables)) {
                    $contextTables = array_merge($contextTables, $ctxTables);
                }
            }
        }

        // Normalize and sort context tables for stable cache key
        if (is_array($contextTables)) {
            $contextTables = array_values(array_filter(array_map('trim', $contextTables)));
            sort($contextTables);
        } else {
            $contextTables = [];
        }

        $cacheKey = 'analyze:' . md5($question . '|' . implode(',', $contextTables));
        $ttl = (int) (getenv('ANALYZE_CACHE_TTL') ?: (getenv('DB_CACHE_TTL') ?: 60));

        if ($this->cache->has($cacheKey)) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $response->json($cached);
            }
        }

        try {
            // If user provided context tables, reuse cached snapshots and avoid new SQL generation
            if (!empty($contextTables)) {
                $tablesData = [];
                foreach ($contextTables as $table) {
                    try {
                        $tablesData[] = [
                            'table' => $table,
                            'data' => $this->dbManager->getTableData($table),
                        ];
                    } catch (\Throwable $e) {
                        // If one table fails, continue with others
                    }
                }

                $payload = [
                    'question' => $question,
                    'sql_generated' => null,
                    'data' => ['tables' => $tablesData],
                ];
            } else {
                $schema = $this->dbManager->getSchema();
                $sql = $this->ollamaService->generateSelectSql($question, $schema);
                $sql = preg_replace('/^```sql\s*|```$/', '', trim($sql));

                $results = [];
                $error = null;
                try {
                    $results = $this->dbManager->select($sql);
                } catch (\Throwable $sqlError) {
                    $error = "SQL Execution Failed: " . $sqlError->getMessage();
                    $results = ['schema_context' => $schema, 'sql_error' => $error];
                }

                $payload = [
                    'question' => $question,
                    'sql_generated' => $sql,
                    'data' => isset($error) ? ['error' => $error] : $results,
                    // No insight yet
                ];
            }

            // Cache only successful or graceful responses (not exceptions)
            $this->cache->set($cacheKey, $payload, $ttl > 0 ? $ttl : 60);

            return $response->json($payload);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/analyze-insight')]
    public function analyzeInsight(RequestInterface $request, ResponseInterface $response)
    {
        $question = $request->input('question');
        $data = $request->input('data'); // Client sends back the data (or subset)
        
        if (!$question || !$data) return $response->json(['error' => 'Question and Data required'])->withStatus(400);

        try {
            $insight = $this->ollamaService->generateInsight($question, $data);
            return $response->json(['insight' => $insight]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/migrate')]
    public function migrate(RequestInterface $request, ResponseInterface $response)
    {
        $command = $request->input('command');
        $table = $request->input('table');

        if (!$command || !$table) {
            return $response->json(['error' => 'Command and table are required'])->withStatus(400);
        }

        try {
            // 1. Obter Schema da tabela específica
            $schema = $this->dbManager->getTableSchema($table);

            // 2. Gerar SQL de Migração (ALTER TABLE)
            $sql = $this->ollamaService->generateMigrationSql($command, $schema);
            $sql = preg_replace('/^```sql\s*|```$/', '', trim($sql));

            // 3. Executar SQL
            $this->dbManager->executeSql($sql);

            return $response->json([
                'message' => 'Migration executed successfully',
                'command' => $command,
                'sql_executed' => $sql
            ]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }
    #[GetMapping(path: '/ai/tables')]
    public function getTables(ResponseInterface $response)
    {
        try {
            $tables = $this->dbManager->getTables();
            return $response->json(['tables' => $tables]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[GetMapping(path: '/ai/table-data')]
    public function getTableData(RequestInterface $request, ResponseInterface $response)
    {
        $table = $request->input('table');
        if (!$table) {
            return $response->json(['error' => 'Table name is required'])->withStatus(400);
        }

        try {
            $data = $this->dbManager->getTableData($table);
            return $response->json(['data' => $data]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }
    #[PostMapping(path: '/ai/drop-table')]
    public function dropTable(RequestInterface $request, ResponseInterface $response)
    {
        $table = $request->input('table');
        if (!$table) {
            return $response->json(['error' => 'Table name is required'])->withStatus(400);
        }

        try {
            $this->dbManager->dropTable($table);
            return $response->json(['message' => "Table '{$table}' deleted successfully."]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/chat-free')]
    public function chatFree(RequestInterface $request, ResponseInterface $response)
    {
        $message = $request->input('message');
        $contextId = $request->input('context_id');

        if (!$message) {
            return $response->json(['error' => 'Message is required'])->withStatus(400);
        }

        if ($contextId) {
            $context = Context::find($contextId);
            if ($context && !empty($context->content['text'])) {
                $message = "[CONTEXT]: " . $context->content['text'] . "\n\n[USER]: " . $message;
            }
        }

        try {
            $reply = $this->ollamaService->chat($message);
            return $response->json(['reply' => $reply]);
        } catch (\Throwable $e) {
            return $response->json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/chat-free-stream')]
    public function chatFreeStream(RequestInterface $request, ResponseInterface $response)
    {
        $message = $request->input('message');
        $contextId = $request->input('context_id');

        if (!$message) {
            return $response->json(['error' => 'Message is required'])->withStatus(400);
        }

        if ($contextId) {
            $context = Context::find($contextId);
            if ($context && !empty($context->content['text'])) {
                $message = "[CONTEXT]: " . $context->content['text'] . "\n\n[USER]: " . $message;
            }
        }

        // Direct Swoole Streaming Strategy
        $psr7Response = \Hyperf\Context\Context::get(\Psr\Http\Message\ResponseInterface::class);
        $swooleResponse = null;

        if ($psr7Response && method_exists($psr7Response, 'getConnection')) {
            $swooleResponse = $psr7Response->getConnection();
        }

        if ($swooleResponse instanceof \Swoole\Http\Response) {
            // 1. Send Headers
            $swooleResponse->header('Content-Type', 'text/event-stream');
            $swooleResponse->header('Cache-Control', 'no-cache');
            $swooleResponse->header('Connection', 'keep-alive');
            $swooleResponse->header('X-Accel-Buffering', 'no');

            try {
                // 2. Stream Content
                // PADDING to force browser buffer flush (2KB)
                $swooleResponse->write(str_repeat(" ", 2048) . "\n");

                $generator = $this->ollamaService->chatStream($message);
                
                foreach ($generator as $chunk) {
                    $swooleResponse->write($chunk);
                }
                
                // 3. End Response (Empty SwooleStream prevents proper double-end)
                // We return an empty body response to satisfy the framework's workflow,
                // but since we wrote to the socket, we shouldn't send more data.
                return $response->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(''));
            } catch (\Throwable $e) {
                // Attempt to send error if headers allow
                @$swooleResponse->write("\nError: " . $e->getMessage());
                return $response->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(''));
            }
        }

        // Fallback for non-Swoole environments (though app is Swoole)
        $response = $response->withHeader('Content-Type', 'text/event-stream')
                             ->withHeader('Cache-Control', 'no-cache')
                             ->withHeader('Connection', 'keep-alive');
        
        try {
            $generator = $this->ollamaService->chatStream($message);
            $stream = new \App\Service\GeneratorStream($generator);
            return $response->withBody($stream);
        } catch (\Throwable $e) {
             return $response->withStatus(500)->json(['error' => 'Stream Init Error: ' . $e->getMessage()]);
        }
    }
}
