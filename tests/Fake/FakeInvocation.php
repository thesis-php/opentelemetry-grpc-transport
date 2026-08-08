<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Fake;

use Amp\Cancellation;
use Thesis\Grpc\Client\Invoke;
use Thesis\Grpc\Metadata;

final class FakeInvocation
{
    public private(set) bool $settled = false;

    /**
     * @param Invoke<object, object> $invoke
     */
    public function __construct(
        public readonly string $payload,
        public readonly Invoke $invoke,
        public readonly Metadata $md,
        public readonly Cancellation $cancellation,
    ) {}

    public function settle(): void
    {
        $this->settled = true;
    }
}
