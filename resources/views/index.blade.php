@extends('layouts.app')

@section('title', 'Acadoc | Нормоконтроль студентських робіт за ДСТУ та ЄСКД')

@section('content')

    @php
        // Що перевіряється зараз (реалізовано в NormControlController)
        $checkGroups = [
            [
                'title' => 'Параметри сторінки',
                'standard' => 'ДСТУ 3008:2015, ГОСТ 2.105',
                'color' => 'indigo',
                'icon' => 'M4 5a1 1 0 011-1h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm4 3h8M8 12h8M8 16h5',
                'items' => ['Формат аркуша А4 у кожному розділі', 'Ліве поле 30 мм, праве — від 10 мм', 'Верхнє й нижнє поля — від 20 мм', 'Нумерація в правому верхньому куті, без номера на титулці'],
            ],
            [
                'title' => 'Рамка та основний напис',
                'standard' => 'ГОСТ 2.104, ГОСТ 2.105',
                'color' => 'amber',
                'icon' => 'M4 4h16v16H4zM4 15h16M11 15v5',
                'items' => ['Основний напис (форма 2) на аркуші ЗМІСТ', 'Заповнено «Розроб.», «Перевір.», «Н. контр.», «Аркушів»', 'Є позначення (шифр) документа', 'ПІБ і назва збігаються з титульним аркушем'],
            ],
            [
                'title' => 'Основний текст',
                'standard' => 'ДСТУ 3008:2015',
                'color' => 'emerald',
                'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h10',
                'items' => ['Шрифт Times New Roman', 'Розмір шрифту 14 пт', 'Міжрядковий інтервал 1,5', 'Абзацний відступ 1,25 см', 'Вирівнювання по ширині'],
            ],
            [
                'title' => 'Заголовки',
                'standard' => 'ДСТУ 3008:2015, ГОСТ 2.105',
                'color' => 'amber',
                'icon' => 'M6 4v16M18 4v16M6 12h12',
                'items' => ['ЗМІСТ, ВСТУП, ВИСНОВКИ, РОЗДІЛ — великими літерами', 'Структурні заголовки по центру', 'Без крапки в кінці заголовка'],
            ],
            [
                'title' => 'Структура роботи',
                'standard' => 'ДСТУ 3008:2015',
                'color' => 'sky',
                'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
                'items' => ['Титульний аркуш і завдання', 'Зміст, вступ, висновки', 'Список використаних джерел, додатки', 'Правильний порядок розділів', 'Кожен розділ — з нової сторінки', 'Автоматично сформований зміст'],
            ],
            [
                'title' => 'Рисунки й таблиці',
                'standard' => 'ДСТУ 3008:2015, ГОСТ 2.105',
                'color' => 'rose',
                'icon' => 'M4 16l4.6-4.6a2 2 0 012.8 0L16 16m-2-2l1.6-1.6a2 2 0 012.8 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
                'items' => ['«Рисунок 1.2 – Назва» під рисунком', '«Таблиця 1.2 – Назва» над таблицею', 'Нумерація в межах розділу без пропусків і повторів', 'Посилання в тексті на кожен рисунок і таблицю'],
            ],
            [
                'title' => 'Формули й додатки',
                'standard' => 'ДСТУ 3008:2015',
                'color' => 'sky',
                'icon' => 'M7 4h10M7 4l5 8-5 8h10M4 20h0',
                'items' => ['Номер формули «(2.1)» у межах розділу', 'Посилання в тексті на кожну формулу', '«ДОДАТОК А», «Б»… без заборонених літер', 'Назва й посилання на кожен додаток'],
            ],
            [
                'title' => 'Список і посилання на джерела',
                'standard' => 'ДСТУ 3008:2015, ДСТУ 8302:2015',
                'color' => 'indigo',
                'icon' => 'M12 6.25v13m0-13C10.8 5.5 9.2 5 7.5 5S4.2 5.5 3 6.25v13C4.2 18.5 5.8 18 7.5 18s3.3.5 4.5 1.25m0-13C13.2 5.5 14.8 5 16.5 5s3.3.5 4.5 1.25v13C19.8 18.5 18.2 18 16.5 18s-3.3.5-4.5 1.25',
                'items' => ['Посилання у форматі [1], [1, с. 25], [2–4]', 'На кожне джерело зі списку є посилання', 'Опис за ДСТУ 8302:2015: рік, сторінки, крапка в кінці', '«URL:» і «(дата звернення: …)» для електронних ресурсів'],
            ],
            [
                'title' => 'Стиль викладу',
                'standard' => 'Вимоги до наукового тексту',
                'color' => 'violet',
                'icon' => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
                'items' => ['Виклад від першої особи множини («ми»)', 'Пошук займенника «я» з точним місцем у тексті'],
            ],
        ];

        $faq = [
            ['q' => 'Хто може користуватися сервісом?', 'a' => 'Студенти й викладачі навчального закладу. Зареєструйтеся, обравши «Студент» або «Викладач». Нові облікові записи отримують права студента, а роль викладача підтверджує адміністратор.'],
            ['q' => 'Скільки робіт можна перевірити?', 'a' => 'Студенти можуть перевіряти 1 документ на день; ліміт оновлюється о 00:00 за київським часом. Тож завантажуйте остаточну версію роботи, виправивши все, що можна. Для викладачів обмежень немає.'],
            ['q' => 'Які файли можна перевірити?', 'a' => 'Документи Microsoft Word у форматі .docx розміром до 5 МБ. Якщо робота в .doc або .pdf, відкрийте її у Word і збережіть як .docx.'],
            ['q' => 'Чи зберігається моя робота на сервері?', 'a' => 'Ні. Файл аналізується одразу після завантаження й не зберігається: у журналі лишаються тільки назва файлу, його контрольна сума й кількість зауважень. Результат бачите лише ви, а за бажанням можете завантажити звіт у форматі Word (.docx).'],
            ['q' => 'Чи замінює перевірка нормоконтролера?', 'a' => 'Ні, вона допомагає підготуватися до нормоконтролю. Сервіс знаходить типові помилки оформлення до того, як ви здасте роботу. Остаточне рішення ухвалює нормоконтролер або керівник.'],
            ['q' => 'Чому сервіс показує помилку, якої в мене немає?', 'a' => 'Перевірка спирається на внутрішню розмітку Word. Якщо форматування задано вручну, а не через стилі, програма може його не розпізнати. Використовуйте стилі «Звичайний» та «Заголовок 1–3», і результат буде точнішим.'],
        ];
    @endphp

    {{-- ГОЛОВНИЙ БЛОК --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-indigo-50 via-white to-white">
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-indigo-200/40 rounded-full blur-3xl"></div>
        <div class="absolute top-40 -left-24 w-72 h-72 bg-sky-200/40 rounded-full blur-3xl"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 pb-10 lg:pt-8 lg:pb-14">
            {{-- Назва навчального закладу: по центру, великими літерами, градієнт як у «перед здачею» --}}
            <p class="max-w-6xl mx-auto mb-6 lg:mb-8 text-center text-xl sm:text-2xl lg:text-4xl font-extrabold uppercase tracking-wide leading-snug text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-sky-500">
                ВСП «Конотопський <span class="lg:whitespace-nowrap">індустріально-педагогічний</span> фаховий коледж СумДУ» -
            <span class="sm:text-2xl">Гурток "WEB DEVELOPER"</span>
            </p>

            <div class="grid lg:grid-cols-2 gap-7 items-center">

            <div>

                <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-gray-900 tracking-tight leading-[1.1] mb-6">
                    Перевірте курсову/дипломну роботу на&nbsp;нормоконтроль
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-sky-500">перед здачею</span>
                </h1>

                <p class="text-lg sm:text-xl text-gray-600 leading-relaxed mb-4 max-w-xl">
                    Автоматична перевірка курсових, дипломних і звітів з практики на відповідність
                    <strong class="text-gray-800">ДСТУ 3008:2015</strong> та стандартам <strong class="text-gray-800">ЄСКД</strong>.
                    Завантажте .docx і за кілька секунд отримайте список помилок із точним місцем у тексті.
                </p>

                <div class="flex flex-col sm:flex-row gap-3 mb-4">
                    @guest
                        <button @click="isRegisterOpen = true" class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-8 py-4 rounded-xl font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-600/20 hover:shadow-xl transition transform hover:-translate-y-0.5">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M16 8l-4-4-4 4M12 4v12"/></svg>
                            Зареєструватися й перевірити роботу
                        </button>
                    @else
                        <a href="{{ route('norm-control.index') }}" class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-8 py-4 rounded-xl font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-600/20 hover:shadow-xl transition transform hover:-translate-y-0.5">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M16 8l-4-4-4 4M12 4v12"/></svg>
                            Завантажити роботу
                        </a>
                    @endguest
                    <a href="#checks" class="inline-flex items-center justify-center px-8 py-4 rounded-xl font-bold text-gray-700 bg-white border border-gray-200 hover:border-indigo-300 hover:text-indigo-700 transition">
                        Що перевіряється
                    </a>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach (['Поля та формат', 'Шрифт і інтервали', 'Заголовки', 'Структура роботи', 'Підписи', 'Звіт у Word'] as $badge)
                        <span class="inline-flex items-center gap-1.5 bg-white border border-gray-200 text-gray-700 text-sm font-medium px-3 py-1.5 rounded-full">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            {{ $badge }}
                        </span>
                    @endforeach
                </div>
            </div>

            {{-- Приклад результату перевірки --}}
            <div class="relative">
                <div class="bg-white rounded-3xl shadow-2xl shadow-indigo-900/10 border border-gray-100 p-6 sm:p-8">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-11 h-11 shrink-0 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l5.4 5.4a1 1 0 01.3.7V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <div class="min-w-0">
                                <p class="font-bold text-gray-900 truncate">Курсова_робота_Іваненко.docx</p>
                                <p class="text-xs text-gray-500">Приклад звіту перевірки</p>
                            </div>
                        </div>
                        <span class="shrink-0 bg-amber-100 text-amber-700 text-xs font-bold px-3 py-1 rounded-full">5 зауважень</span>
                    </div>

                    <div class="grid grid-cols-3 gap-3 mb-6 text-center">
                        <div class="bg-gray-50 rounded-xl p-3"><p class="text-2xl font-extrabold text-gray-900">318</p><p class="text-xs text-gray-500">абзаців</p></div>
                        <div class="bg-gray-50 rounded-xl p-3"><p class="text-2xl font-extrabold text-gray-900">6</p><p class="text-xs text-gray-500">таблиць</p></div>
                        <div class="bg-gray-50 rounded-xl p-3"><p class="text-2xl font-extrabold text-gray-900">11</p><p class="text-xs text-gray-500">рисунків</p></div>
                    </div>

                    <ul class="space-y-3 text-sm">
                        <li class="flex gap-3 p-3 rounded-xl bg-rose-50 border border-rose-100">
                            <span class="w-2 shrink-0 rounded-full bg-rose-500"></span>
                            <div><p class="font-semibold text-gray-900">Ліве поле: 25 мм (треба 30 мм)</p><p class="text-gray-500 text-xs">Налаштування сторінки</p></div>
                        </li>
                        <li class="flex gap-3 p-3 rounded-xl bg-amber-50 border border-amber-100">
                            <span class="w-2 shrink-0 rounded-full bg-amber-500"></span>
                            <div><p class="font-semibold text-gray-900">Інтервал не 1,5</p><p class="text-gray-500 text-xs">РОЗДІЛ 1 · «Аналіз предметної області…»</p></div>
                        </li>
                        <li class="flex gap-3 p-3 rounded-xl bg-violet-50 border border-violet-100">
                            <span class="w-2 shrink-0 rounded-full bg-violet-500"></span>
                            <div><p class="font-semibold text-gray-900">Пишіть «Рисунок» повністю</p><p class="text-gray-500 text-xs">1.2 · «Рис. 3 Схема бази даних»</p></div>
                        </li>
                    </ul>

                    <div class="mt-6 flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 text-emerald-600 font-semibold">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Структура роботи правильна
                        </span>
                        <span class="text-indigo-600 font-semibold">Звіт .docx ↓</span>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </section>

    {{-- ПЕРЕВАГИ --}}
    <section class="border-y border-gray-100 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
            @foreach ([
                ['Кілька секунд', 'на перевірку однієї роботи', 'M13 10V3L4 14h7v7l9-11h-7z'],
                ['Конфіденційно', 'файл не зберігається на сервері', 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
                ['ДСТУ та ЄСКД', 'вимоги, за якими працює нормоконтроль закладу', 'M9 12l2 2 4-4m5.6-4A12 12 0 0112 2.9 12 12 0 013.4 6 12 12 0 003 9c0 5.6 3.8 10.3 9 11.6 5.2-1.3 9-6 9-11.6 0-1-.1-2-.4-3z'],
            ] as [$title, $text, $icon])
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 shrink-0 rounded-2xl bg-indigo-600 text-white flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                    </div>
                    <div>
                        <p class="font-extrabold text-gray-900 text-lg">{{ $title }}</p>
                        <p class="text-gray-500 text-sm">{{ $text }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ЩО ПЕРЕВІРЯЄТЬСЯ --}}
    <section id="checks" class="scroll-mt-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 lg:py-14">
        <div class="text-center max-w-3xl mx-auto mb-7">
            <p class="text-indigo-600 font-bold uppercase tracking-wider text-sm mb-3">Що перевіряється</p>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-4">Вимоги стандартів, які найчастіше порушують</h2>
            <p class="text-gray-600 text-lg">
                Перевірка спирається на ДСТУ 3008:2015 «Звіти у сфері науки і техніки. Структура та правила оформлювання»
                і на стандарти ЄСКД до текстових документів (ГОСТ 2.105).
            </p>
        </div>

        @php
            $palette = [
                'indigo'  => 'bg-indigo-50 text-indigo-600',
                'emerald' => 'bg-emerald-50 text-emerald-600',
                'amber'   => 'bg-amber-50 text-amber-600',
                'sky'     => 'bg-sky-50 text-sky-600',
                'rose'    => 'bg-rose-50 text-rose-600',
                'violet'  => 'bg-violet-50 text-violet-600',
            ];
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach ($checkGroups as $group)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-lg hover:-translate-y-1 transition p-7">
                    <div class="w-12 h-12 rounded-xl {{ $palette[$group['color']] }} flex items-center justify-center mb-5">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $group['icon'] }}"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">{{ $group['title'] }}</h3>
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">{{ $group['standard'] }}</p>
                    <ul class="space-y-2.5">
                        @foreach ($group['items'] as $item)
                            <li class="flex gap-2.5 text-gray-600 text-sm">
                                <svg class="w-5 h-5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ЯК ЦЕ ПРАЦЮЄ --}}
    <section id="how" class="scroll-mt-20 bg-gray-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 lg:py-14">
            <div class="text-center max-w-3xl mx-auto mb-7">
                <p class="text-indigo-300 font-bold uppercase tracking-wider text-sm mb-3">Як це працює</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold mb-4">Чотири кроки до роботи без зауважень</h2>
                <p class="text-gray-400 text-lg">Без встановлення програм: потрібні лише браузер і ваш файл Word.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ([
                    ['Зареєструйтеся або увійдіть', 'Створіть обліковий запис студента чи викладача за кілька секунд.'],
                    ['Завантажте .docx', 'Відкрийте «Функції → Нормоконтроль» і оберіть файл роботи (до 5 МБ).'],
                    ['Отримайте звіт', 'Система розбере документ і покаже кожне зауваження: що не так, у якому розділі та в якому фрагменті тексту.'],
                    ['Виправте й перевірте знову', 'Внесіть правки у Word, повторіть перевірку й завантажте звіт у Word для керівника.'],
                ] as $i => [$title, $text])
                    <div class="relative bg-white/5 border border-white/10 rounded-2xl p-7 hover:bg-white/10 transition">
                        <span class="block text-5xl font-black text-transparent bg-clip-text bg-gradient-to-br from-indigo-400 to-sky-300 mb-4">{{ $i + 1 }}</span>
                        <h3 class="text-lg font-bold mb-2">{{ $title }}</h3>
                        <p class="text-gray-400 text-sm leading-relaxed">{{ $text }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 rounded-2xl bg-gradient-to-r from-indigo-600/30 to-sky-500/20 border border-indigo-400/20 p-6 sm:p-8 flex flex-col sm:flex-row gap-4 sm:items-center">
                <svg class="w-8 h-8 shrink-0 text-indigo-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.7 17h4.6M12 3v1m6.4 1.6l-.7.7M21 12h-1M4 12H3m3.3-5.7l-.7-.7m2.8 9.8a5 5 0 117 0l-.5.6a3.4 3.4 0 00-1 2.4V19a2 2 0 11-4 0v-.2c0-.9-.4-1.8-1-2.4l-.5-.5z"/></svg>
                <p class="text-gray-200">
                    <strong class="text-white">Порада:</strong> оформлюйте роботу через стилі Word («Звичайний», «Заголовок 1–3»)
                    і вставляйте зміст через «Посилання → Зміст». Так перевірка буде найточнішою, а виправлення найшвидшими.
                </p>
            </div>
        </div>
    </section>

    {{-- ДЛЯ КОГО --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 lg:py-14">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <div class="rounded-3xl bg-gradient-to-br from-indigo-50 to-white border border-indigo-100 p-8 sm:p-10">
                <p class="text-indigo-600 font-bold uppercase tracking-wider text-sm mb-3">Для студентів</p>
                <h3 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-4">Здавайте роботу з першого разу</h3>
                <p class="text-gray-600 mb-6">Знайдіть помилки оформлення самостійно, до походу на нормоконтроль, і заощадьте час на повторних перевірках.</p>
                <ul class="space-y-3 text-gray-700">
                    @foreach (['Конкретні зауваження з місцем у тексті', 'Одна перевірка щодня', 'Звіт у Word для керівника роботи'] as $item)
                        <li class="flex gap-3"><svg class="w-6 h-6 shrink-0 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-3xl bg-gradient-to-br from-emerald-50 to-white border border-emerald-100 p-8 sm:p-10">
                <p class="text-emerald-600 font-bold uppercase tracking-wider text-sm mb-3">Для викладачів і нормоконтролерів</p>
                <h3 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-4">Менше рутини, більше змісту</h3>
                <p class="text-gray-600 mb-6">Технічні вимоги перевіряє система, а ви зосереджуєтеся на змісті роботи та науковій складовій.</p>
                <ul class="space-y-3 text-gray-700">
                    @foreach (['Однакові критерії для всіх студентів', 'Керування відділеннями, групами й користувачами', 'Масовий імпорт студентів із CSV'] as $item)
                        <li class="flex gap-3"><svg class="w-6 h-6 shrink-0 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section id="faq" class="scroll-mt-20 bg-gray-50 border-t border-gray-100">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10 lg:py-14">
            <div class="text-center mb-6">
                <p class="text-indigo-600 font-bold uppercase tracking-wider text-sm mb-3">FAQ</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Часті запитання</h2>
            </div>

            <div class="space-y-3" x-data="{ open: 0 }">
                @foreach ($faq as $i => $item)
                    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                        <button type="button" @click="open = open === {{ $i }} ? null : {{ $i }}" class="w-full flex items-center justify-between gap-4 text-left px-6 py-5 font-bold text-gray-900 hover:text-indigo-700 transition">
                            {{ $item['q'] }}
                            <svg class="w-5 h-5 shrink-0 text-indigo-600 transition-transform duration-200" :class="{ 'rotate-45': open === {{ $i }} }" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                        </button>
                        <div x-show="open === {{ $i }}" x-transition.opacity class="px-6 pb-5 text-gray-600 leading-relaxed" @if($i !== 0) style="display: none;" @endif>
                            {{ $item['a'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ЗАКЛИК --}}
    <section class="bg-gradient-to-r from-indigo-600 to-sky-600">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex flex-col lg:flex-row items-center justify-between gap-4 text-center lg:text-left">
            <div>
                <h2 class="text-3xl font-extrabold text-white mb-2">Готові перевірити свою роботу?</h2>
                <p class="text-indigo-100 text-lg">Знайдіть помилки оформлення до того, як їх знайде нормоконтролер.</p>
            </div>
            @guest
                <div class="shrink-0 flex flex-col sm:flex-row gap-3">
                    <button @click="isRegisterOpen = true" class="bg-white text-indigo-700 px-8 py-4 rounded-xl font-bold hover:bg-indigo-50 shadow-lg transition">Зареєструватися</button>
                    <button @click="isLoginOpen = true" class="border-2 border-white/70 text-white px-8 py-4 rounded-xl font-bold hover:bg-white/10 transition">Увійти</button>
                </div>
            @else
                <a href="{{ route('norm-control.index') }}" class="shrink-0 bg-white text-indigo-700 px-8 py-4 rounded-xl font-bold hover:bg-indigo-50 shadow-lg transition">Перевірити роботу</a>
            @endguest
        </div>
    </section>
@endsection
