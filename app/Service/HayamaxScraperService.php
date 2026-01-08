<?php

declare(strict_types=1);

namespace App\Service;

class HayamaxScraperService
{
    /**
     * Analisa o HTML bruto enviado pelo frontend e extrai os produtos.
     * Versão otimizada para capturar imagens mesmo com Lazy Load.
     */
    public function parseHtml(string $html): array
    {
        $products = [];
        
        // 1. Isolar blocos de produtos usando a classe específica identificada
        // A Hayamax usa 'search-product' para cada item na busca nova
        preg_match_all('/<div[^>]*class="[^"]*search-product[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>\s*<\/div>/s', $html, $blocks);

        if (empty($blocks[1])) {
            // Fallback para o modo grade geral
            preg_match_all('/<div[^>]*class="[^"]*(col-|card-product|product-item|product-container)[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/s', $html, $blocks);
            $contentIdx = 2;
        } else {
            $contentIdx = 1;
        }

        if (empty($blocks[$contentIdx])) {
            // Último recurso: dividir por "Cód."
            $parts = explode('Cód.', $html);
            array_shift($parts); // Remove a primeira parte antes do primeiro código
            foreach ($parts as $part) {
                $block = 'Cód.' . substr($part, 0, 1000); // Pega um pedaço razoável
                $this->extractFromBlock($block, $products);
            }
            return $products;
        }

        foreach ($blocks[$contentIdx] as $block) {
            $this->extractFromBlock($block, $products);
        }

        return $products;
    }

    private function extractFromBlock(string $block, array &$products): void
    {
        // 1. Código (Cód. 74168)
        preg_match('/Cód\.\s*(\d+)/', $block, $codeMatch);
        $code = $codeMatch[1] ?? null;

        if (!$code) return;

        // 2. Preço (Extrair apenas o valor numérico para o campo de Custo)
        preg_match('/R\$\s*([\d,.]+)/', $block, $priceMatch);
        $priceRaw = $priceMatch[1] ?? '0.00';
        // Converter formato brasileiro (1.200,50) para decimal (1200.50)
        $priceClean = str_replace(['.', ','], ['', '.'], $priceRaw);
        
        // 3. Nome
        $name = '';
        if (preg_match('/class="search-product-title"[^>]*>(.*?)<\/p>/s', $block, $nameMatch)) {
            $name = trim(strip_tags($nameMatch[1]));
        } else {
            $cleanBlock = strip_tags($block);
            $lines = array_map('trim', explode("\n", $cleanBlock));
            foreach ($lines as $line) {
                if (strlen($line) > 10 && !str_contains($line, 'Cód.') && !str_contains($line, 'R$') && !str_contains($line, 'Estoque')) {
                    $name = $line;
                    break;
                }
            }
        }

        // 4. Imagem (Base64) - Prioriza data-src
        $imgUrl = '';
        if (preg_match('/data-src=["\'](https:\/\/[^"\']+(static|produto)[^"\']+)["\']/', $block, $match)) {
            $imgUrl = $match[1];
        } elseif (preg_match('/src=["\'](https:\/\/[^"\']+(static|produto)[^"\']+)["\']/', $block, $match)) {
            $imgUrl = $match[1];
        }

        $imageBase64 = '';
        if ($imgUrl && !str_contains($imgUrl, 'data:image') && !str_contains($imgUrl, 'placeholder')) {
            try {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
                    ]
                ]);
                $imgData = @file_get_contents($imgUrl, false, $ctx);
                if ($imgData) $imageBase64 = base64_encode($imgData);
            } catch (\Throwable $e) {}
        }

        if ($name && $code) {
            $products[] = [
                'nome' => $name,
                'codigo' => $code,
                'preco' => $priceClean, // Agora numérico pura (ex: 119.57)
                'custo' => $priceClean, // Alias para garantir compatibilidade
                'unidade' => 'PC/1',
                'imageBase64' => $imageBase64,
                'imageUrl' => $imgUrl
            ];
        }
    }

    public function login(string $u, string $p) { return true; }
    public function scrape(string $url) { return []; }
}
