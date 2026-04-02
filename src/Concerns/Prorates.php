<?php

namespace HandycatsDev\CashierPayFast\Concerns;

trait Prorates
{
    protected $prorationBehavior = 'prorated_next_billing_period';

    public function prorate()
    {
        $this->prorationBehavior = 'prorated_next_billing_period';

        return $this;
    }

    public function noProrate()
    {
        $this->prorationBehavior = 'full_next_billing_period';

        return $this;
    }

    public function prorateImmediately()
    {
        $this->prorationBehavior = 'prorated_immediately';

        return $this;
    }

    public function immediatelyWithoutProrate()
    {
        $this->prorationBehavior = 'full_immediately';

        return $this;
    }

    public function doNotBill()
    {
        $this->prorationBehavior = 'do_not_bill';

        return $this;
    }

    public function setProrationBehavior($prorationBehavior)
    {
        $this->prorationBehavior = $prorationBehavior;

        return $this;
    }
}
