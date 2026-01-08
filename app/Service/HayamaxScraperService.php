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
        
        // 1. Isolar os blocos de produtos (considerando as classes de grid da Hayamax)
        preg_match_all('/<div[^>]*class="[^"]*(col-|card-product|product-item|product-container)[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/s', $html, $blocks);

        if (empty($blocks[2])) {
            // Fallback: tentar um padrão genérico baseado na estrutura de texto do código
            preg_match_all('/<div[^>]*>(.*?)Cód\.\s*\d+.*?<\/div>/s', $html, $blocks);
        }

        foreach ($blocks[0] as $block) {
            // Extrair Código (ex: Cód. 74168)
            preg_match('/Cód\.\s*(\d+)/', $block, $codeMatch);
            $code = $codeMatch[1] ?? null;

            if (!$code) continue;

            // Extrair Preço (ex: R$ 52,15)
            preg_match('/R\$\s*([\d,.]+)/', $block, $priceMatch);
            $price = $priceMatch[0] ?? 'Indisponível';

            // Extrair Nome
            $cleanBlock = strip_tags($block);
            $lines = array_map('trim', explode("\n", $cleanBlock));
            $name = '';
            foreach ($lines as $line) {
                if (strlen($line) > 10 && !str_contains($line, 'Cód.') && !str_contains($line, 'R$') && !str_contains($line, 'Estoque')) {
                    $name = $line;
                    break;
                }
            }

            // EXTRAÇÃO DA IMAGEM (Lazy Load Support)
            // Procurar por data-src primeiro, depois src
            $imgUrl = '';
            if (preg_match('/data-src=["\']([^"\']+)["\']/', $block, $match)) {
                $imgUrl = $match[1];
            } elseif (preg_match('/src=["\']([^"\']+)["\']/', $block, $match)) {
                $imgUrl = $match[1];
            }

            // Limpeza da URL
            if ($imgUrl && !str_contains($imgUrl, 'http')) {
                $imgUrl = 'https://loja.hayamax.com.br' . (str_starts_with($imgUrl, '/') ? '' : '/') . $imgUrl;
            }

            // Converter para Base64 para compatibilidade com o Modal do Frontend
            $imageBase64 = '';
            if ($imgUrl && !str_contains($imgUrl, '.gif') && !str_contains($imgUrl, 'placeholder')) {
                try {
                    // Timeout curto para não travar o processo
                    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
                    $imgData = @file_get_contents($imgUrl, false, $ctx);
                    if ($imgData) {
                        $imageBase64 = base64_encode($imgData);
                    }
                } catch (\Throwable $e) {
                    // Silenciar erros de download, apenas segue sem foto
                }
            }

            if ($name && $code) {
                $products[] = [
                    'nome' => $name,
                    'codigo' => $code,
                    'preco' => $price,
                    'unidade' => 'PC/1',
                    'imageBase64' => $imageBase64,
                    'imageUrl' => $imgUrl
                ];
            }
        }

        return $products;
    }

    public function login(string $u, string $p) { return true; }
    public function scrape(string $url) { return []; }
}
