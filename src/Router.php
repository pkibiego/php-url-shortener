<?php

declare(strict_types=1);

/**
 * Minimal request router.
 * Parses $_SERVER['REQUEST_URI'] and $_SERVER['REQUEST_METHOD'].
 */
class Router
{
    private string $method;
    private string $uri;
    private ?string $path;

    public function __construct(?array $server = null)
    {
        $server = $server ?? $_SERVER;
        $this->method = $server['REQUEST_METHOD'] ?? 'GET';
        $this->uri = $server['REQUEST_URI'] ?? '/';
        $this->path = parse_url($this->uri, PHP_URL_PATH);
    }

    /**
     * Get the HTTP method.
     */
    public function getMethod(): string
    {
        return strtoupper($this->method);
    }

    /**
     * Get the request path.
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * Check if current route matches.
     */
    public function matches(string $method, string $pattern): bool
    {
        if ($this->getMethod() !== strtoupper($method)) {
            return false;
        }
        return $this->path === $pattern;
    }

    /**
     * Check if current route matches with a code parameter.
     * Pattern should be like '/api/stats/{code}' or '/{code}'.
     * Returns ['code' => 'value'] or null if no match.
     */
    public function matchesWithCode(string $method, string $pattern): ?array
    {
        if ($this->getMethod() !== strtoupper($method)) {
            return null;
        }

        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($this->path ?? '', '/'));

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{(.+)\}$/', $part, $match)) {
                $paramName = $match[1];
                if ($paramName === 'code') {
                    // Code must be exactly 8 hex characters
                    if (!preg_match('/^[a-f0-9]{8}$/', $pathParts[$i])) {
                        return null;
                    }
                    $params['code'] = $pathParts[$i];
                } else {
                    $params[$paramName] = $pathParts[$i];
                }
            } elseif ($part !== $pathParts[$i]) {
                return null;
            }
        }

        return $params;
    }

    /**
     * Get all routing results for registered routes.
     * Returns ['matched' => bool, 'handler' => ?callable, 'params' => ?array].
     */
    public function route(array $routes): array
    {
        foreach ($routes as $route) {
            [$method, $pattern, $handler] = $route;

            // Handle routes with {code} parameter
            if (strpos($pattern, '{code}') !== false) {
                $params = $this->matchesWithCode($method, $pattern);
                if ($params !== null) {
                    return [
                        'matched' => true,
                        'handler' => $handler,
                        'params' => $params,
                    ];
                }
            } elseif ($this->matches($method, $pattern)) {
                return [
                    'matched' => true,
                    'handler' => $handler,
                    'params' => [],
                ];
            }
        }

        return [
            'matched' => false,
            'handler' => null,
            'params' => null,
        ];
    }
}
