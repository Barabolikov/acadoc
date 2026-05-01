<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FrontendAuthController;
use App\Http\Controllers\NormControlController;

Route::get('/', function () {
    return view('index');
});

// Маршрути для авторизації на фронтенді
Route::post('/login-process', [FrontendAuthController::class, 'login'])->name('frontend.login');
Route::post('/logout', [FrontendAuthController::class, 'logout'])->name('frontend.logout');

// Якщо неавторизований користувач лізе на закриту сторінку - кидаємо його на головну
Route::get('/login', function () {
    return redirect('/');
})->name('login');

// Всі маршрути всередині цієї групи вимагатимуть авторизації
Route::middleware(['auth'])->group(function () {
    Route::get('/norm-control', [NormControlController::class, 'index'])->name('norm-control.index');
});
