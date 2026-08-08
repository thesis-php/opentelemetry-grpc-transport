<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Example;

use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Thesis\OpenTelemetry\Grpc\TransportFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4317';

$transport = new TransportFactory()->create($endpoint . OtlpUtil::method(Signals::TRACE));

$tracerProvider = new TracerProvider(
    new SimpleSpanProcessor(
        new SpanExporter($transport),
    ),
);

$tracer = $tracerProvider->getTracer('thesis/opentelemetry-grpc-transport');

echo "Starting OTLP gRPC example, exporting to {$endpoint}", \PHP_EOL;

$root = $tracer->spanBuilder('root')->startSpan();
$scope = $root->activate();

try {
    for ($i = 0; $i < 3; ++$i) {
        $span = $tracer->spanBuilder("loop-{$i}")->startSpan();

        $span
            ->setAttribute('remote_ip', '1.2.3.4')
            ->setAttribute('country', 'USA');

        $span->addEvent('found_login', [
            'id' => $i,
            'username' => "otuser{$i}",
        ]);

        $span->end();
    }
} finally {
    $scope->detach();
    $root->end();
}

// Flushes the pending spans and closes the connection.
$tracerProvider->shutdown();

echo 'OTLP gRPC example complete!', \PHP_EOL;
