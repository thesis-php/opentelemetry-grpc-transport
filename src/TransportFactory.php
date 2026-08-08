<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc;

use Amp\Socket\Certificate;
use Google\Rpc\Code;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use Thesis\Grpc\Client;
use Thesis\Grpc\Compression;
use Thesis\Grpc\Metadata;
use Thesis\Grpc\Retry;
use Thesis\OpenTelemetry\Grpc\Internal\Endpoint;
use Thesis\OpenTelemetry\Grpc\Internal\RawMessageEncoder;

/**
 * Builds {@see Transport} instances on top of a pure-PHP gRPC client.
 *
 * Everything the OTLP configuration surface knows about — endpoint, TLS, compression,
 * retries, timeout — is applied on top of the supplied builder. Everything it does not
 * know about — load balancing, endpoint resolvers, connection limits, extra interceptors —
 * belongs on that builder, which is left untouched and reused for every created transport.
 *
 * @api
 */
final readonly class TransportFactory implements TransportFactoryInterface
{
    private const int MILLIS_PER_SECOND = 1_000;

    public function __construct(
        private Client\Builder $builder = new Client\Builder(),
    ) {}

    /**
     * @return TransportInterface<'application/x-protobuf'>
     */
    #[\Override]
    public function create(
        string $endpoint,
        string $contentType = Transport::CONTENT_TYPE,
        array $headers = [],
        $compression = null,
        float $timeout = 10.,
        int $retryDelay = 100,
        int $maxRetries = 3,
        ?string $cacert = null,
        ?string $cert = null,
        ?string $key = null,
    ): TransportInterface {
        if ($contentType !== Transport::CONTENT_TYPE) {
            throw new \InvalidArgumentException(
                \sprintf('Unsupported content type "%s", the gRPC transport only supports "%s".', $contentType, Transport::CONTENT_TYPE),
            );
        }

        $target = Endpoint::parse($endpoint);

        $builder = $this->builder
            ->withHost($target->target)
            ->withEncoding(new RawMessageEncoder())
            ->withUnaryInterceptors(new Retry\Interceptor(new Retry\Config(
                maxAttempts: max($maxRetries + 1, 1),
                /** @see https://opentelemetry.io/docs/specs/otlp/#otlpgrpc-response */
                retryableCodes: [
                    Code::CANCELLED,
                    Code::DEADLINE_EXCEEDED,
                    Code::RESOURCE_EXHAUSTED,
                    Code::ABORTED,
                    Code::OUT_OF_RANGE,
                    Code::UNAVAILABLE,
                    Code::DATA_LOSS,
                ],
                backoff: new Retry\Backoff\Exponential(
                    base: $retryDelay / self::MILLIS_PER_SECOND,
                    max: $retryDelay * 2 ** max($maxRetries - 1, 0) / self::MILLIS_PER_SECOND,
                ),
            )))
            ->withTransferTimeout($timeout);

        $compressor = self::compressor($compression);
        if ($compressor !== null) {
            $builder = $builder->withCompression($compressor);
        }

        if ($target->secure) {
            $builder = $builder->withTransportCredentials(self::credentials($target->host, $cacert, $cert, $key));
        }

        return new Transport(
            $builder->build(),
            $target->method,
            new Metadata($headers)->withKey( // @phpstan-ignore argument.type
                Metadata\Timeout::milliseconds(max((int) ($timeout * self::MILLIS_PER_SECOND), 0)),
            ),
        );
    }

    /**
     * @param non-empty-string $peerName
     */
    private static function credentials(
        string $peerName,
        ?string $cacert,
        ?string $cert,
        ?string $key,
    ): Client\TransportCredentials {
        $credentials = new Client\TransportCredentials()
            ->withPeerName($peerName);

        if ($cacert !== null && $cacert !== '') {
            $credentials = $credentials->withCaCert($cacert);
        }

        if ($cert !== null && $cert !== '') {
            $credentials = $credentials->withCertificate(new Certificate($cert, $key));
        }

        return $credentials;
    }

    /**
     * @param string|string[]|null $compression
     */
    private static function compressor(null|string|array $compression): ?Compression\Compressor
    {
        foreach ((array) $compression as $algorithm) {
            $compressor = match ($algorithm) {
                self::COMPRESSION_GZIP => new Compression\GzipCompressor(),
                self::COMPRESSION_DEFLATE => new Compression\DeflateCompressor(),
                default => null,
            };

            if ($compressor !== null) {
                return $compressor;
            }
        }

        return null;
    }
}
