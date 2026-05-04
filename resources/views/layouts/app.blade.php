<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Acadoc | LMS')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Підключаємо Alpine.js для магії спливаючих вікон -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased selection:bg-indigo-500 selection:text-white">
<!-- Змінна isLoginOpen зберігає стан вікна: відкрите/закрите -->
<!-- Якщо є помилки вводу, вікно автоматично залишиться відкритим після перезавантаження -->
<div class="min-h-screen flex flex-col" x-data="{ isLoginOpen: {{ $errors->has('email') ? 'true' : 'false' }} }">

    <!-- Шапка (Header) -->
    <header class="bg-white shadow-sm border-b border-gray-100 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <!-- Логотип -->
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center text-white font-bold text-xl">A</div>
                <a href="/" class="text-2xl font-black text-gray-900 tracking-tight hover:text-indigo-600 transition">Acadoc</a>
            </div>


            <!-- Меню навігації -->
            <nav class="flex items-center gap-3 sm:gap-5 text-sm font-medium">

                @auth
                    <!-- Випадаюче меню "Функції" (Тільки для авторизованих) -->
                    <div class="relative" x-data="{ dropdownOpen: false }">
                        <button @click="dropdownOpen = !dropdownOpen" class="text-gray-600 hover:text-indigo-600 transition flex items-center gap-1 font-semibold outline-none">
                            Функції
                            <!-- Іконка стрілочки, яка перевертається при відкритті -->
                            <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': dropdownOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>

                        <!-- Саме випадаюче віконце -->
                        <div x-show="dropdownOpen"
                             @click.away="dropdownOpen = false"
                             x-transition.opacity.duration.200ms
                             class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50"
                             style="display: none;">
                            <a href="{{ route('norm-control.index') }}" class="flex items-center gap-3 px-4 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-600 transition">
                                <span class="text-lg">🔍</span> Нормоконтроль
                            </a>
                            <!-- В майбутньому сюди легко додавати нові пункти -->
                        </div>
                    </div>

                    <!-- Кнопка виходу -->
                    <form action="{{ route('frontend.logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-gray-500 hover:text-red-500 font-bold transition">Вийти</button>
                    </form>
                @else
                    <!-- Кнопка Вхід (Для гостей) -->
                    <button @click="isLoginOpen = true" class="bg-indigo-600 text-white px-5 py-2 rounded-lg font-bold hover:bg-indigo-700 transition shadow-sm">
                        Вхід
                    </button>
                @endauth

                <!-- Кнопка Адмін панелі (Завжди присутня справа) -->
                <a href="/admin" class="bg-gray-100 text-gray-700 px-5 py-2 rounded-lg font-bold hover:bg-gray-200 border border-gray-200 transition flex items-center gap-2">
                    Адмін панель
                </a>
            </nav>
        </div>
    </header>

    <!-- СПЛИВАЮЧЕ ВІКНО АВТОРИЗАЦІЇ -->
    <div x-show="isLoginOpen"
         x-transition.opacity
         class="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-50 backdrop-blur-sm"
         style="display: none;">

        <!-- Клік поза вікном закриває його (@click.away) -->
        <div @click.away="isLoginOpen = false"
             x-transition.scale.origin.bottom
             class="bg-white w-full max-w-md p-8 rounded-2xl shadow-2xl relative mx-4">

            <!-- Кнопка закрити (Х) -->
            <button @click="isLoginOpen = false" class="absolute top-4 right-4 text-gray-400 hover:text-gray-800 text-2xl font-bold">&times;</button>

            <h2 class="text-2xl font-bold text-gray-900 mb-6 text-center">Вхід у систему</h2>

            <form action="{{ route('frontend.login') }}" method="POST" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Електронна пошта</label>
                    <input type="email" name="email" id="email" required value="{{ old('email') }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                    @error('email')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Пароль</label>
                    <input type="password" name="password" id="password" required
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded text-indigo-600 focus:ring-indigo-500">
                        Запам'ятати мене
                    </label>
                </div>

                <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-indigo-700 transition">
                    Увійти
                </button>
            </form>
        </div>
    </div>

    <!-- Контент сторінки -->
    <main class="flex-grow">
        @yield('content')
    </main>

    <!-- Підвал -->
    <footer class="bg-white border-t border-gray-200 py-8 mt-auto">
        <div class="max-w-7xl mx-auto px-4 text-center text-sm text-gray-400">
            &copy; {{ date('Y') }} Acadoc. Розроблено для оптимізації освітнього процесу.
        </div>
    </footer>

</div>
</body>
</html>
