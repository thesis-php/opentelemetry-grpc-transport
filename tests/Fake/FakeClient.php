<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Fake;

use Amp\Cancellation;
use Amp\NullCancellation;
use Thesis\Grpc\Client;
use Thesis\Grpc\ClientStream;
use Thesis\Grpc\Metadata;
use Thesis\OpenTelemetry\Grpc\Internal\RawMessage;

final class FakeClient implements Client
{
    /** @var list<FakeInvocation> */
    public private(set) array $invocations = [];

    public private(set) int $closes = 0;

    /** @var \Closure(string, Cancellation): string */
    private readonly \Closure $handler;

    /**
     * @param ?\Closure(string, Cancellation): string $handler
     */
    public function __construct(?\Closure $handler = null)
    {
        $this->handler = $handler ?? static fn(string $payload): string => $payload;
    }

    #[\Override]
    public function invoke(
        object $request,
        Client\Invoke $invoke,
        Metadata $md = new Metadata(),
        Cancellation $cancellation = new NullCancellation(),
    ): object {
        \assert($request instanceof RawMessage);

        $invocation = new FakeInvocation($request->payload, $invoke, $md, $cancellation);
        $this->invocations[] = $invocation;

        try {
            return new RawMessage(($this->handler)($request->payload, $cancellation)); // @phpstan-ignore return.type
        } finally {
            $invocation->settle();
        }
    }

    #[\Override]
    public function createStream(
        Client\Invoke $invoke,
        Metadata $md = new Metadata(),
        Cancellation $cancellation = new NullCancellation(),
    ): ClientStream {
        throw new \LogicException('The transport only issues unary calls.');
    }

    #[\Override]
    public function close(Cancellation $cancellation = new NullCancellation()): void
    {
        ++$this->closes;
    }
}
