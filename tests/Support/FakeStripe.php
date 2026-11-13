<?php

namespace NetOS\Payments\Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP API: answers from responses registered per method and path, and records every request.
 */
class FakeStripe implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, headers: list<string>}> */
    public array $requests = [];

    /** @var array<string, list<array{body: array<string, mixed>, status: int}>> */
    private array $responses = [];

    /**
     * Queues a response for "METHOD /path"; the path may contain * as wildcard. The last response for a route repeats.
     *
     * @param array<string, mixed> $body
     */
    public function on(string $route, array $body, int $status = 200): self
    {
        $this->responses[$route][] = ['body' => $body, 'status' => $status];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $method = mb_strtoupper($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($absUrl, PHP_URL_QUERY), $query);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => is_array($params) ? $params + $query : $query, 'headers' => $headers];

        foreach ($this->responses as $route => &$queue) {
            [$routeMethod, $routePath] = explode(' ', $route, 2);
            $pattern = '#^' . str_replace('\*', '[^/]+', preg_quote($routePath, '#')) . '$#';

            if ($routeMethod === $method && preg_match($pattern, $path) === 1) {
                $response = count($queue) > 1 ? array_shift($queue) : $queue[0];

                return [json_encode($response['body'], JSON_THROW_ON_ERROR), $response['status'], ['request-id' => 'req_test']];
            }
        }

        throw new RuntimeException(sprintf('No fake Stripe response for %s %s', $method, $path));
    }

    /**
     * @return array{method: string, path: string, params: array<string, mixed>, headers: list<string>}
     */
    public function last(string $method, string $pathPattern): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['method'] === $method && preg_match('#' . $pathPattern . '#', $request['path']) === 1) {
                return $request;
            }
        }

        throw new RuntimeException(sprintf('No %s request to %s was made', $method, $pathPattern));
    }

    public function count(string $method, string $pathPattern): int
    {
        return count(array_filter(
            $this->requests,
            fn (array $request): bool => $request['method'] === $method && preg_match('#' . $pathPattern . '#', $request['path']) === 1,
        ));
    }
}
