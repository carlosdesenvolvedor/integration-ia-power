<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

Router::get('/favicon.ico', function () {
    return '';
});

Router::post('/ai/analyze_pdf', 'App\Controller\AIController@analyzePdf');
Router::post('/ai/parse-hayamax', 'App\Controller\AIController@parseHayamaxHtml');
Router::post('/ai/parse_hayamax', 'App\Controller\AIController@parseHayamaxHtml');
Router::post('/ai/parse-hayamax-html', 'App\Controller\AIController@parseHayamaxHtml');
Router::post('/ai/parse_hayamax_html', 'App\Controller\AIController@parseHayamaxHtml');
