<?php

declare(strict_types=1);

namespace App\Contract;

interface LlmDriverInterface
{
    public function generateSql(string $prompt): string;
    
    public function generateSelectSql(string $question, string $schema): string;
    
    public function generateManipulationSql(string $command, string $schema): string;
    
    public function generateCode(string $prompt): string;
    
    public function generateInsight(string $question, array $data): string;

    public function chat(string $message): string;

    public function chatStream(string $message): \Generator;
}
