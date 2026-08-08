<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Internal;

use Thesis\Grpc\Client\Target;

/**
 * An OTLP endpoint is a URL whose scheme decides between plaintext and TLS and whose
 * path is the fully qualified gRPC method, e.g.
 * "https://collector:4317/opentelemetry.proto.collector.trace.v1.TraceService/Export".
 *
 * The scheme is consumed here rather than handed to {@see Target}:
 * "http"/"https" are not gRPC naming schemes, and the TLS decision they carry has no
 * representation in a target string.
 *
 * @internal
 */
final readonly class Endpoint
{
    private const int DEFAULT_INSECURE_PORT = 80;
    private const int DEFAULT_SECURE_PORT = 443;

    /**
     * @param non-empty-string $target authority in the "host:port" form understood by {@see Target}
     * @param non-empty-string $method fully qualified gRPC method, leading slash included
     * @param non-empty-string $host authority without the port, used as the TLS peer name
     */
    private function __construct(
        public string $target,
        public string $method,
        public string $host,
        public bool $secure,
    ) {}

    /**
     * @throws \InvalidArgumentException
     */
    public static function parse(string $endpoint): self
    {
        $parts = parse_url($endpoint);

        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new \InvalidArgumentException(
                \sprintf('Endpoint "%s" has to contain a scheme, a host and a path.', $endpoint),
            );
        }

        $secure = match ($parts['scheme']) {
            'http' => false,
            'https' => true,
            default => throw new \InvalidArgumentException(
                \sprintf('Endpoint "%s" contains an unsupported scheme "%s", expected "http" or "https".', $endpoint, $parts['scheme']),
            ),
        };

        $method = $parts['path'];

        if ($method === '' || substr_count($method, '/') !== 2) {
            throw new \InvalidArgumentException(
                \sprintf('Endpoint path "%s" is not a valid gRPC method.', $method),
            );
        }

        $host = $parts['host'];

        if ($host === '') {
            throw new \InvalidArgumentException(
                \sprintf('Endpoint "%s" has to contain a host.', $endpoint),
            );
        }

        $port = $parts['port'] ?? ($secure ? self::DEFAULT_SECURE_PORT : self::DEFAULT_INSECURE_PORT);

        return new self("{$host}:{$port}", $method, $host, $secure);
    }
}
