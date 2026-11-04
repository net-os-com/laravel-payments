<?php

use Illuminate\Support\Facades\Route;
use NetOS\Payments\Http\Controllers\WebhookController;

Route::post(config('payments.webhook_path') . '/{provider}', WebhookController::class)
    ->name('payments.webhooks');
