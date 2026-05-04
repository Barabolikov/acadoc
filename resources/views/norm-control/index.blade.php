@extends('layouts.app')

@section('title', 'Нормоконтроль документів | Acadoc')

@section('content')
    <div class="max-w-4xl mx-auto px-4 py-12">

        <div class="mb-8">
            <h1 class="text-3xl font-extrabold text-gray-900 mb-2">Нормоконтроль документів</h1>
            <p class="text-gray-600">Завантажте вашу курсову або дипломну роботу у форматі .docx для автоматичної перевірки оформлення.</p>
        </div>

        <!-- Блок завантаження файлу -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 text-center">
            <div class="flex justify-center mb-4">
                <div class="w-16 h-16 bg-indigo-50 text-indigo-600 rounded-full flex items-center justify-center text-3xl">📄</div>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-1">Оберіть файл для перевірки</h3>
            <p class="text-sm text-gray-500 mb-6">Підтримуються лише файли формату .docx (Word)</p>

            @if ($errors->any())
                <div class="mb-4 p-4 bg-red-50 text-red-600 rounded-lg text-sm text-left">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('norm-control.process') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <!-- Додаємо x-data="{ fileName: '' }" для відстеження файлу -->
                <div x-data="{ fileName: '' }" class="flex items-center justify-center w-full mb-6">
                    <label for="dropzone-file" class="flex flex-col items-center justify-center w-full h-40 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 transition relative overflow-hidden">

                        <div class="flex flex-col items-center justify-center pt-5 pb-6 w-full px-4 text-center">

                            <!-- Блок 1: Показуємо, коли файл ЩЕ НЕ обрано -->
                            <div x-show="!fileName" class="flex flex-col items-center">
                                <svg class="w-8 h-8 mb-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 16">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"/>
                                </svg>
                                <p class="mb-2 text-sm text-gray-500"><span class="font-semibold">Натисніть для завантаження</span> або перетягніть файл сюди</p>
                            </div>

                            <!-- Блок 2: Показуємо, коли файл ВЖЕ обрано -->
                            <div x-show="fileName" style="display: none;" class="flex flex-col items-center text-indigo-600">
                                <!-- Іконка галочки -->
                                <svg class="w-10 h-10 mb-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <p class="text-sm font-medium text-gray-500 mb-1">Файл готовий до перевірки:</p>
                                <!-- Назва файлу -->
                                <p x-text="fileName" class="font-bold text-lg truncate max-w-full px-4"></p>
                            </div>

                        </div>

                        <!-- @change ловить подію вибору і записує ім'я у змінну fileName -->
                        <input id="dropzone-file" type="file" name="document" class="hidden" accept=".docx"
                               @change="fileName = $event.target.files[0] ? $event.target.files[0].name : ''" />
                    </label>
                </div>

                <button type="submit" class="bg-indigo-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-indigo-700 transition w-full sm:w-auto shadow-sm">
                    Почати перевірку
                </button>
            </form>
        </div>

    </div>
@endsection
