<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return view('welcome');
})->name('login');

Route::get('/test-log', function () {
    \Log::info('Testing log output from Laravel.');
    throw new \Exception('Test exception');
});