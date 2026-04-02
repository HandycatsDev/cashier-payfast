<?php

namespace Workbench\App\Models;

use HandycatsDev\CashierPayFast\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;

    protected $guarded = [];
}
