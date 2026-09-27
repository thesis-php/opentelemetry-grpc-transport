<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use Thesis\Grpc\Client;
use Thesis\Grpc\Metadata;
use Thesis\Grpc\RpcType;
use Thesis\OpenTelemetry\Grpc\Internal\RawMessage;
use function Amp\async;

/**
 * @api
 * @template-implements TransportInterface<'application/x-protobuf'>
 */
final class Transport implements TransportInterface
{
    use LogsMessagesTrait;
    public const string CONTENT_TYPE = 'application/x-protobuf';

    /** @var array<non-empty-string, Future<string>> */
    private array $futures = [];

    /** @var non-empty-string */
    private string $nextId = 'a';

    private Internal\State $state = Internal\State::Alive;

    /**
     * @param non-empty-string $method
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $method,
        private readonly Metadata $metadata,
    ) {}

    #[\Override]
    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    /**
     * @return FutureInterface<string>
     */
    #[\Override]
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->state !== Internal\State::Alive) {
            return new ErrorFuture(new \BadMethodCallException('Transport closed'));
        }

        $nextId = $this->nextId;
        $this->nextId = str_increment($this->nextId);

        $client = $this->client;
        $method = $this->method;
        $metadata = $this->metadata;
        $futures = &$this->futures;

        /** @var Future<string> $future */
        $future = async(static function () use (
            $payload,
            $cancellation,
            $nextId,
            $client,
            $method,
            $metadata,
            &$futures,
        ): string {
            $canceller = new DeferredCancellation();

            $cancellationId = $cancellation?->subscribe($canceller->cancel(...));

            try {
                $response = $client->invoke(
                    request: new RawMessage($payload),
                    invoke: new Client\Invoke(
                        method: $method,
                        output: RawMessage::class,
                        type: RpcType::Unary,
                    ),
                    md: $metadata,
                    cancellation: $canceller->getCancellation(),
                );

                return $response->payload;
            } finally {
                unset($futures[$nextId]);

                if ($cancellationId !== null) {
                    $cancellation->unsubscribe($cancellationId);
                }
            }
        });

        $this->futures[$nextId] = $future;

        return new Internal\AmpFuture($future);
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->state !== Internal\State::Alive) {
            return false;
        }

        $this->state = Internal\State::Dead;

        $canceller = new DeferredCancellation();

        $cancellationId = $cancellation?->subscribe($canceller->cancel(...));

        try {
            return $this->flush($canceller->getCancellation());
        } catch (CancelledException) {
            return false;
        } finally {
            // The client owns the connection, so it has to be released even when the flush was
            // cancelled: the transport is already dead and will never be shutdown a second time.
            try {
                $this->client->close();
            } catch (\Throwable $e) {
                self::logError('Failed to close the gRPC client', [
                    'exception' => $e,
                ]);
            }

            if ($cancellationId !== null) {
                $cancellation->unsubscribe($cancellationId);
            }
        }
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        if ($this->state === Internal\State::Dead) {
            return false;
        }

        $canceller = new DeferredCancellation();

        $cancellationId = $cancellation?->subscribe($canceller->cancel(...));

        try {
            return $this->flush($canceller->getCancellation());
        } catch (CancelledException) {
            return false;
        } finally {
            if ($cancellationId !== null) {
                $cancellation->unsubscribe($cancellationId);
            }
        }
    }

    /**
     * Awaits the exports that are in flight at the time of the call.
     *
     * @return bool whether all of them completed successfully
     * @throws CancelledException
     */
    private function flush(Cancellation $cancellation): bool
    {
        [$errors] = Future\awaitAll(array_values($this->futures), $cancellation);

        foreach ($errors as $error) {
            self::logError('Failed to export telemetry data', [
                'exception' => $error,
            ]);
        }

        return $errors === [];
    }
}
