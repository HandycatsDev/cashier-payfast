<?php

namespace HandycatsDev\CashierPayFast\Components;

use HandycatsDev\CashierPayFast\Checkout;
use Illuminate\View\Component;

class Button extends Component
{
    public function __construct(
        public Checkout $checkout
    ) {
    }

    public function render()
    {
        return view('cashier::components.button');
    }
}
