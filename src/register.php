<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc;

use OpenTelemetry\SDK\Registry;

Registry::registerTransportFactory('grpc', TransportFactory::class);
