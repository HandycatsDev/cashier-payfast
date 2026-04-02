<?php

namespace HandycatsDev\CashierPayFast;

use HandycatsDev\CashierPayFast\Concerns\ManagesCustomer;
use HandycatsDev\CashierPayFast\Concerns\ManagesSubscriptions;
use HandycatsDev\CashierPayFast\Concerns\ManagesTransactions;

trait Billable
{
    use ManagesCustomer;
    use ManagesSubscriptions;
    use ManagesTransactions;
}
