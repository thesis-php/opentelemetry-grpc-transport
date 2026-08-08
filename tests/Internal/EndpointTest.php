<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Internal;

use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Data\DataProvider;
use Testo\Test;

final class EndpointTest
{
    /**
     * @param non-empty-string $endpoint
     */
    #[DataProvider('provideParseCases')]
    #[Test]
    public function parse(
        string $endpoint,
        string $target,
        string $method,
        string $host,
        bool $secure,
    ): void {
        $parsed = Endpoint::parse($endpoint);

        Assert::same($parsed->target, $target);
        Assert::same($parsed->method, $method);
        Assert::same($parsed->host, $host);
        Assert::same($parsed->secure, $secure);
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function provideParseCases(): iterable
    {
        yield 'http with port' => [
            'http://collector:4317/opentelemetry.proto.collector.trace.v1.TraceService/Export',
            'collector:4317',
            '/opentelemetry.proto.collector.trace.v1.TraceService/Export',
            'collector',
            false,
        ];

        yield 'https with port' => [
            'https://collector:4317/opentelemetry.proto.collector.logs.v1.LogsService/Export',
            'collector:4317',
            '/opentelemetry.proto.collector.logs.v1.LogsService/Export',
            'collector',
            true,
        ];

        yield 'http without port falls back to 80' => [
            'http://collector/pkg.Service/Method',
            'collector:80',
            '/pkg.Service/Method',
            'collector',
            false,
        ];

        yield 'https without port falls back to 443' => [
            'https://collector/pkg.Service/Method',
            'collector:443',
            '/pkg.Service/Method',
            'collector',
            true,
        ];

        yield 'fully qualified host' => [
            'https://otel-collector.observability.svc.cluster.local:4317/pkg.Service/Method',
            'otel-collector.observability.svc.cluster.local:4317',
            '/pkg.Service/Method',
            'otel-collector.observability.svc.cluster.local',
            true,
        ];

        yield 'ipv4 host' => [
            'http://127.0.0.1:4317/pkg.Service/Method',
            '127.0.0.1:4317',
            '/pkg.Service/Method',
            '127.0.0.1',
            false,
        ];

        yield 'ipv6 host keeps its brackets' => [
            'http://[::1]:4317/pkg.Service/Method',
            '[::1]:4317',
            '/pkg.Service/Method',
            '[::1]',
            false,
        ];

        yield 'ipv6 host without port' => [
            'https://[2001:db8::1]/pkg.Service/Method',
            '[2001:db8::1]:443',
            '/pkg.Service/Method',
            '[2001:db8::1]',
            true,
        ];
    }

    /**
     * @param non-empty-string $endpoint
     */
    #[DataProvider('provideParseThrowsCases')]
    #[ExpectException(\InvalidArgumentException::class)]
    #[Test]
    public function throws(string $endpoint): void
    {
        Endpoint::parse($endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideParseThrowsCases(): iterable
    {
        yield 'empty string' => [''];
        yield 'no scheme' => ['collector:4317/pkg.Service/Method'];
        yield 'scheme relative' => ['//collector:4317/pkg.Service/Method'];
        yield 'no host' => ['https:///pkg.Service/Method'];
        yield 'no path' => ['http://collector:4317'];
        yield 'root path only' => ['http://collector:4317/'];
        yield 'path without a method' => ['http://collector:4317/pkg.Service'];
        yield 'path with a trailing segment' => ['http://collector:4317/pkg.Service/Method/extra'];
        yield 'path with a trailing slash' => ['http://collector:4317/pkg.Service/Method/'];
        yield 'grpc scheme' => ['grpc://collector:4317/pkg.Service/Method'];
        yield 'dns scheme' => ['dns://collector:4317/pkg.Service/Method'];
        yield 'unix scheme' => ['unix:///var/run/otel.sock'];
    }
}
