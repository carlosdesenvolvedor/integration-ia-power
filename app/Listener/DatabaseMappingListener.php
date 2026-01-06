<?php

declare(strict_types=1);

namespace App\Listener;

use App\Service\DatabaseManagerService;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnStart;
use Hyperf\Contract\StdoutLoggerInterface;

#[Listener]
class DatabaseMappingListener implements ListenerInterface
{
    public function __construct(
        private DatabaseManagerService $dbManager,
        private StdoutLoggerInterface $logger
    ) {}

    public function listen(): array
    {
        return [
            OnStart::class,
        ];
    }

    public function process(object $event): void
    {
        $this->logger->info('[AI Power] 🚀 Iniciando mapeamento inteligente do banco de dados...');
        
        try {
            $startTime = microtime(true);
            $schema = $this->dbManager->buildRichSchema();
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->info("[AI Power] ✅ Mapeamento concluído em {$duration}ms. Schema pronto para uso.");
        } catch (\Throwable $e) {
            // Em ambientes sem banco (ex: Stateless no Render), apenas ignoramos graciosamente
            $this->logger->warning("[AI Power] ⚠️ Base de dados não disponível. Funcionalidades SQL estarão desativadas.");
        }
    }
}

