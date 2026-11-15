<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FrontendAuthController;
use App\Http\Controllers\NormControlController;
use App\Http\Controllers\PremiumController;

Route::get('/', function () {
    return view('index');
});

Route::view('/rules', 'rules')->name('rules');

// «Преміум» за пожертву
Route::get('/premium', [PremiumController::class, 'show'])->name('premium');
// Вебхук monobank (без CSRF, див. bootstrap/app.php); секрет — частина адреси
Route::match(['get', 'post'], '/payments/monobank/{secret}', [PremiumController::class, 'monobankWebhook'])->middleware('throttle:60,1')->name('payments.monobank.webhook');

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
    Route::post('/premium/monobank', [PremiumController::class, 'monobankStart'])->name('premium.monobank');
    Route::post('/premium/monobank/check', [PremiumController::class, 'monobankCheck'])->middleware('throttle:6,1')->name('premium.monobank.check');
    Route::post('/premium/paypal', [PremiumController::class, 'paypalStart'])->middleware('throttle:10,1')->name('premium.paypal');
    Route::get('/premium/paypal/return', [PremiumController::class, 'paypalReturn'])->name('premium.paypal.return');
    Route::get('/norm-control/fixed', [NormControlController::class, 'downloadFixed'])->name('norm-control.fixed');
});
