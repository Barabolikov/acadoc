@extends('layouts.app')

@section('title', 'Головна | Acadoc LMS')

@section('content')
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24">

        <!-- Геройський блок (по центру) -->
        <div class="text-center max-w-4xl mx-auto mb-20">
            <h1 class="text-5xl sm:text-6xl font-extrabold text-gray-900 tracking-tight mb-6">
                Єдиний цифровий простір <br>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-blue-500">
                    навчального закладу
                </span>
            </h1>
            <p class="text-xl text-gray-600 mb-10 leading-relaxed max-w-2xl mx-auto">
                Сучасна платформа для організації навчального процесу. Від видачі завдань та комунікації до автоматизованого нормоконтролю документів.
            </p>

            <!-- Якщо користувач не увійшов, запропонуємо кнопку, яка теж відкриває наше вікно -->
            @guest
                <button @click="isLoginOpen = true" class="bg-indigo-600 text-white px-8 py-4 rounded-xl font-bold hover:bg-indigo-700 shadow-lg hover:shadow-xl transition transform hover:-translate-y-1">
                    Почати роботу
                </button>
            @else
                <a href="{{ route('norm-control.index') }}" class="inline-block bg-indigo-600 text-white px-8 py-4 rounded-xl font-bold hover:bg-indigo-700 shadow-lg hover:shadow-xl transition transform hover:-translate-y-1">
                    Перейти до нормоконтролю
                </a>
            @endguest
        </div>

        <!-- Блок з модулями системи -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 hover:shadow-md transition">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center text-2xl mb-4">👥</div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Групи та Студенти</h3>
                <p class="text-gray-500 text-sm">Зручне управління академічними групами, профілями студентів та викладачів.</p>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 hover:shadow-md transition">
                <div class="w-12 h-12 bg-green-100 text-green-600 rounded-xl flex items-center justify-center text-2xl mb-4">📝</div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Завдання та Оцінки</h3>
                <p class="text-gray-500 text-sm">Створення практичних завдань, дедлайни, завантаження робіт та електронний журнал.</p>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 hover:shadow-md transition">
                <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center text-2xl mb-4">💬</div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Комунікація</h3>
                <p class="text-gray-500 text-sm">Внутрішні чати, обговорення проектів та швидкий зв'язок між викладачем і студентом.</p>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 hover:shadow-md transition">
                <div class="w-12 h-12 bg-orange-100 text-orange-600 rounded-xl flex items-center justify-center text-2xl mb-4">🔍</div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Нормоконтроль</h3>
                <p class="text-gray-500 text-sm">Автоматизована перевірка форматування курсових та дипломних робіт за стандартами.</p>
            </div>
        </div>

    </div>
@endsection
