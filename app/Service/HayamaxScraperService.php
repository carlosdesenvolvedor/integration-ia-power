<?php

declare(strict_types=1);

namespace App\Service;

class HayamaxScraperService
{
    /**
     * Analisa o HTML bruto enviado pelo frontend e extrai os produtos.
     * Esta versão usa PHP puro (Regex) para evitar conflitos de dependências no servidor.
     */
    public function parseHtml(string $html): array
    {
        $products = [];
        
        // 1. Isolar os blocos de produtos (geralmente dentro de col- ou card-)
        // Procuramos por padrões que se repetem no grid da Hayamax
        preg_match_all('/<div[^>]*class="[^"]*(col-|card-product|product-item)[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/s', $html, $blocks);

        if (empty($blocks[2])) {
            // Fallback: tentar um padrão mais genérico se o grid mudar
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

            // Extrair Nome (geralmente em um <p> ou <h3> antes do código)
            // Pegamos o texto limpo de tags dentro do bloco
            $cleanBlock = strip_tags($block);
            $lines = array_map('trim', explode("\n", $cleanBlock));
            $name = '';
            
            foreach ($lines as $line) {
                if (strlen($line) > 10 && !str_contains($line, 'Cód.') && !str_contains($line, 'R$')) {
                    $name = $line;
                    break;
                }
            }

            // Extrair URL da Imagem
            preg_match('/src="([^"]*(foto|produto)[^"]*)"/', $block, $imgMatch);
            $img = $imgMatch[1] ?? '';
            if ($img && str_starts_with($img, '/')) {
                $img = 'https://loja.hayamax.com.br' . $img;
            }

            if ($name && $code) {
                $products[] = [
                    'nome' => $name,
                    'codigo' => $code,
                    'preco' => $price,
                    'unidade' => 'PC/1',
                    'imageUrl' => $img
                ];
            }
        }

        return $products;
    }

    /**
     * Legado: Mantido para não quebrar injeções, mas redireciona para o novo parser
     */
    public function login(string $u, string $p) { return true; }
    public function scrape(string $url) { return []; }
}
