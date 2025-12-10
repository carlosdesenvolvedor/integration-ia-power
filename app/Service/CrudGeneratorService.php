<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Stringable\Str;

class CrudGeneratorService
{
    public function __construct(
        private OllamaService $ollamaService,
        private DatabaseManagerService $dbManager
    ) {}

    public function generateCrud(string $tableName): array
    {
        // 1. Obter Schema da Tabela
        $schema = $this->dbManager->getTableSchema($tableName);
        $className = Str::studly(Str::singular($tableName)); // ex: clientes -> Cliente

        // 2. Gerar Model
        $modelPrompt = "Generate a Hyperf (PHP) Model class for the table '{$tableName}'. 
        Class Name: {$className}
        Namespace: App\Model
        Schema:
        {$schema}
        
        Requirements:
        - Use strict types.
        - Extends Hyperf\DbConnection\Model\Model.
        - Add @property annotations for all columns.
        - Define 'protected ?string \$table = \'{$tableName}\';'.
        - Output ONLY the PHP code starting with <?php. Do not use markdown code blocks.";

        $modelCode = $this->ollamaService->generateCode($modelPrompt);
        $modelCode = $this->cleanCode($modelCode);
        $this->saveFile("app/Model/{$className}.php", $modelCode);

        // 3. Gerar Controller
        $controllerPrompt = "Generate a Hyperf (PHP) Controller class for the Model 'App\Model\\{$className}'.
        Class Name: {$className}Controller
        Namespace: App\Controller
        
        Requirements:
        - Use strict types.
        - Add #[Controller] annotation.
        - Implement CRUD methods: index (GET /), show (GET /{id}), store (POST /), update (PUT /{id}), delete (DELETE /{id}).
        - Use auto-mapping routing if possible or annotations like #[GetMapping], #[PostMapping], etc.
        - Return JSON responses.
        - Output ONLY the PHP code starting with <?php. Do not use markdown code blocks.";

        $controllerCode = $this->ollamaService->generateCode($controllerPrompt);
        $controllerCode = $this->cleanCode($controllerCode);
        $this->saveFile("app/Controller/{$className}Controller.php", $controllerCode);

        return [
            'model' => "app/Model/{$className}.php",
            'controller' => "app/Controller/{$className}Controller.php"
        ];
    }

    private function cleanCode(string $code): string
    {
        // Remove markdown code blocks
        $code = preg_replace('/^```php\s*|```$/', '', trim($code));
        $code = preg_replace('/^```\s*|```$/', '', trim($code));
        return $code;
    }

    private function saveFile(string $path, string $content): void
    {
        $fullPath = BASE_PATH . '/' . $path;
        file_put_contents($fullPath, $content);
    }
}
