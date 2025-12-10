<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\DbConnection\Db;
use Psr\SimpleCache\CacheInterface;

class DatabaseManagerService
{
    private int $ttlSeconds;
    private string $prefix = 'db-cache:';

    public function __construct(private CacheInterface $cache)
    {
        $ttl = (int) (getenv('DB_CACHE_TTL') ?: 300);
        $this->ttlSeconds = $ttl > 0 ? $ttl : 300;
    }

    public function executeSql(string $sql): void
    {
        // Dividir por ponto e vírgula, mas ignorar se estiver vazio
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $stmt) {
            if (empty($stmt)) continue;

            // Segurança básica: permitir CREATE/ALTER e INSERT/UPDATE/DELETE/DROP/TRUNCATE
            $allowed = ['CREATE', 'ALTER', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE'];
            $command = strtoupper(strtok($stmt, ' ')); // Pega a primeira palavra

            if (!in_array($command, $allowed)) {
                // Se for um comentário ou algo estranho, ignorar ou logar, mas aqui vamos ser estritos
                // Vamos relaxar se for SET ou USE, ou apenas lançar erro
                throw new \RuntimeException("Statement not allowed: " . substr($stmt, 0, 50) . "...");
            }

            Db::statement($stmt);
        }

        // Optionally clear cache on DDL/DML if desired (disabled by default)
        $shouldClear = strtolower((string) getenv('CACHE_CLEAR_ON_DDL')) === 'true';
        if ($shouldClear) {
            $this->clearCaches();
        }
    }

    public function select(string $sql): array
    {
        if (stripos(trim($sql), 'SELECT') !== 0) {
            throw new \RuntimeException('Only SELECT statements are allowed in this method.');
        }

        $normalizedSql = $this->normalizeSql($sql);
        $key = $this->prefix . 'select:' . md5($normalizedSql);
        if ($this->cache->has($key)) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = Db::select($sql);
        // Cache lightweight results only (avoid huge payloads)
        if (count($result) <= 5000) {
            $this->cache->set($key, $result, $this->ttlSeconds);
        }

        return $result;
    }

    public function getSchema(): string
    {
        $key = $this->prefix . 'schema';
        if ($this->cache->has($key)) {
            $cached = $this->cache->get($key);
            if (is_string($cached) && !empty($cached)) {
                return $cached;
            }
        }

        // If not found (cold start), build it now
        return $this->buildRichSchema();
    }

    public function buildRichSchema(): string
    {
        $schema = "";
        $tables = Db::select('SHOW TABLES');
        
        foreach ($tables as $table) {
            $tableName = array_values((array)$table)[0];
            $schema .= "Table: {$tableName}\nColumns:\n";
            
            // Get Columns
            $columns = Db::select("SHOW COLUMNS FROM {$tableName}");
            foreach ($columns as $column) {
                $extra = $column->Extra ? "({$column->Extra})" : "";
                $key = $column->Key ? "[{$column->Key}]" : "";
                $schema .= "- {$column->Field} ({$column->Type}) {$key} {$extra}\n";
            }

            // Get Indices (Rich Context)
            try {
                $indexes = Db::select("SHOW INDEX FROM {$tableName}");
                $indexMap = [];
                foreach ($indexes as $idx) {
                    $k = $idx->Key_name;
                    if ($k === 'PRIMARY') continue; // Already in Columns
                    $indexMap[$k][] = $idx->Column_name;
                }
                if (!empty($indexMap)) {
                    $schema .= "Indices:\n";
                    foreach ($indexMap as $name => $cols) {
                        $schema .= "- {$name}: " . implode(', ', $cols) . "\n";
                    }
                }
            } catch (\Throwable $e) {
                // Ignore index fetch errors
            }

            $schema .= "\n";
        }

        $key = $this->prefix . 'schema';
        $this->cache->set($key, $schema, $this->ttlSeconds);
        return $schema;
    }

    public function getTableSchema(string $tableName): string
    {
        // Validação básica para evitar SQL Injection no nome da tabela
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \RuntimeException('Invalid table name.');
        }

        $schema = "Table: {$tableName}\nColumns:\n";
        $columns = Db::select("SHOW COLUMNS FROM {$tableName}");
        foreach ($columns as $column) {
            $extra = $column->Extra ? "({$column->Extra})" : "";
            $key = $column->Key ? "[{$column->Key}]" : "";
            $schema .= "- {$column->Field} ({$column->Type}) {$key} {$extra}\n";
        }
        
        return $schema;
    }
    public function getTables(): array
    {
        $tables = Db::select('SHOW TABLES');
        $tableList = [];
        foreach ($tables as $table) {
            $tableList[] = array_values((array)$table)[0];
        }
        return $tableList;
    }

    public function getTableData(string $tableName, int $limit = 500): array
    {
        // Validação básica para evitar SQL Injection no nome da tabela
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \RuntimeException('Invalid table name.');
        }

        $cacheKey = $this->prefix . "table:{$tableName}:{$limit}";
        // Uncomment cache if needed, but ensure manual updates clear it
        if ($this->cache->has($cacheKey)) {
             $cached = $this->cache->get($cacheKey);
             if (is_array($cached)) {
                 return $cached;
             }
        }

        // 1. Obter colunas
        $columnsRaw = Db::select("SHOW COLUMNS FROM {$tableName}");
        $columns = [];
        foreach ($columnsRaw as $col) {
            $columns[] = $col->Field;
        }

        // 2. Obter dados
        $rows = Db::select("SELECT * FROM {$tableName} LIMIT {$limit}");

        $payload = [
            'columns' => $columns,
            'rows' => $rows
        ];

        $this->cache->set($cacheKey, $payload, $this->ttlSeconds);
        return $payload;
    }

    public function getPrimaryKeyDetails(string $tableName): ?array
    {
         if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            return null;
        }

        $columns = Db::select("SHOW COLUMNS FROM {$tableName}");
        foreach ($columns as $column) {
            if ($column->Key === 'PRI') {
                return [
                    'column' => $column->Field,
                    'type' => $column->Type,
                    'auto_increment' => str_contains(strtolower($column->Extra ?? ''), 'auto_increment'),
                    'is_numeric' => preg_match('/int|decimal|float|double|num/i', $column->Type)
                ];
            }
        }
        return null;
    }
    public function dropTable(string $tableName): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \RuntimeException('Invalid table name.');
        }

        Db::statement('SET FOREIGN_KEY_CHECKS = 0');
        Db::statement("DROP TABLE IF EXISTS {$tableName}");
        Db::statement('SET FOREIGN_KEY_CHECKS = 1');

        $shouldClear = strtolower((string) getenv('CACHE_CLEAR_ON_DDL')) === 'true';
        if ($shouldClear) {
            $this->clearCaches();
        }
    }

    private function clearCaches(): void
    {
        try {
            // Clear all cached entries; acceptable since cache is currently dedicated
            $this->cache->clear();
        } catch (\Throwable $e) {
            // Ignore cache failures to avoid breaking main flow
        }
    }

    private function normalizeSql(string $sql): string
    {
        // Remove trailing semicolons and collapse excessive whitespace
        $sql = trim($sql, " \t\n\r\0\x0B;");
        // Collapse multiple whitespace to single space
        $sql = preg_replace('/\s+/', ' ', $sql);
        return $sql;
    }
}
