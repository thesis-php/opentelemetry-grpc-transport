<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Internal;

/**
 * @internal
 */
final readonly class RawMessage
{
    public function __construct(
        public string $payload,
    ) {}
}
