<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FrontendAuthController extends Controller
{
    // Обробка спроби входу
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Тепер після успішного входу ми просто оновлюємо головну сторінку
            return redirect()->intended('/');
        }

        return back()->withErrors([
            'email' => 'Невірний email або пароль.',
        ])->onlyInput('email');
    }

    // Обробка виходу з системи
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
