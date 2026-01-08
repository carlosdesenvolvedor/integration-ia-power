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
        private CacheInterface $cache,
        private \Psr\Container\ContainerInterface $container
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

    #[PostMapping(path: '/ai/analyze-pdf')]
    public function analyzePdf(RequestInterface $request, ResponseInterface $response)
    {
        $file = $request->file('pdf');
        if (!$file || !$file->isValid()) {
            return $response->json(['error' => 'Arquivo PDF válido é obrigatório'])->withStatus(400);
        }

        $vision = $request->input('vision', 'true') === 'true';
        
        // Log basic info
        $logger = $this->container->get(\Hyperf\Logger\LoggerFactory::class)->get('ai');
        $logger->info("Analyze PDF started. Vision: " . ($vision ? 'yes' : 'no') . ", Name: " . $file->getClientFilename());

        try {
            if ($vision) {
                return $this->analyzePdfVision($file, $response);
            }

            // Fallback para texto puro se vision for false
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file->getRealPath());
            $text = preg_replace('/\s+/', ' ', $pdf->getText());
            
            $reply = $this->ollamaService->chat("Analise este texto de PDF:\n" . mb_substr($text, 0, 10000));
            return $response->json(['reply' => $reply]);

        } catch (\Throwable $e) {
            return $response->json(['error' => 'Falha ao analisar PDF: ' . $e->getMessage()])->withStatus(500);
        }
    }

    private function analyzePdfVision($file, ResponseInterface $response)
    {
        $pdfPath = $file->getRealPath();
        $tempDir = BASE_PATH . '/runtime/temp_vision';
        if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
        
        $outputImagePath = $tempDir . '/' . uniqid() . '.png';

        try {
            // 0. Check Environment
            if (!extension_loaded('imagick')) {
                throw new \Exception("Extensão 'imagick' não encontrada no servidor PHP. Verifique o Dockerfile.");
            }

            // 1. Converter PDF para Imagem com alta resolução (150 DPI)
            $pdf = new \Spatie\PdfToImage\Pdf($pdfPath);
            
            // API v3 uses fluent methods without 'set' prefix
            if (method_exists($pdf, 'resolution')) {
                $pdf->resolution(150);
            }
            
            if (method_exists($pdf, 'format')) {
                $pdf->format(\Spatie\PdfToImage\Enums\OutputFormat::Png);
            }

            // Try saveImage or save depending on exact version/available methods
            if (method_exists($pdf, 'saveImage')) {
                $pdf->saveImage($outputImagePath);
            } else {
                $pdf->save($outputImagePath);
            }

            if (!file_exists($outputImagePath)) {
                throw new \Exception("Arquivo de imagem não foi gerado. Verifique os logs do Ghostscript/ImageMagick.");
            }

            $imageBase64 = base64_encode(file_get_contents($outputImagePath));

            // 2. Chamar IA com Visão - Foco em EMBALAGEM COMPLETA
            $prompt = "Você é um especialista em visão de produtos.\n" .
                      "Neste catálogo, os produtos estão dispostos verticalmente: FOTO em cima, TEXTO embaixo.\n\n" .
                      "Tarefa: Identifique cada item e seu box de imagem.\n" .
                      "- Nome completo, Código, Preço e Unidade.\n" .
                      "- box: [ymin, xmin, ymax, xmax] da EMBALAGEM COMPLETA do produto (0 a 100).\n\n" .
                      "REGRAS CRÍTICAS:\n" .
                      "1. O box DEVE envolver o produto INTEIRO, de ponta a ponta, sem cortar as bordas.\n" .
                      "2. Centralize o produto no box.\n" .
                      "3. NÃO inclua o texto de descrição no box.\n" .
                      "4. Retorne APENAS o JSON bruto (array de objetos).";

            $reply = $this->ollamaService->chatWithVision($prompt, $imageBase64);
            $products = json_decode($reply, true) ?: (preg_match('/\[.*\]/s', $reply, $m) ? json_decode($m[0], true) : []);

            if (!$products) {
                throw new \Exception("A IA não conseguiu estruturar os produtos. Resposta bruta: " . mb_substr($reply, 0, 500));
            }

            // 3. Recortar Imagens
            $manager = \Intervention\Image\ImageManager::gd();
            $img = $manager->read($outputImagePath);
            $width = $img->width();
            $height = $img->height();

            foreach ($products as &$product) {
                if (isset($product['box']) && count($product['box']) === 4) {
                    try {
                        $p = $product['box'];
                        
                        // Adicionar 5% de margem de segurança para evitar cortes
                        $padding = 5;
                        $ymin = max(0, $p[0] - $padding);
                        $xmin = max(0, $p[1] - $padding);
                        $ymax = min(100, $p[2] + $padding);
                        $xmax = min(100, $p[3] + $padding);

                        $y1 = ($ymin / 100) * $height;
                        $x1 = ($xmin / 100) * $width;
                        $ch = (($ymax - $ymin) / 100) * $height;
                        $cw = (($xmax - $xmin) / 100) * $width;

                        $crop = clone $img;
                        $crop->crop((int)round($cw), (int)round($ch), (int)round($x1), (int)round($y1));
                        $product['imageBase64'] = base64_encode((string)$crop->toJpeg());
                    } catch (\Throwable $e) {
                        $product['crop_error'] = $e->getMessage();
                    }
                }
            }

            @unlink($outputImagePath);
            return $response->json(['products' => $products]);

        } catch (\Throwable $e) {
            @unlink($outputImagePath);
            return $response->json(['error' => 'Erro no processamento visual: ' . $e->getMessage()])->withStatus(500);
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
    #[PostMapping(path: '/ai/generate-video')]
    public function generateVideo(RequestInterface $request, ResponseInterface $response)
    {
        $text = $request->input('text');
        $avatarImage = $request->input('avatar_image'); // URL or Path accessible by Python
        $emotion = $request->input('emotion', 'natural');
        $voice = $request->input('voice', 'alloy');
        // Force Realtime Unity mode for "generateVideo" as well during debugging (fast path)
        $mode = 'realtime_unity';
        
        // Default Avatar from Vast.ai Server
        if (!$avatarImage) {
            $avatarImage = "/root/ai_worker/default_avatar.jpg";
        }

        if (!$text) {
            return $response->json(['error' => 'Text is required'])->withStatus(400);
        }

        try {
            // Call Python AI Worker (sync path for low latency)
            $pythonUrl = 'http://host.docker.internal:8090/generate_video_sync';
            
            $client = new \GuzzleHttp\Client();
            $res = $client->post($pythonUrl, [
                'json' => [
                    'text' => $text,
                    'avatar_image' => $avatarImage,
                    'emotion' => $emotion,
                    'voice' => $voice,
                    'mode' => $mode
                ]
            ]);

            $data = json_decode($res->getBody()->getContents(), true);

            [$payload, $rawResult] = $this->extractRealtime($data);

            return $response->json([
                'message' => 'Video generation completed',
                'reply_text' => $text,
                'audio_url' => $payload['audio_url'] ?? null,
                'visemes' => $payload['visemes'] ?? [],
                'raw_result' => $rawResult,
                'status' => $data['status'] ?? 'finished',
                'job_id' => $data['job_id'] ?? null
            ]);

        } catch (\Throwable $e) {
            return $response->json(['error' => 'Failed to process video generation: ' . $e->getMessage()])->withStatus(500);
        }
    }
    #[PostMapping(path: '/ai/talk-to-avatar')]
    public function talkToAvatar(RequestInterface $request, ResponseInterface $response)
    {
        $prompt = $request->input('prompt');
        $avatarImage = $request->input('avatar_image');
        if (!$avatarImage) {
            $avatarImage = "/root/ai_worker/default_avatar.jpg";
        }
        $voice = $request->input('voice', 'alloy');
        $emotion = $request->input('emotion', 'natural');
        $contextId = $request->input('context_id');
        // Force Realtime Unity mode for "talkToAvatar" to ensure speed/audio
        $mode = 'realtime_unity'; 

        if (!$prompt) {
            return $response->json(['error' => 'Prompt is required'])->withStatus(400);
        }

        try {
            // 1. Get AI Text Response (The "Brain")
            $aiPrompt = $prompt;
            if ($contextId) {
                $context = Context::find($contextId);
                if ($context && !empty($context->content['text'])) {
                     $aiPrompt = "[CONTEXT]: " . $context->content['text'] . "\n\n[USER]: " . $prompt;
                }
            } else {
                // System instruction for better persona
                // System instruction for better persona: Misaki (Fofa e Prestativa)
                $persona = "Você é a Misaki, uma assistente virtual fofa, gentil e muito prestativa. " .
                           "Sua aparência é de uma jovem personagem de anime, como o modelo que o usuário está vendo agora. " .
                           "Fale SEMPRE em Português do Brasil com um tom carinhoso e amigável. " .
                           "Mantenha suas respostas curtas (máximo 2 frases), para garantir uma boa sincronização labial. " .
                           "Ao se apresentar, diga que você é a Misaki e que está aqui para ajudar.";
                
                $aiPrompt = "INSTRUÇÃO DO SISTEMA: " . $persona . "\n\nUsuário: " . $prompt;
            }

            $aiResponseText = $this->ollamaService->chat($aiPrompt);
            
            // Clean up any markdown or thinking artifacts if necessary
            $aiResponseText = strip_tags($aiResponseText);

            // 2. Generate Audio/Viseme payload synchronously for instant response
            // TUNNEL FIX: Use host.docker.internal to access the SSH Tunnel on the Host
            $pythonUrl = 'http://host.docker.internal:8090/generate_video_sync';
            $client = new \GuzzleHttp\Client();
            
            $res = $client->post($pythonUrl, [
                'json' => [
                    'text' => $aiResponseText,
                    'avatar_image' => $avatarImage,
                    'emotion' => $emotion,
                    'voice' => $voice,
                    'mode' => $mode
                ]
            ]);
            
            $data = json_decode($res->getBody()->getContents(), true);
            [$payload, $rawResult] = $this->extractRealtime($data);

            return $response->json([
                'message' => 'Processed successfully',
                'reply_text' => $aiResponseText,
                'audio_url' => $payload['audio_url'] ?? null,
                'visemes' => $payload['visemes'] ?? [],
                'raw_result' => $rawResult,
                'status' => $data['status'] ?? 'finished',
                'job_id' => $data['job_id'] ?? null
            ]);

        } catch (\Throwable $e) {
            return $response->json(['error' => 'Interaction failed: ' . $e->getMessage()])->withStatus(500);
        }
    }

    #[GetMapping(path: '/ai/jobs/{job_id}')]
    public function getJobStatus($job_id, ResponseInterface $response)
    {
        try {
            // Call Python AI Worker
            // TUNNEL FIX: Use host.docker.internal to access the SSH Tunnel on the Host
            $pythonUrl = 'http://host.docker.internal:8090/jobs/' . $job_id;
            
            $client = new \GuzzleHttp\Client();
            $res = $client->get($pythonUrl);

            $data = json_decode($res->getBody()->getContents(), true);

            // Enhance result URL if finished
            if (isset($data['status']) && $data['status'] === 'finished' && isset($data['result'])) {
                 $path = $data['result'];
                 
                 // Handle REALTIME_JSON special case - pass it through directly
                 if (strpos($path, 'REALTIME_JSON:') === 0) {
                     // Parse and inject absolute URL for the tunnel (localhost:8080)
                     $jsonStr = substr($path, 14); // Remove prefix
                     $jsonObj = json_decode($jsonStr, true);
                     if (isset($jsonObj['audio_url'])) {
                         $fname = basename($jsonObj['audio_url']);
                         // Fix: Send FULL path with port 8090 to avoid conflict with stuck 8088
                         $jsonObj['audio_url'] = "http://localhost:8090/outputs/" . $fname;
                         $data['result'] = "REALTIME_JSON:" . json_encode($jsonObj);
                     }
                 } 
                 // Handle UNITY_AUDIO special case
                 else if (strpos($path, 'UNITY_AUDIO:') === 0) {
                     // No modification needed
                 }
                 else if (strpos($path, '/app/outputs/') !== false) {
                     $filename = basename($path);
                     // Fix: Point to localhost:8080 to use the SSH Tunnel
                     $data['result'] = "http://localhost:8090/outputs/" . $filename;
                 }
            }

            return $response->json($data);

        } catch (\Throwable $e) {
             // Handle 404 from Python gracefully
             if (strpos($e->getMessage(), '404') !== false) {
                 return $response->json(['error' => 'Job not found'])->withStatus(404);
             }
            return $response->json(['error' => 'Failed to check job status: ' . $e->getMessage()])->withStatus(500);
        }
    }
    #[PostMapping(path: '/ai/generate-avatar')]
    public function generateAvatar(RequestInterface $request, ResponseInterface $response)
    {
        $prompt = $request->input('prompt');

        if (!$prompt) {
            return $response->json(['error' => 'Prompt is required'])->withStatus(422);
        }

        try {
            // Call Python AI Worker
            // TUNNEL FIX: Use host.docker.internal to access the SSH Tunnel on the Host
            $pythonUrl = 'http://host.docker.internal:8080/avatar';
            
            $client = new \GuzzleHttp\Client();
            $res = $client->post($pythonUrl, [
                'json' => [
                    'prompt' => $prompt
                ]
            ]);

            $data = json_decode($res->getBody()->getContents(), true);

            return $response->json([
                'message' => 'Avatar generation queued successfully',
                'job_id' => $data['job_id'],
                'status' => $data['status']
            ]);

        } catch (\Throwable $e) {
            return $response->json(['error' => 'Failed to queue avatar generation: ' . $e->getMessage()])->withStatus(500);
        }
    }

    #[PostMapping(path: '/ai/upload-base-motion')]
    public function uploadBaseMotion(\Hyperf\HttpServer\Contract\RequestInterface $request, \Hyperf\HttpServer\Contract\ResponseInterface $response)
    {
        $avatarName = $request->input('avatar_name');
        $videoFile = $request->file('video');

        if (!$avatarName || !$videoFile) {
            return $response->json(['error' => 'avatar_name and video file are required'])->withStatus(422);
        }

        try {
            // TUNNEL FIX: Use host.docker.internal to access the SSH Tunnel on the Host
            $pythonUrl = 'http://host.docker.internal:8080/upload_base_motion';
            
            $client = new \GuzzleHttp\Client();
            $res = $client->post($pythonUrl, [
                'multipart' => [
                    [
                        'name'     => 'avatar_name',
                        'contents' => $avatarName
                    ],
                    [
                        'name'     => 'video',
                        'contents' => fopen($videoFile->getRealPath(), 'r'),
                        'filename' => $videoFile->getClientFilename()
                    ]
                ]
            ]);

            return $response->json(json_decode($res->getBody()->getContents(), true));

        } catch (\Throwable $e) {
            return $response->json(['error' => 'Failed to upload base motion: ' . $e->getMessage()])->withStatus(500);
        }
    }

    /**
     * Normalize realtime payload (audio + visemes) returned by the Python worker.
     */
    private function normalizeRealtimePayload(?array $payload): array
    {
        $payload = $payload ?? [];

        if (isset($payload['audio_url'])) {
            $fname = basename((string) $payload['audio_url']);
            $payload['audio_url'] = "http://localhost:8090/outputs/" . $fname;
        }

        if (!isset($payload['visemes']) || !is_array($payload['visemes'])) {
            $payload['visemes'] = [];
        }

        return $payload;
    }

    /**
     * Extracts realtime payload and raw result string from worker response.
     */
    private function extractRealtime(array $data): array
    {
        $rawResult = $data['result'] ?? null;
        $payload = $data['realtime'] ?? null;

        if (!$payload && is_string($rawResult) && str_starts_with($rawResult, 'REALTIME_JSON:')) {
            $payload = json_decode(substr($rawResult, 14), true) ?: [];
        }

        $payload = $this->normalizeRealtimePayload($payload);

        if (is_string($rawResult) && str_starts_with($rawResult, 'REALTIME_JSON:')) {
            $rawResult = 'REALTIME_JSON:' . json_encode($payload);
        }

        return [$payload, $rawResult];
    }
}
