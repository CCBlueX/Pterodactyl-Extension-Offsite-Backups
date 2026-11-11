<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OffsiteBackups\Http\Controllers\StreamController;

Route::get('/stream/{secret}', StreamController::class)->where('secret', '[A-Za-z0-9]{64}')->middleware('throttle:30,1')->name('stream');
