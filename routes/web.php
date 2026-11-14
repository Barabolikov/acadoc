<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FrontendAuthController;
use App\Http\Controllers\NormControlController;

Route::get('/', function () {
    return view('index');
});

// Маршрути для авторизації на фронтенді
Route::post('/login-process', [FrontendAuthController::class, 'login'])->middleware('throttle:10,1')->name('frontend.login');
Route::post('/register-process', [FrontendAuthController::class, 'register'])->middleware('throttle:5,1')->name('frontend.register');
Route::post('/logout', [FrontendAuthController::class, 'logout'])->name('frontend.logout');

// Якщо неавторизований користувач лізе на закриту сторінку - кидаємо його на головну
Route::get('/login', function () {
    return redirect('/');
})->name('login');

// Всі маршрути всередині цієї групи вимагатимуть авторизації
Route::middleware(['auth'])->group(function () {
    Route::get('/norm-control', [NormControlController::class, 'index'])->name('norm-control.index');
    Route::post('/norm-control/process', [NormControlController::class, 'process'])->name('norm-control.process');
    Route::post('/norm-control/download', [NormControlController::class, 'downloadReport'])->name('norm-control.download');
});
