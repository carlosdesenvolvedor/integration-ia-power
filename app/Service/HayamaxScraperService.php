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
            // 1. Get login page to grab any initial cookies if needed
            $this->client->get('https://loja.hayamax.com.br/entrar-cliente');

            // 2. Perform Login
            $response = $this->client->post('https://loja.hayamax.com.br/entrar-cliente', [
                'form_params' => [
                    'customer[stcd1]' => $user,
                    'customer[password]' => $password,
                ],
            ]);

            $content = (string)$response->getBody();
            
            // Check if login was successful (usually redirects or shows logout/account name)
            return str_contains($content, 'Sair') || str_contains($content, 'Minha Conta');
        } catch (\Throwable $e) {
            return false;
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
            $crawler->filter('.col-6.col-sm-4.col-md-3')->each(function (Crawler $node) use (&$products) {
                try {
                    $name = $node->filter('p')->first()->text('');
                    $codeText = $node->text('');
                    preg_match('/Cód\.\s*(\d+)/', $codeText, $matches);
                    $code = $matches[1] ?? '';
                    
                    preg_match('/R\$\s*[\d,.]+/', $codeText, $priceMatches);
                    $price = $priceMatches[0] ?? 'Indisponível';
                    
                    $imageUrl = $node->filter('img')->first()->attr('src');
                    $imageBase64 = '';
                    if ($imageUrl) {
                        try {
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
