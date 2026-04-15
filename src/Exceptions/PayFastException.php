<?php

namespace HandycatsDev\CashierPayFast\Exceptions;

use Exception;

class PayFastException extends Exception
{
    /**
     * The full error envelope from PayFast.
     *
     * @var array<string, mixed>
     */
    protected array $error = [];

    /**
     * Get the error envelope from PayFast.
     *
     * @return array<string, mixed>
     */
    public function getError(): array
    {
        return $this->error;
    }

    /**
     * Set the error envelope from PayFast.
     *
     * @param  array<string, mixed>  $error
     */
    public function setError(array $error): self
    {
        $this->error = $error;

        return $this;
    }
}
