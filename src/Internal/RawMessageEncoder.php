<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Internal;

use Thesis\Grpc\Encoding\Encoder;

/**
 * @internal
 * @template-implements Encoder<RawMessage>
 */
final readonly class RawMessageEncoder implements Encoder
{
    #[\Override]
    public function name(): string
    {
        return 'proto';
    }

    #[\Override]
    public function encode(object $request): string
    {
        return $request->payload;
    }

    #[\Override]
    public function decode(string $buffer, string $classType): object
    {
        return new $classType($buffer);
    }
}
