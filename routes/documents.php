<?php

use Illuminate\Support\Facades\Route;
use NetOS\Payments\Http\Controllers\DocumentController;

Route::prefix(config('payments.routes.documents_prefix'))
    ->middleware(['signed:relative', ...config('payments.routes.documents_middleware')])
    ->group(function (): void {
        Route::get('{document}', [DocumentController::class, 'show'])->name('payments.documents.show');
        Route::get('{document}/pdf', [DocumentController::class, 'pdf'])->name('payments.documents.pdf');
    });
