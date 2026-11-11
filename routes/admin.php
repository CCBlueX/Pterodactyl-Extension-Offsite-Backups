<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use OffsiteBackups\Http\Controllers\CopyController;
use OffsiteBackups\Http\Controllers\DestinationController;
use OffsiteBackups\Http\Controllers\OverviewController;
use OffsiteBackups\Http\Controllers\RuleController;
use OffsiteBackups\Http\Controllers\ServerController;
use OffsiteBackups\Http\Controllers\StorageController;

Route::get('/overview', [OverviewController::class, 'overview']);
Route::get('/lookup', [OverviewController::class, 'lookup']);

Route::get('/destinations', [DestinationController::class, 'index']);
Route::post('/destinations', [DestinationController::class, 'store']);
Route::post('/destinations/test', [DestinationController::class, 'test'])->middleware('throttle:10,1');
Route::patch('/destinations/{id}', [DestinationController::class, 'update'])->whereNumber('id');
Route::delete('/destinations/{id}', [DestinationController::class, 'destroy'])->whereNumber('id');
Route::post('/destinations/{id}/test', [DestinationController::class, 'testExisting'])->whereNumber('id')->middleware('throttle:10,1');
Route::post('/destinations/{id}/default', [DestinationController::class, 'makeDefault'])->whereNumber('id');

Route::get('/storage', [StorageController::class, 'show']);
Route::put('/storage', [StorageController::class, 'update']);

Route::get('/rules', [RuleController::class, 'index']);
Route::post('/rules', [RuleController::class, 'store']);
Route::patch('/rules/{id}', [RuleController::class, 'update'])->whereNumber('id');
Route::delete('/rules/{id}', [RuleController::class, 'destroy'])->whereNumber('id');
Route::post('/rules/{id}/run', [RuleController::class, 'run'])->whereNumber('id');

Route::get('/copies', [CopyController::class, 'index']);
Route::post('/copies/bulk', [CopyController::class, 'bulk']);
Route::get('/copies/{id}', [CopyController::class, 'show'])->whereNumber('id');
Route::delete('/copies/{id}', [CopyController::class, 'destroy'])->whereNumber('id');
Route::post('/copies/{id}/retry', [CopyController::class, 'retry'])->whereNumber('id');
Route::post('/copies/{id}/check', [CopyController::class, 'check'])->whereNumber('id');
Route::post('/copies/{id}/download', [CopyController::class, 'download'])->whereNumber('id');
Route::post('/copies/{id}/restore', [CopyController::class, 'restore'])->whereNumber('id');

Route::get('/servers/{id}', [ServerController::class, 'show'])->whereNumber('id');
Route::put('/servers/{id}', [ServerController::class, 'update'])->whereNumber('id');
Route::post('/servers/{id}/copy', [ServerController::class, 'copy'])->whereNumber('id');
