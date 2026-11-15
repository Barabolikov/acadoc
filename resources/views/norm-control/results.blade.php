@extends('layouts.app')

@section('title', 'Результати перевірки | Acadoc')

@section('content')
    <div class="max-w-4xl mx-auto px-4 py-6">

        <!-- Кнопка назад -->
        <a href="{{ route('norm-control.index') }}" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-indigo-600 mb-6 transition">
            &larr; Завантажити інший документ
        </a>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <!-- Шапка звіту -->
            <div class="bg-indigo-600 px-8 py-6 text-white">
                <h1 class="text-2xl font-bold mb-1">Звіт нормоконтролю</h1>
                <p class="text-indigo-100 opacity-90 font-medium">Файл: {{ $fileName }}</p>
            </div>

            <div class="p-8">
                <h2 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">Загальна статистика</h2>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-4">
                    <div class="flex flex-col md:flex-row justify-between gap-3">
                        <!-- Інформація про документ -->
                        <div class="space-y-2">
                            <h1 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                                <span class="text-indigo-600">📄</span> {{ $fileName }}
                            </h1>
                            <p class="text-sm text-gray-500 flex items-center gap-2">
                                <span class="font-bold text-gray-700">📅 Дата перевірки:</span> {{ $checkDate }}
                            </p>
                        </div>

                        <!-- Учасники процесу -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 bg-indigo-50 p-4 rounded-xl border border-indigo-100">
                            <div>
                                <p class="text-[10px] uppercase tracking-wider text-indigo-400 font-black">Виконав студент:</p>
                                <p class="text-sm font-bold text-indigo-900">{{ $studentName }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] uppercase tracking-wider text-indigo-400 font-black">Науковий керівник:</p>
                                <p class="text-sm font-bold text-indigo-900">{{ $supervisorName }}</p>
                            </div>
                            <!-- Оновлений блок: Кількість помилок -->
                            <div class="p-4 rounded-xl border text-center shadow-sm {{ $errorsCount > 0 ? 'bg-red-50 border-red-100' : 'bg-green-50 border-green-100' }}">
                                <p class="text-xs uppercase font-bold mb-1 {{ $errorsCount > 0 ? 'text-red-500' : 'text-green-600' }}">Помилок</p>
                                <p class="text-2xl font-black {{ $errorsCount > 0 ? 'text-red-600' : 'text-green-600' }}">
                                    {{ $errorsCount }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Блок зі статистикою -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-center">
                        <p class="text-xs text-gray-500 uppercase font-bold mb-1">Абзаців</p>
                        <p class="text-2xl font-black text-indigo-600">{{ $paragraphsCount }}</p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-center">
                        <p class="text-xs text-gray-500 uppercase font-bold mb-1">Символів</p>
                        <p class="text-2xl font-black text-indigo-600">{{ number_format($charsCount, 0, '', ' ') }}</p>
                    </div>
                    <!-- Новий блок: Таблиці -->
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-center shadow-sm">
                        <p class="text-xs text-gray-500 uppercase font-bold mb-1">Таблиць</p>
                        <p class="text-2xl font-black text-indigo-600">{{ $tablesCount }}</p>
                    </div>

                    <!-- Новий блок: Рисунки -->
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-center shadow-sm">
                        <p class="text-xs text-gray-500 uppercase font-bold mb-1">Рисунків</p>
                        <p class="text-2xl font-black text-indigo-600">{{ $imagesCount }}</p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-center">
                        <p class="text-xs text-gray-500 uppercase font-bold mb-1">Розширення</p>
                        <p class="text-2xl font-black text-indigo-600">.docx</p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-center">
                        <p class="text-xs text-gray-500 uppercase font-bold mb-1">Статус файлу</p>
                        <p class="text-2xl font-black text-green-500">ОК</p>
                    </div>
                </div>

                <h2 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">Перевірка оформлення</h2>

                <!-- Блок аналізу шрифтів -->
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="mt-1">
                            @if($mainFont === 'Times New Roman')
                                <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            @else
                                <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            @endif
                        </div>
                        <div>
                            <p class="font-bold text-gray-900">Основний шрифт</p>
                            <p class="text-sm text-gray-600">
                                @if($mainFont === null)
                                    Основний текст не знайдено (потрібен розділ «ВСТУП»), тому шрифт не визначено.
                                @elseif($mainFont === 'Times New Roman')
                                    Основний текст набрано шрифтом <span class="font-semibold">Times New Roman</span> ({{ $mainFontShare }}% тексту). Це відповідає вимогам.
                                @else
                                    <span class="text-red-600">Увага!</span> Основний текст набрано шрифтом <span class="font-semibold">{{ $mainFont }}</span> ({{ $mainFontShare }}% тексту), треба Times New Roman.
                                @endif
                            </p>

                            {{-- Шрифти, задані вручну: лістинги коду, рамка, формули тощо — довідково --}}
                            @if(count($fonts) > 0)
                                <p class="text-xs text-gray-400 mt-2">Інші шрифти, задані вручну (код, рамка, формули тощо): {{ implode(', ', $fonts) }}</p>
                            @endif
                        </div>
                        @if($structure['usesPronounI'])
                            <div class="mt-4 p-4 bg-orange-50 border border-orange-200 rounded-lg flex items-start gap-3">
                                <span class="text-xl">⚠️</span>
                                <div>
                                    <p class="font-bold text-orange-800">Стилістика тексту</p>
                                    <p class="text-sm text-orange-700">У тексті знайдено займенник <strong>«я»</strong>. Згідно з методичними вимогами, в академічних текстах необхідно використовувати займенник <strong>«ми»</strong> або безособові речення.</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                <!-- Блок перевірки структури -->
                <h2 class="text-lg font-bold text-gray-900 mt-5 mb-4 border-b pb-2">Структура документа (Послідовність розділів)</h2>

                <div class="bg-gray-50 rounded-xl p-6 border border-gray-100">
                    <div class="relative border-l-2 border-indigo-200 ml-3 space-y-3">

                        <!-- 1. Титулка -->
                        <div class="relative pl-6">
                            <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full border-2 border-white {{ $structure['hasTitle'] ? 'bg-green-500' : 'bg-red-500' }}"></div>
                            <p class="font-bold text-gray-900">1. Титульний аркуш</p>
                            @if($structure['hasTitle'])
                                <p class="text-sm text-gray-600">Знайдено ключові маркери (Міністерство, Коледж, Конотоп).</p>
                            @else
                                <p class="text-sm text-red-600 font-medium">Помилка: Не знайдено стандартних атрибутів титульної сторінки на початку документа.</p>
                            @endif
                        </div>

                        <!-- 2. Завдання -->
                        <div class="relative pl-6">
                            <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full border-2 border-white {{ $structure['taskIndex'] !== -1 ? 'bg-green-500' : 'bg-red-500' }}"></div>
                            <p class="font-bold text-gray-900">2. Завдання на роботу</p>
                            @if($structure['taskIndex'] !== -1)
                                <p class="text-sm text-gray-600">Розділ "ЗАВДАННЯ" знайдено.</p>
                            @else
                                <p class="text-sm text-red-600 font-medium">Помилка: Розділ "ЗАВДАННЯ" відсутній.</p>
                            @endif
                        </div>

                        <!-- 3. Зміст -->
                        <div class="relative pl-6">
                            <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full border-2 border-white {{ $structure['tocIndex'] !== -1 ? 'bg-green-500' : 'bg-red-500' }}"></div>
                            <p class="font-bold text-gray-900">3. Зміст документа</p>
                            @if($structure['tocIndex'] !== -1)
                                <p class="text-sm text-gray-600">Розділ "ЗМІСТ" знайдено.</p>
                            @else
                                <p class="text-sm text-red-600 font-medium">Помилка: Розділ "ЗМІСТ" відсутній.</p>
                            @endif
                        </div>

                        <!-- 4. Вступ -->
                        <div class="relative pl-6">
                            <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full border-2 border-white {{ $structure['introIndex'] !== -1 ? 'bg-green-500' : 'bg-red-500' }}"></div>
                            <p class="font-bold text-gray-900">4. Вступ</p>
                            @if($structure['introIndex'] !== -1)
                                <p class="text-sm text-gray-600">Розділ "ВСТУП" знайдено.</p>
                            @else
                                <p class="text-sm text-red-600 font-medium">Помилка: Розділ "ВСТУП" відсутній.</p>
                            @endif
                        </div>
                        <!-- 5. Висновки -->
                        <div class="relative pl-6">
                            <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full border-2 border-white {{ $structure['conclusionsIndex'] !== -1 ? 'bg-green-500' : 'bg-red-500' }}"></div>
                            <p class="font-bold text-gray-900">5. Висновки</p>
                            @if($structure['conclusionsIndex'] !== -1)
                                <p class="text-sm text-gray-600">Розділ знайдено: «{{ $structure['conclusionsTitle'] }}».</p>
                            @else
                                <p class="text-sm text-red-600 font-medium">Помилка: розділ «ВИСНОВКИ» («ВИСНОВОК») відсутній.</p>
                            @endif
                        </div>

                        <!-- 6. Список джерел -->
                        <div class="relative pl-6">
                            <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full border-2 border-white {{ $structure['referencesIndex'] !== -1 ? 'bg-green-500' : 'bg-red-500' }}"></div>
                            <p class="font-bold text-gray-900">6. Список джерел</p>
                            @if($structure['referencesIndex'] !== -1)
                                <p class="text-sm text-gray-600">Розділ знайдено: «{{ $structure['referencesTitle'] }}».</p>
                            @else
                                <p class="text-sm text-red-600 font-medium">Помилка: розділ відсутній. Допустимі назви: «СПИСОК ВИКОРИСТАНИХ ДЖЕРЕЛ», «СПИСОК ЛІТЕРАТУРИ», «ПЕРЕЛІК ДЖЕРЕЛ ПОСИЛАННЯ».</p>
                            @endif
                        </div>

                        <!-- 7. Додатки -->
                        <div class="relative pl-6">
                            <div class="absolute -left-[9px] top-1 w-4 h-4 rounded-full border-2 border-white {{ $structure['appendicesIndex'] !== -1 ? 'bg-blue-400' : 'bg-gray-300' }}"></div>
                            <p class="font-bold text-gray-900">7. Додатки <span class="text-xs font-normal text-gray-500">(Опціонально)</span></p>
                            @if($structure['appendicesIndex'] !== -1)
                                <p class="text-sm text-gray-600">У роботі присутні додатки.</p>
                            @else
                                <p class="text-sm text-gray-500">Додатки не виявлено (допускається, якщо не передбачено завданням).</p>
                            @endif
                        </div>

                    </div>

                    <!-- Висновок по порядку -->
                    <div class="mt-6 p-4 rounded-lg flex items-start gap-3 {{ $structure['isOrderValid'] ? 'bg-green-50 border border-green-100' : 'bg-red-50 border border-red-100' }}">
                        <div class="mt-0.5">
                            @if($structure['isOrderValid'])
                                <span class="text-xl">✅</span>
                            @else
                                <span class="text-xl">❌</span>
                            @endif
                        </div>
                        <div>
                            <p class="font-bold {{ $structure['isOrderValid'] ? 'text-green-800' : 'text-red-800' }}">
                                Порядок розділів
                            </p>
                            <p class="text-sm {{ $structure['isOrderValid'] ? 'text-green-700' : 'text-red-700' }}">
                                @if($structure['isOrderValid'])
                                    Усі розділи розміщені у правильній послідовності.
                                @else
                                    Порушено логіку структури. Можливо, якийсь розділ пропущено або вони переплутані місцями.
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
                <!-- Журнал конкретних виправлень -->
                <h2 class="text-lg font-bold text-gray-900 mt-5 mb-4 border-b pb-2 flex items-center gap-2">
                    <span class="text-xl">📋</span> Журнал виправлень (Що і де виправити)
                </h2>

                @if(count($actionableErrors) > 0)
                    <div class="bg-white border border-red-200 rounded-xl overflow-hidden shadow-sm mt-4">
                        <div class="p-4 bg-red-50 border-b border-red-100 flex justify-between items-center">
                            <span class="font-bold text-red-800">Знайдено помилок: {{ count($actionableErrors) }}</span>
                            <span class="text-xs text-red-600 bg-white px-2 py-1 rounded border border-red-200">Показано перші 50 помилок (якщо їх більше)</span>
                        </div>
                        <div class="max-h-96 overflow-y-auto"> <!-- Додаємо скрол, якщо помилок багато -->
                            <table class="w-full text-left border-collapse">
                                <thead class="sticky top-0 bg-white shadow-sm z-10">
                                <tr class="text-gray-600 text-xs uppercase tracking-wider border-b border-gray-200">
                                    <th class="py-3 px-4 w-1/5">Тип</th>
                                    <th class="py-3 px-4 w-1/4">Де знаходиться</th>
                                    <th class="py-3 px-4 w-1/4">В чому помилка</th>
                                    <th class="py-3 px-4 w-1/3">Знайдений текст</th>
                                </tr>
                                </thead>
                                <tbody class="text-sm">
                                <!-- Обмежуємо вивід до 50, щоб не "повісити" браузер, якщо весь документ кривий -->
                                @foreach(array_slice($actionableErrors, 0, 50) as $error)
                                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                        <td class="py-3 px-4">
                                            <span class="inline-block px-2 py-1 rounded text-xs font-bold
                                                {{ str_contains($error['type'], 'Форматування') ? 'bg-blue-100 text-blue-700' : 'bg-orange-100 text-orange-700' }}">
                                                {{ $error['type'] }}
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-gray-800 font-medium align-top">
                                            <div class="flex items-center gap-2">
                                                <span class="text-indigo-500 text-lg">📍</span>
                                                <span class="truncate max-w-[200px]" title="{{ $error['location'] }}">{{ $error['location'] }}</span>
                                            </div>
                                        </td>
                                        <td class="py-3 px-4 text-red-600 font-bold align-top">
                                            {{ $error['message'] }}
                                        </td>
                                        <td class="py-3 px-4 align-top">
                                            <div class="bg-gray-100 text-gray-600 p-2 rounded border border-gray-200 font-mono text-xs break-words">
                                                {{ $error['context'] }}
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Блок успіху залишається без змін -->
                @else
                    <div class="bg-green-50 border border-green-200 rounded-xl p-6 text-center">
                        <span class="text-3xl mb-2 block">🎉</span>
                        <p class="font-bold text-green-800">Критичних точкових помилок не знайдено!</p>
                        <p class="text-sm text-green-700 mt-1">Текст очищений від заборонених слів та застарілих назв заголовків.</p>
                    </div>
                @endif
            </div>
        </div>
        <div class="mt-4 flex flex-col items-center gap-4">
            <div class="max-w-xl w-full bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-800 text-center">
                <strong>ℹ️ Примітка:</strong> Отриманий файл звіту слід надіслати керівнику проєкту.
            </div>
            <div class="flex flex-col sm:flex-row items-center gap-3">
                <form action="{{ route('norm-control.download') }}" method="POST">
                    @csrf
                    {{-- Дані звіту беруться із сесії на сервері (див. NormControlController::downloadReport) --}}

                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-4 px-8 rounded-2xl shadow-lg transition-all flex items-center gap-3">
                        <span>📥 Завантажити звіт (.docx)</span>
                    </button>
                </form>

                {{-- Автовиправлення (DocxFixer) — для статусу «Преміум» --}}
                @if ($isPremium && $fixed)
                    <a href="{{ route('norm-control.fixed') }}"
                       class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-4 px-8 rounded-2xl shadow-lg transition-all flex items-center gap-3">
                        <span>🛠️ Виправити помилки</span>
                    </a>
                @else
                    <a href="{{ route('premium') }}" title="Доступно зі статусом «Преміум»"
                       class="bg-gray-200 text-gray-500 hover:bg-gray-300 font-bold py-4 px-8 rounded-2xl flex items-center gap-3 transition">
                        <span>🔒 Виправити помилки</span>
                        <span class="text-xs font-bold uppercase bg-amber-400 text-amber-950 rounded px-2 py-0.5">Преміум</span>
                    </a>
                @endif
            </div>

            @if ($isPremium && $fixed)
                <div class="max-w-xl w-full bg-emerald-50 border border-emerald-200 rounded-lg p-4 text-sm text-emerald-900">
                    @if ($fixed['fixes'])
                        <p class="font-bold mb-1">Сервіс виправив у вашій роботі:</p>
                        <ul class="list-disc pl-5 space-y-0.5">
                            @foreach ($fixed['fixes'] as $op => $count)
                                <li>{{ \App\Services\NormControl\DocxFixer::LABELS[$op] ?? $op }} — {{ $count }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="font-bold">Помилок, які можна виправити автоматично, не знайдено.</p>
                    @endif
                    @if ($fixed['manual'] > 0)
                        <p class="mt-2">Ще {{ $fixed['manual'] }} зауважень (посилання, підписи, рамка, зміст тощо) потрібно виправити вручну — див. звіт.</p>
                    @endif
                    <p class="mt-2 text-xs text-emerald-700">
                        <a href="{{ route('norm-control.fixed') }}" class="font-bold underline">Завантажити виправлену роботу</a>.
                        Посилання діє 24 години. Перегляньте файл перед здачею та оновіть зміст (ПКМ → Оновити поле).
                    </p>
                </div>
            @elseif ($isPremium)
                <div class="max-w-xl w-full bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-800 text-center">
                    Не вдалося автоматично виправити цей документ. Виправте помилки вручну за звітом.
                </div>
            @else
                <div class="max-w-xl w-full bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900 text-center">
                    Сервіс може сам виправити шрифт, інтервали, відступи, поля й заголовки та дати посилання на виправлену роботу. Статус «Преміум» також знімає денний ліміт перевірок.
                    Статус «Преміум» можна отримати за символічну пожертву на розвиток проєкту: {{ \App\Models\Payment::priceUah() }} грн на місяць або {{ \App\Models\Payment::priceUah('lifetime') }} грн назавжди.
                    <a href="{{ route('premium') }}" class="mt-2 block w-fit mx-auto bg-amber-500 hover:bg-amber-600 text-white font-bold py-2 px-5 rounded-lg transition">⭐ Отримати «Преміум» від {{ \App\Models\Payment::priceUah() }} грн</a>
                </div>
            @endif
        </div>
    </div>
@endsection
