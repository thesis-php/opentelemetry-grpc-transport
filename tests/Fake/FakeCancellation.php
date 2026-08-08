<?php

declare(strict_types=1);

namespace Thesis\OpenTelemetry\Grpc\Fake;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;

final class FakeCancellation implements CancellationInterface
{
    /** @var array<string, \Closure(\Throwable): void> */
    private array $callbacks = [];

    private ?\Throwable $cancelled = null;

    /** @var non-empty-string */
    private string $nextId = 'a';

    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        $id = $this->nextId;
        $this->nextId = str_increment($this->nextId);

        if ($this->cancelled !== null) {
            $callback($this->cancelled);
        } else {
            $this->callbacks[$id] = $callback;
        }

        return $id;
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
        unset($this->callbacks[$id]);
    }

    public function cancel(?\Throwable $reason = null): void
    {
        if ($this->cancelled !== null) {
            return;
        }

        $reason ??= new \RuntimeException('Cancelled.');

        $this->cancelled = $reason;

        foreach ($this->callbacks as $callback) {
            $callback($reason);
        }

        $this->callbacks = [];
    }

    public function subscribers(): int
    {
        return \count($this->callbacks);
    }
}
