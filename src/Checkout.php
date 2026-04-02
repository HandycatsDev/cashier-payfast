<?php

namespace HandycatsDev\CashierPayFast;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\RedirectResponse;
use JsonSerializable;

class Checkout implements Arrayable, JsonSerializable
{
    protected array $custom = [];

    protected ?string $returnUrl = null;

    protected ?string $cancelUrl = null;

    protected ?string $notifyUrl = null;

    public function __construct(
        protected PayFastClient $client,
        protected array $params = []
    ) {
        try {
            $this->returnUrl = config('cashier.return_url');
            $this->cancelUrl = config('cashier.cancel_url');
            $this->notifyUrl = config('cashier.notify_url');
        } catch (\Exception) {
            // No Laravel application container available (e.g. unit tests)
        }
    }

    public static function make(array $params = []): static
    {
        return new static(app(PayFastClient::class), $params);
    }

    public function returnTo(string $url): static
    {
        $this->returnUrl = $url;

        return $this;
    }

    public function cancelTo(string $url): static
    {
        $this->cancelUrl = $url;

        return $this;
    }

    public function notifyTo(string $url): static
    {
        $this->notifyUrl = $url;

        return $this;
    }

    public function customData(array $custom): static
    {
        $this->custom = array_merge($this->custom, $custom);

        return $this;
    }

    public function fields(): array
    {
        $data = array_merge($this->params, array_filter([
            'return_url' => $this->returnUrl,
            'cancel_url' => $this->cancelUrl,
            'notify_url' => $this->notifyUrl,
        ]));

        if (! empty($this->custom)) {
            $data['custom_str1'] = json_encode($this->custom);
        }

        return $this->client->buildPaymentData($data);
    }

    public function url(): string
    {
        return $this->client->processUrl();
    }

    public function redirect(): RedirectResponse
    {
        return new RedirectResponse($this->url().'?'.http_build_query($this->fields()));
    }

    public function getCustomData(): array
    {
        return $this->custom;
    }

    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }

    public function jsonSerialize(): mixed
    {
        return $this->fields();
    }

    public function toArray(): array
    {
        return $this->fields();
    }
}
