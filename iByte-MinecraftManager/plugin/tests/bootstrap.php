<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IByte\\MinecraftManager\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $callback, string $reason): void
{
    try { $callback(); }
    catch (IByte\MinecraftManager\Support\Failure $error) {
        check($error->reason === $reason, 'Expected ' . $reason . ', received ' . $error->reason);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
}

final class FixtureTransport implements IByte\MinecraftManager\Catalog\JsonTransport
{
    public array $calls = [];
    public function __construct(public array $responses) {}
    public function post(string $provider, string $path, array $body): array
    {
        return $this->get($provider, $path, $body);
    }
    public function get(string $provider, string $path, array $query = []): array
    {
        $this->calls[] = [$provider, $path, $query];
        $key = $provider . $path;
        if (!array_key_exists($key, $this->responses)) { throw new RuntimeException('Unexpected fixture request: ' . $key); }
        $response = $this->responses[$key];
        if ($response instanceof Throwable) { throw $response; }
        return $response instanceof Closure ? $response($query) : $response;
    }
}
