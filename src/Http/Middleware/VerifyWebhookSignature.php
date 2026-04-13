<?php

namespace HandycatsDev\CashierPayFast\Http\Middleware;

use Closure;
use HandycatsDev\CashierPayFast\PayFastClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next)
    {
        $client = app(PayFastClient::class);

        // Skip IP verification in sandbox mode (ngrok/tunnels use proxy IPs)
        if (! config('cashier.sandbox')) {
            if (! $client->isValidIp($request->ip())) {
                throw new AccessDeniedHttpException('Invalid ITN source IP.');
            }
        }

        if (! $client->validateSignature($request->all())) {
            throw new AccessDeniedHttpException('Invalid ITN signature.');
        }

        // Skip server confirmation in sandbox mode
        if (! config('cashier.sandbox')) {
            if (! $client->confirmItn($request->except('signature'))) {
                throw new AccessDeniedHttpException('ITN server confirmation failed.');
            }
        }

        return $next($request);
    }
}
