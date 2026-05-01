<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Змінний заголовок сторінки -->
    <title>@yield('title', 'Acadoc | LMS')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased selection:bg-indigo-500 selection:text-white">
<div class="min-h-screen flex flex-col">

    <!-- Шапка (Header) -->
    <header class="bg-white shadow-sm border-b border-gray-100 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center text-white font-bold text-xl">A</div>
                <!-- Посилання на головну -->
                <a href="/" class="text-2xl font-black text-gray-900 tracking-tight hover:text-indigo-600 transition">Acadoc</a>
            </div>
            <nav class="hidden md:flex gap-6 text-sm font-medium text-gray-600">
                <!-- Тут пізніше додамо інші посилання (Новини, Про нас тощо) -->
            </nav>
            <a href="/admin" class="bg-indigo-50 text-indigo-700 px-5 py-2 rounded-lg text-sm font-bold hover:bg-indigo-100 transition">
                Особистий кабінет &rarr;
            </a>
        </div>
    </header>

    <!-- Тут буде підставлятися унікальний контент кожної сторінки -->
    <main class="flex-grow">
        @yield('content')
    </main>

    <!-- Підвал (Footer) -->
    <footer class="bg-white border-t border-gray-200 py-8 mt-auto">
        <div class="max-w-7xl mx-auto px-4 text-center text-sm text-gray-400">
            &copy; {{ date('Y') }} Acadoc. Розроблено для оптимізації освітнього процесу.
        </div>
    </footer>

</div>
</body>
</html>
