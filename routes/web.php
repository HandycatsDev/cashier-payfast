<?php

use Illuminate\Support\Facades\Route;

Route::post('webhook', 'WebhookController')->name('cashier.webhook');
