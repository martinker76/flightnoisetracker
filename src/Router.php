<?php

declare(strict_types=1);

namespace App;

/**
 * Lightweight REST API router with support for path parameters.
 */
class Router
{
    /** @var array<string, array<array{pattern: string, handler: callable|array, params: string[]}>> */
    private array $routes = [];

    /**
     * Register a GET route.
     */
    public function get(string $path, array|callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, array|callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a route for a given HTTP method.
     */
    private function addRoute(string $method, string $path, array|callable $handler): void
    {
        // Extract parameter names from path (e.g., {id}, {icao24})
        $paramNames = [];
        if (preg_match_all('/\{(\w+)\}/', $path, $matches)) {
            $paramNames = $matches[1];
        }

        // Convert path to regex pattern
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[$method][] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'params' => $paramNames,
        ];
    }

    /**
     * Dispatch a request to the matching route handler.
     */
    public function dispatch(string $method, string $uri, array $config): void
    {
        // Strip query string from URI for matching
        $path = parse_url($uri, PHP_URL_PATH);

        // Remove trailing slash (except root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        if (!isset($this->routes[$method])) {
            $this->sendJson(['error' => 'Method not allowed', 'code' => 'METHOD_NOT_ALLOWED'], 405);
            return;
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract named parameters
                $params = [];
                foreach ($route['params'] as $paramName) {
                    $params[$paramName] = $matches[$paramName] ?? null;
                }

                $this->callHandler($route['handler'], $params, $config);
                return;
            }
        }

        $this->sendJson(['error' => 'Not found', 'code' => 'NOT_FOUND'], 404);
    }

    /**
     * Call the route handler (controller method or closure).
     */
    private function callHandler(array|callable $handler, array $params, array $config): void
    {
        if (is_array($handler)) {
            [$controllerClass, $method] = $handler;
            $controller = new $controllerClass($config);
            $controller->$method($params);
        } else {
            $handler($params, $config);
        }
    }

    /**
     * Send a JSON response and exit.
     */
    private function sendJson(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
