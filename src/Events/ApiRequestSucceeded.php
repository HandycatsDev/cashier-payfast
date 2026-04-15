<?php

namespace HandycatsDev\CashierPayFast\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ApiRequestSucceeded
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $statusCode,
        public readonly array $responseBody,
    ) {}
}
