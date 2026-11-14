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
                <div class="hidden md:flex items-center gap-5 text-gray-600 font-semibold">
                    <a href="/#checks" class="hover:text-indigo-600 transition">Що перевіряємо</a>
                    <a href="/#how" class="hover:text-indigo-600 transition">Як це працює</a>
                    <a href="/#faq" class="hover:text-indigo-600 transition">FAQ</a>
                </div>

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
    <footer class="bg-gray-900 text-gray-400 mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 grid grid-cols-1 md:grid-cols-3 gap-10">
            <div>
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center text-white font-bold text-xl">A</div>
                    <span class="text-2xl font-black text-white tracking-tight">Acadoc</span>
                </div>
                <p class="text-sm leading-relaxed">Корпоративний сервіс автоматизованого нормоконтролю студентських робіт за ДСТУ 3008:2015 та стандартами ЄСКД.</p>
            </div>
            <div>
                <p class="text-white font-bold mb-4">Сервіс</p>
                <ul class="space-y-2 text-sm">
                    <li><a href="/#checks" class="hover:text-white transition">Що перевіряється</a></li>
                    <li><a href="/#how" class="hover:text-white transition">Як це працює</a></li>
                    <li><a href="/#faq" class="hover:text-white transition">Часті запитання</a></li>
                    @auth
                        <li><a href="{{ route('norm-control.index') }}" class="hover:text-white transition">Нормоконтроль</a></li>
                    @endauth
                </ul>
            </div>
            <div>
                <p class="text-white font-bold mb-4">Розробник</p>
                <p class="text-sm text-gray-300 font-semibold">Barabolikov</p>
                <a href="https://github.com/Barabolikov" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-sm mt-2 hover:text-white transition">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .5a12 12 0 00-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.7-1.6-2.7-.3-5.5-1.3-5.5-6 0-1.2.5-2.3 1.2-3.1-.1-.4-.5-1.6.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 016 0C17.3 4.7 18.3 5 18.3 5c.7 1.6.2 2.8.1 3.2.8.8 1.2 1.9 1.2 3.1 0 4.7-2.8 5.7-5.5 6 .4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0012 .5z"/></svg>
                    github.com/Barabolikov
                </a>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 text-sm flex flex-col sm:flex-row justify-between gap-2">
                <span>&copy; {{ date('Y') }} Acadoc. Усі права захищено.</span>
                <span>Розробник: <span class="text-gray-300">Barabolikov</span></span>
            </div>
        </div>
    </footer>

</div>
</body>
</html>
