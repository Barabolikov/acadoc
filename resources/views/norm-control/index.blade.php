@extends('layouts.app')

@section('title', 'Нормоконтроль документів | Acadoc')

@section('content')
    <div class="max-w-4xl mx-auto px-4 py-6">

        <div class="mb-4">
            <h1 class="text-3xl font-extrabold text-gray-900 mb-2">Нормоконтроль документів</h1>
            <p class="text-gray-600">Завантажте вашу курсову або дипломну роботу у форматі .docx для автоматичної перевірки оформлення.</p>
        </div>

        {{-- Денний ліміт для студентів ($remainingChecks === null — без обмежень) --}}
        @if ($remainingChecks !== null)
            <div class="mb-6 flex items-start gap-3 rounded-xl border p-4 {{ $remainingChecks > 0 ? 'bg-indigo-50 border-indigo-100 text-indigo-900' : 'bg-amber-50 border-amber-200 text-amber-900' }}">
                <svg class="w-6 h-6 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div class="text-sm">
                    @if ($remainingChecks > 0)
                        <p class="font-bold">Сьогодні доступно перевірок: {{ $remainingChecks }} з {{ $dailyLimit }}</p>
                        <p>Студенти можуть перевіряти {{ $dailyLimit }} документ на день. Перед завантаженням переконайтеся, що обрали остаточну версію роботи.</p>
                    @else
                        <p class="font-bold">Ліміт перевірок на сьогодні вичерпано</p>
                        <p>Наступна перевірка буде доступна завтра з 00:00 (за київським часом). Звіт попередньої перевірки можна завантажити на сторінці результатів, доки ви не вийдете із системи.</p>
                    @endif
                </div>
            </div>
        @endif

        <!-- Блок завантаження файлу -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 text-center">
            <div class="flex justify-center mb-4">
                <div class="w-16 h-16 bg-indigo-50 text-indigo-600 rounded-full flex items-center justify-center text-3xl">📄</div>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-1">Оберіть файл для перевірки</h3>
            <p class="text-sm text-gray-500 mb-6">
                Підтримуються лише файли формату .docx (Word)
                <span class="block mt-1 font-semibold text-amber-700">⚠️ Максимальний розмір файлу — {{ $maxUploadMb }} МБ</span>
            </p>

            @if ($errors->any())
                <div class="mb-4 p-4 bg-red-50 text-red-600 rounded-lg text-sm text-left">
                    <ul class="list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- fileName/fileSize — обраний файл; tooBig — більше за ліміт, тоді показуємо попередження й блокуємо кнопку --}}
            <form action="{{ route('norm-control.process') }}" method="POST" enctype="multipart/form-data"
                  x-data="{ fileName: '', fileSize: 0, maxBytes: {{ $maxUploadMb }} * 1024 * 1024, get tooBig() { return this.fileSize > this.maxBytes } }">
                @csrf

                <div class="flex items-center justify-center w-full mb-6">
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
                            <div x-show="fileName" style="display: none;" class="flex flex-col items-center" :class="tooBig ? 'text-red-600' : 'text-indigo-600'">
                                <!-- Іконка галочки (або хрестика, якщо файл завеликий) -->
                                <svg x-show="!tooBig" class="w-10 h-10 mb-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <svg x-show="tooBig" class="w-10 h-10 mb-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <p class="text-sm font-medium text-gray-500 mb-1" x-text="tooBig ? 'Файл завеликий:' : 'Файл готовий до перевірки:'"></p>
                                <!-- Назва файлу -->
                                <p x-text="fileName" class="font-bold text-lg truncate max-w-full px-4"></p>
                            </div>

                        </div>

                        <!-- @change ловить подію вибору і записує ім'я у змінну fileName -->
                        <input id="dropzone-file" type="file" name="document" class="hidden" accept=".docx"
                               @change="const f = $event.target.files[0]; fileName = f ? f.name : ''; fileSize = f ? f.size : 0" />
                    </label>
                </div>

                <!-- Попередження, якщо обраний файл більший за ліміт (перевіряємо ще до відправки) -->
                <div x-show="tooBig" x-transition.opacity style="display: none;" class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm text-left">
                    <p class="font-bold">
                        Файл важить <span x-text="(fileSize / 1024 / 1024).toFixed(1).replace('.', ',')"></span> МБ — це більше за допустимі {{ $maxUploadMb }} МБ.
                    </p>
                    <p class="mt-1">Зменште розмір документа: у Word виберіть рисунок → «Формат рисунка» → «Стиснути рисунки» → «До всіх рисунків у документі», збережіть файл і завантажте знову.</p>
                </div>

                <div class="mb-6">
                    <x-turnstile :show-error="false" />
                </div>

                <button type="submit" @disabled($remainingChecks === 0) :disabled="{{ $remainingChecks === 0 ? 'true' : 'false' }} || tooBig" class="bg-indigo-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-indigo-700 transition w-full sm:w-auto shadow-sm disabled:bg-gray-300 disabled:cursor-not-allowed">
                    Почати перевірку
                </button>
            </form>
        </div>

    </div>
@endsection
