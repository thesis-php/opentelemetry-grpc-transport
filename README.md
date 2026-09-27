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
composer require thesis/opentelemetry-grpc-transport open-telemetry/exporter-otlp
```

The package only provides the transport. The OTLP exporters that serialize spans, metrics and logs live
in `open-telemetry/exporter-otlp`.

### Replacing `open-telemetry/transport-grpc`

The package registers its `TransportFactory` in the SDK registry under the `grpc` protocol, the same
name `open-telemetry/transport-grpc` uses, so SDK autoconfiguration picks it up without any code
changes:

```shell
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4317
```

Do not install both packages at once: the registry keeps the factory registered first, so which
transport wins depends on the autoload order. Remove `open-telemetry/transport-grpc` (and `ext-grpc`
along with it, if nothing else needs it):

```shell
composer remove open-telemetry/transport-grpc
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

## Configuration

`TransportFactory::create()` takes the standard `TransportFactoryInterface` arguments:

| Argument        | Default                  | Description                                                                                                                                             |
|-----------------|--------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------|
| `$endpoint`     | —                        | `http://host[:port]/<method>` for plaintext, `https://host[:port]/<method>` for TLS. The port defaults to 80 / 443, so set `:4317` explicitly.          |
| `$contentType`  | `application/x-protobuf` | The only supported value, anything else throws `InvalidArgumentException`.                                                                              |
| `$headers`      | `[]`                     | Sent as gRPC metadata with every export.                                                                                                                |
| `$compression`  | `null`                   | `gzip` or `deflate`, or a list of algorithms where the first supported one is used. Anything else, `br` included, disables compression.                 |
| `$timeout`      | `10`                     | Seconds. A deadline for the whole export, retries and backoff included, also sent to the collector as `grpc-timeout`.                                   |
| `$retryDelay`   | `100`                    | Milliseconds before the first retry; the delay then grows exponentially up to `$retryDelay * 2 ** ($maxRetries - 1)`.                                   |
| `$maxRetries`   | `3`                      | Retries on top of the first attempt, only for the [retryable](https://opentelemetry.io/docs/specs/otlp/#otlpgrpc-response) codes such as `UNAVAILABLE`. |
| `$cacert`       | `null`                   | Path to the CA certificate that verifies the collector. Without it the system trust store is used.                                                      |
| `$cert`, `$key` | `null`                   | Path to the client certificate and its private key for mTLS.                                                                                            |

When the transport is built by SDK autoconfiguration, the exporter factory fills these from the usual
environment variables: `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`,
`OTEL_EXPORTER_OTLP_COMPRESSION`, `OTEL_EXPORTER_OTLP_TIMEOUT` and their per-signal
`OTEL_EXPORTER_OTLP_{TRACES,METRICS,LOGS}_*` variants. It does not pass retries or certificates, so
those keep their defaults. To set them, create the transport yourself and hand it to the exporter as
shown in [Usage](#usage).

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
