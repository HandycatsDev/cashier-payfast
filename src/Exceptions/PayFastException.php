<?php

namespace HandycatsDev\CashierPayFast\Exceptions;

use Exception;

class PayFastException extends Exception
{
    protected array $error = [];

    public function getError(): array
    {
        return $this->error;
    }

    public function setError(array $error): self
    {
        $this->error = $error;

        return $this;
    }
}
