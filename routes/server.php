<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OffsiteBackups\Http\Controllers\ClientController;

Route::get('/', [ClientController::class, 'index']);
Route::post('/copies/{uuid}/restore', [ClientController::class, 'restore'])->whereUuid('uuid');
Route::post('/copies/{uuid}/download', [ClientController::class, 'download'])->whereUuid('uuid')->middleware('throttle:10,1');
