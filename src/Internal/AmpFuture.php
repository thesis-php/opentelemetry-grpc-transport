<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Internal;

use Amp\Future;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * @internal
 * @template-covariant T
 * @template-implements FutureInterface<T>
 */
final readonly class AmpFuture implements FutureInterface
{
    /**
     * @param Future<T> $future
     */
    public function __construct(
        private Future $future,
    ) {}

    #[\Override]
    public function await()
    {
        return $this->future->await();
    }

    #[\Override]
    public function map(\Closure $closure): FutureInterface
    {
        return new self($this->future->map($closure));
    }

    #[\Override]
    public function catch(\Closure $closure): FutureInterface
    {
        return new self($this->future->catch($closure));
    }
}
