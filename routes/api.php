<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/


Route::post('/store',[\App\Http\Controllers\RecordController::class,'store']);
Route::get('/index',[\App\Http\Controllers\RecordController::class,'index']);
Route::get('/show',[\App\Http\Controllers\RecordController::class,'show']);
