# thesis/opentelemetry-grpc-transport

A gRPC transport for [opentelemetry-php](https://github.com/open-telemetry/opentelemetry-php) built on
[thesis/grpc-client](https://github.com/thesis-php/grpc): a drop-in replacement for
`open-telemetry/transport-grpc` that needs no `ext-grpc`, because the whole gRPC stack — HTTP/2 framing,
compression, TLS, load balancing — is plain PHP running on [amphp](https://amphp.org).

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Configuration](#configuration)
- [Bringing your own client](#bringing-your-own-client)
- [Example](#example)

## Installation

```shell
composer require thesis/opentelemetry-grpc-transport
```

## Usage

`TransportFactory` implements `TransportFactoryInterface`, so it plugs into any OTLP exporter in place
of the one shipped by the contrib package. The endpoint is a URL whose path is the fully qualified
gRPC method of the signal:

```php
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use Thesis\OpenTelemetry\Grpc\TransportFactory;

$transport = new TransportFactory()->create(
    'http://collector:4317' . OtlpUtil::method(Signals::TRACE),
);

$exporter = new SpanExporter($transport);
```

## Bringing your own client

The factory only applies what OTLP knows about. Load balancing, endpoint resolvers, connection limits
and your own interceptors belong on a `Client\Builder`, which the factory takes as a constructor
argument, leaves untouched and reuses for every transport it creates:

```php
use Thesis\Grpc\Client;
use Thesis\OpenTelemetry\Grpc\TransportFactory;

$factory = new TransportFactory(
    new Client\Builder()
        ->withLoadBalancer(new Client\LoadBalancer\RoundRobinFactory())
        ->withConnectionLimit(4),
);
```

The host, the encoder, the retry interceptor, compression and TLS are set by the factory on top of it
and override whatever the builder had for those.

## Example

[`examples/otlp_grpc.php`](examples/otlp_grpc.php) exports a handful of spans to a collector. A Jaeger
all-in-one is defined in `compose.yaml` behind the `example` profile, so one target brings it up and
runs the example against it:

```shell
make example
```

The spans then show up at http://localhost:16686 under the `unknown_service:php` service.

Against a collector you are running yourself:

```shell
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4317 php examples/otlp_grpc.php
```

It needs `open-telemetry/exporter-otlp` for the exporter itself.
