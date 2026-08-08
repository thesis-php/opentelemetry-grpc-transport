<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Internal;

/**
 * @internal
 */
enum State
{
    case Alive;
    case Dead;
}
