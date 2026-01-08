<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\DomCrawler\Crawler;

class HayamaxScraperService
{
    private Client $client;
    private CookieJar $cookieJar;

    public function __construct()
    {
        $this->cookieJar = new CookieJar();
        $this->client = new Client([
            'cookies' => $this->cookieJar,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
            'allow_redirects' => true,
        ]);
    }

    public function login(string $user, string $password): bool
    {
        try {
            // 1. Get login page to grab initial cookies
            $this->client->get('https://loja.hayamax.com.br/entrar-cliente');

            // 2. Perform Login (Standard POST)
            $response = $this->client->post('https://loja.hayamax.com.br/mod/eco/User/submitLogin', [
                'form_params' => [
                    'customer[stcd1]' => $user,
                    'customer[password]' => $password,
                ],
                'headers' => [
                    'Origin' => 'https://loja.hayamax.com.br',
                    'Referer' => 'https://loja.hayamax.com.br/entrar-cliente',
                    // 'X-Requested-With' => 'XMLHttpRequest', // Removed to simulate standard browser submit
                ]
            ]);

            $content = (string)$response->getBody();
            
            // If we are redirected to the homepage or dashboard, the content will be that page.
            // Check for login success markers.
            $isLoggedIn = str_contains($content, 'Sair') || 
                         str_contains($content, 'Minha Conta') || 
                         str_contains($content, 'meus-pedidos');

            // Debug: If not logged in immediately, try fetching a protected page.
            if (!$isLoggedIn) {
                $checkResponse = $this->client->get('https://loja.hayamax.com.br/minha-conta');
                $checkContent = (string)$checkResponse->getBody();
                
                if (str_contains($checkContent, 'Meus Pedidos') || str_contains($checkContent, 'Sair')) {
                    return true;
                }
                
                // If we are here, login failed. Throw exception with debug info.
                // Truncate content related to failure for readability
                $debugContent = mb_substr(strip_tags($content), 0, 200); 
                throw new \Exception("Login não identificado. Resp: " . $debugContent);
            }

            return true;
        } catch (\Throwable $e) {
            // Re-throw so controller can return the message
            throw new \Exception("Erro no Login Hayamax: " . $e->getMessage());
        }
    }

    public function scrape(string $url): array
    {
        try {
            $response = $this->client->get($url);
            $html = (string)$response->getBody();
            
            $crawler = new Crawler($html);
            $products = [];

            // Targeted crawling for Hayamax Grid
            // Targeted crawling for Hayamax Grid - Tentando vários seletores comuns
            $crawler->filter('.col-6.col-sm-4.col-md-3, .product-item, .card-product, .item')->each(function (Crawler $node) use (&$products) {
                try {
                    // Nome: tentar vários seletores
                    $name = '';
                    if ($node->filter('p')->count() > 0) {
                        $name = $node->filter('p')->first()->text('');
                    } elseif ($node->filter('.product-name')->count() > 0) {
                        $name = $node->filter('.product-name')->text('');
                    } elseif ($node->filter('h3')->count() > 0) {
                        $name = $node->filter('h3')->text('');
                    }

                    // Código e Preço (extrair do texto completo do bloco)
                    $fullText = $node->text('');
                    
                    preg_match('/Cód\.\s*(\d+)/', $fullText, $matches);
                    $code = $matches[1] ?? '';
                    
                    preg_match('/R\$\s*[\d,.]+/', $fullText, $priceMatches);
                    $price = $priceMatches[0] ?? 'Indisponível';
                    
                    // Imagem: pegar a primeira imagem válida dentro do bloco
                    $imageUrl = '';
                    $node->filter('img')->each(function (Crawler $imgNode) use (&$imageUrl) {
                        if (empty($imageUrl)) {
                            $src = $imgNode->attr('src');
                            if ($src && !str_contains($src, 'placeholder') && (str_contains($src, 'foto') || str_contains($src, 'produto'))) {
                                $imageUrl = $src;
                            }
                        }
                    });

                    // Fallback para qualquer imagem se não achar filtro específico
                    if (empty($imageUrl) && $node->filter('img')->count() > 0) {
                        $imageUrl = $node->filter('img')->first()->attr('src');
                    }

                    $imageBase64 = '';
                    if ($imageUrl) {
                        try {
                            // Se a URL for relativa, adicionar domínio
                            if (str_starts_with($imageUrl, '/')) {
                                $imageUrl = 'https://loja.hayamax.com.br' . $imageUrl;
                            }
                            $imgData = (string)$this->client->get($imageUrl)->getBody();
                            $imageBase64 = base64_encode($imgData);
                        } catch (\Throwable $e) {}
                    }
                    
                    if ($name && $code) {
                        $products[] = [
                            'nome' => trim($name),
                            'codigo' => $code,
                            'preco' => $price,
                            'unidade' => 'PC/1',
                            'imageBase64' => $imageBase64,
                        ];
                    }
                } catch (\Throwable $e) {
                    // Skip failed individual items
                }
            });

            return $products;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
