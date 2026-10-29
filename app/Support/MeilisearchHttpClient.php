<?php

namespace App\Support;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MeilisearchHttpClient implements ClientInterface
{
    private Client $search;

    private Client $operations;

    public function __construct()
    {
        $this->search = new Client([
            'connect_timeout' => $this->timeout('search_connect_timeout', 1),
            'timeout' => $this->timeout('search_timeout', 3),
        ]);
        $this->operations = new Client([
            'connect_timeout' => $this->timeout('operations_connect_timeout', 10),
            'timeout' => $this->timeout('operations_timeout', 300),
        ]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $isSearch = ($request->getMethod() === 'POST'
            && preg_match('~/(?:indexes/[^/]+/(?:search|facet-search)|multi-search)$~', $path) === 1)
            || ($request->getMethod() === 'GET' && preg_match('~/indexes/[^/]+/settings/pagination$~', $path) === 1);

        return ($isSearch ? $this->search : $this->operations)->sendRequest($request);
    }

    private function timeout(string $setting, float $default): float
    {
        $seconds = (float) config('scout.meilisearch.'.$setting, $default);

        return is_finite($seconds) ? max(0.1, $seconds) : $default;
    }
}
