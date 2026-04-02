<?php

namespace HandycatsDev\CashierPayFast\Components;

use HandycatsDev\CashierPayFast\Checkout as CheckoutInstance;
use Illuminate\View\Component;

class Checkout extends Component
{
    public function __construct(
        public CheckoutInstance $checkout,
        public string $id = 'cashier-checkout-form'
    ) {
    }

    public function fields(): array
    {
        return $this->checkout->fields();
    }

    public function action(): string
    {
        return $this->checkout->url();
    }

    public function render()
    {
        return view('cashier::components.checkout');
    }
}
