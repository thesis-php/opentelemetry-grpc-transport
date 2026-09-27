<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc;

use Testo\Assert;
use Testo\Test;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc\Client;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Metadata;
use Thesis\Grpc\RpcType;
use Thesis\OpenTelemetry\Grpc\Fake\FakeClient;
use Thesis\OpenTelemetry\Grpc\Internal\RawMessage;
use function Amp\delay;

final class TransportTest
{
    private const string METHOD = '/opentelemetry.proto.collector.trace.v1.TraceService/Export';

    #[Test]
    public function sendReturnsTheResponsePayload(): void
    {
        $transport = self::transport(new FakeClient(static fn(): string => 'pong'));

        Assert::same($transport->send('ping')->await(), 'pong');
    }

    #[Test]
    public function sendPassesTheCallDetailsToTheClient(): void
    {
        $client = new FakeClient();
        $md = new Metadata(['x-tenant' => 'acme']);

        self::transport($client, $md)->send('ping')->await();

        Assert::count($client->invocations, 1);

        $invocation = $client->invocations[0]; // @phpstan-ignore offsetAccess.notFound

        Assert::same($invocation->payload, 'ping');
        Assert::same($invocation->invoke->method, self::METHOD);
        Assert::same($invocation->invoke->output, RawMessage::class);
        Assert::same($invocation->invoke->type, RpcType::Unary);
        Assert::same($invocation->md, $md);
    }

    #[Test]
    public function shutdownClosesTheClientOnce(): void
    {
        $client = new FakeClient();
        $transport = self::transport($client);

        Assert::true($transport->shutdown());
        Assert::same($client->closes, 1);

        Assert::false($transport->shutdown());
        Assert::same($client->closes, 1);
    }

    #[Test]
    public function shutdownAwaitsExportsThatAreStillInFlight(): void
    {
        $client = new FakeClient(static function (string $payload): string {
            delay(0.01);

            return $payload;
        });

        $transport = self::transport($client);

        $transport->send('ping');

        Assert::true($transport->shutdown());
        Assert::count($client->invocations, 1);
        Assert::true($client->invocations[0]->settled); // @phpstan-ignore offsetAccess.notFound
    }

    #[Test]
    public function shutdownReportsFailedExportsAndStillClosesTheClient(): void
    {
        $client = new FakeClient(
            static fn(): string => throw new InvokeError(Code::UNAVAILABLE),
        );

        $transport = self::transport($client);
        $transport->send('ping');

        Assert::false($transport->shutdown());
        Assert::same($client->closes, 1);
    }

    #[Test]
    public function forceFlushAfterShutdownFails(): void
    {
        $transport = self::transport(new FakeClient());
        $transport->shutdown();

        Assert::false($transport->forceFlush());
    }

    private static function transport(Client $client, Metadata $md = new Metadata()): Transport
    {
        return new Transport($client, self::METHOD, $md);
    }
}
