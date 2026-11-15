<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\Turnstile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class FrontendAuthController extends Controller
{
    // Обробка спроби входу
    public function login(Request $request)
    {
        // Помилки входу — в окремому наборі 'login', щоб вікно входу відкривалося лише через них
        $request->validateWithBag('login', [
            'email' => ['required', 'email'],
            'password' => ['required'],
            Turnstile::FIELD => ['required', new Turnstile],
        ], [Turnstile::FIELD.'.required' => 'Підтвердіть, що ви не робот.']);
        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Тепер після успішного входу ми просто оновлюємо головну сторінку
            return redirect()->intended('/');
        }

        return back()->withErrors([
            'email' => 'Невірний email або пароль.',
        ], 'login')->onlyInput('email');
    }

    // Реєстрація: будь-хто стає студентом; бажання бути викладачем фіксуємо в requested_role,
    // а роль «Викладач» призначає адміністратор в адмін-панелі.
    public function register(Request $request)
    {
        if (Auth::check()) {
            return redirect('/');
        }

        $data = $request->validateWithBag('register', [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'requested_role' => ['required', 'in:student,teacher'],
            Turnstile::FIELD => ['required', new Turnstile],
        ], [
            Turnstile::FIELD.'.required' => 'Підтвердіть, що ви не робот.',
            'name.required' => 'Вкажіть прізвище та ім\'я.',
            'email.required' => 'Вкажіть електронну пошту.',
            'email.email' => 'Електронна пошта має неправильний формат.',
            'email.unique' => 'Користувач з такою поштою вже зареєстрований.',
            'password.required' => 'Вкажіть пароль.',
            'password.confirmed' => 'Паролі не збігаються.',
            'password.min' => 'Пароль має містити щонайменше 8 символів.',
            'requested_role.required' => 'Оберіть, хто ви: студент чи викладач.',
            'requested_role.in' => 'Оберіть, хто ви: студент чи викладач.',
        ]);

        $user = User::create($data);
        $user->assignRole(Role::findOrCreate(User::ROLE_STUDENT, 'web'));

        Auth::login($user);
        $request->session()->regenerate();

        $message = $data['requested_role'] === 'teacher'
            ? 'Реєстрацію завершено. Заявку на роль викладача надіслано адміністратору; до її розгляду ви маєте права студента.'
            : 'Реєстрацію завершено. Ласкаво просимо до Acadoc!';

        return redirect('/')->with('status', $message);
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
