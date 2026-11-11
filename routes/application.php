<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OffsiteBackups\Http\Controllers\ApplicationController;

Route::get('/servers/{id}', [ApplicationController::class, 'show'])->whereNumber('id');
Route::patch('/servers/{id}', [ApplicationController::class, 'update'])->whereNumber('id');
Route::get('/servers/{id}/copies', [ApplicationController::class, 'copies'])->whereNumber('id');
