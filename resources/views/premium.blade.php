@extends('layouts.app')

@section('title', 'Преміум | Acadoc')

@php
    $maxMb = \App\Http\Controllers\NormControlController::PREMIUM_MAX_UPLOAD_MB;
    $baseMb = \App\Http\Controllers\NormControlController::MAX_UPLOAD_MB;
    $admin = '<a href="https://t.me/Barabolikov" target="_blank" rel="noopener" class="font-semibold text-indigo-600 underline">@Barabolikov</a>';
@endphp

@section('content')
    <div class="max-w-4xl mx-auto px-4 py-6">

        <div class="mb-5 text-center">
            <p class="text-amber-600 font-bold uppercase tracking-wider text-sm mb-2">⭐ Преміум-акаунт</p>
            @if (\App\Models\Payment::paymentsEnabled())
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">Підтримайте розвиток Acadoc</h1>
            <p class="text-gray-600 max-w-2xl mx-auto">
                Символічна пожертва на розвиток проєкту відкриває статус «Преміум»:
                <strong>{{ $price }} грн — на місяць</strong> або <strong>{{ $lifetimePrice }} грн — назавжди</strong>.
                Кошти йдуть на оплату хостингу та домену, розвиток і вдосконалення сервісу.
            </p>
            @else
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2">Преміум-акаунт Acadoc</h1>
            <p class="text-gray-600 max-w-2xl mx-auto">Статус «Преміум» надається особисто адміністратором ресурсу.</p>
            @endif
        </div>

        @if ($errors->has('payment'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{{ $errors->first('payment') }}</div>
        @endif

        <div class="grid sm:grid-cols-3 gap-3 mb-5">
            @foreach ([
                ['♾️', 'Безліміт перевірок', 'Без денного обмеження для студентів.'],
                ['🛠️', 'Автовиправлення', 'Шрифт, інтервали, відступи, поля й заголовки — одним натисканням.'],
                ['📦', "Файли до {$maxMb} МБ", "Замість {$baseMb} МБ — для робіт з великою кількістю рисунків."],
            ] as [$icon, $title, $text])
                <div class="rounded-xl border border-amber-100 bg-amber-50 p-4">
                    <p class="text-2xl mb-1">{{ $icon }}</p>
                    <p class="font-bold text-gray-900">{{ $title }}</p>
                    <p class="text-sm text-gray-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>

        {{-- Тарифи --}}
        @if (\App\Models\Payment::paymentsEnabled())
        <div class="grid sm:grid-cols-2 gap-3 mb-5">
            <div class="rounded-2xl border-2 border-gray-200 bg-white p-5 text-center">
                <p class="font-bold text-gray-900">На місяць</p>
                <p class="text-4xl font-black text-gray-900 my-1">{{ $price }} <span class="text-xl">грн</span></p>
                <p class="text-sm text-gray-500">Повторна пожертва продовжує «Преміум» ще на місяць.</p>
            </div>
            <div class="relative rounded-2xl border-2 border-amber-400 bg-amber-50 p-5 text-center">
                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-amber-500 text-white text-xs font-bold uppercase rounded-full px-3 py-1">Вигідно</span>
                <p class="font-bold text-gray-900">Назавжди</p>
                <p class="text-4xl font-black text-gray-900 my-1">{{ $lifetimePrice }} <span class="text-xl">грн</span></p>
                <p class="text-sm text-gray-600">Один раз — і «Преміум» без строку дії.</p>
            </div>
        </div>
        @endif

        <div id="pay" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
            @if ($isPremium)
                <div class="text-center {{ $isAdmin || $isLifetime ? '' : 'mb-6 pb-6 border-b border-gray-100' }}">
                    <p class="text-4xl mb-2">🎉</p>
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">
                        @if ($premiumStatus) Статус «Преміум» діє {{ $premiumStatus }} @else У вас є статус «Преміум» @endif
                    </h2>
                    <p class="text-gray-600 mb-4">Дякуємо за підтримку проєкту! Безліміт перевірок і автовиправлення вже доступні.</p>
                    <a href="{{ route('norm-control.index') }}" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-6 rounded-xl transition">Перевірити роботу</a>
                </div>
            @endif

            @if ($isAdmin || $isLifetime)
                {{-- «Преміум» без строку — платити нічого не треба --}}
            @elseif (! $user)
                <div class="text-center">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ \App\Models\Payment::paymentsEnabled() ? 'Увійдіть, щоб зробити пожертву' : 'Увійдіть, щоб отримати «Преміум»' }}</h2>
                    <p class="text-gray-600 mb-4">Статус «Преміум» надається вашому обліковому запису, тож спершу увійдіть або зареєструйтеся.</p>
                    <div class="flex flex-col sm:flex-row justify-center gap-3">
                        <button type="button" @click="isLoginOpen = true" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-6 rounded-xl transition">Увійти</button>
                        <button type="button" @click="isRegisterOpen = true" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 font-bold py-3 px-6 rounded-xl transition">Зареєструватися</button>
                    </div>
                </div>
            @elseif (! $monobank && ! $paypal)
                {{-- Онлайн-оплату вимкнено (PREMIUM_PAYMENTS_ENABLED=false) — статус видає адміністратор --}}
                <div class="text-center text-gray-700 max-w-xl mx-auto">
                    <p class="text-4xl mb-2">✈️</p>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">Статус «Преміум» надається особисто адміністратором ресурсу</h2>
                    <p class="mb-4">
                        Щоб отримати його, напишіть адміністратору {!! $admin !!} у Telegram
                        і вкажіть пошту, з якою ви зареєструвалися: <strong>{{ $user->email }}</strong>.
                    </p>
                    <a href="{{ \App\Models\Payment::TELEGRAM_URL }}" target="_blank" rel="noopener"
                       class="inline-block bg-sky-500 hover:bg-sky-600 text-white font-bold py-3 px-6 rounded-xl transition">
                        Написати {{ \App\Models\Payment::TELEGRAM_HANDLE }} у Telegram
                    </a>
                </div>
            @else
                <h2 class="text-xl font-bold text-gray-900 mb-4">{{ $isPremium ? 'Продовжити «Преміум» або оформити назавжди' : 'Оберіть спосіб пожертви' }}</h2>
                <div class="grid {{ $monobank && $paypal ? 'md:grid-cols-2' : '' }} gap-4">

                    @if ($monobank)
                        <div class="rounded-xl border-2 border-gray-900 p-5">
                            <p class="font-extrabold text-lg text-gray-900 mb-1">monobank</p>
                            <p class="text-sm text-gray-500 mb-3">Банка monobank · будь-яка картка українського банку</p>

                            @if ($pending)
                                <ol class="list-decimal pl-5 space-y-2 text-sm text-gray-700 mb-4">
                                    <li>Ваш код: <span class="font-mono text-2xl font-black tracking-widest text-gray-900 bg-amber-100 rounded px-2">{{ $pending->code }}</span></li>
                                    <li>Відкрийте банку й переказуйте <strong>{{ $price }} грн</strong> (місяць) або <strong>{{ $lifetimePrice }} грн</strong> (назавжди) — тариф визначається сумою.</li>
                                    <li>У полі <strong>«Коментар»</strong> вкажіть код <strong>{{ $pending->code }}</strong> — без нього пожертву не вдасться зарахувати.</li>
                                    <li>@if ($monobankAuto) Статус активується автоматично протягом хвилини. @else Після переказу статус активує адміністратор. @endif</li>
                                </ol>
                                <div class="grid grid-cols-2 gap-2 mb-2">
                                    <a href="{{ $jarUrl }}" target="_blank" rel="noopener" class="text-center bg-gray-900 hover:bg-black text-white font-bold py-3 px-2 rounded-xl transition">
                                        {{ $price }} грн<span class="block text-xs font-normal text-gray-300">на місяць</span>
                                    </a>
                                    <a href="{{ $jarLifetimeUrl }}" target="_blank" rel="noopener" class="text-center bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 px-2 rounded-xl transition">
                                        {{ $lifetimePrice }} грн<span class="block text-xs font-normal text-amber-100">назавжди</span>
                                    </a>
                                </div>
                                @if ($monobankAuto)
                                    <form action="{{ route('premium.monobank.check') }}" method="POST">
                                        @csrf
                                        <button type="submit" class="w-full bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 font-semibold py-2.5 px-4 rounded-xl transition">
                                            Я переказав(-ла) — перевірити
                                        </button>
                                    </form>
                                @endif
                            @else
                                <p class="text-sm text-gray-700 mb-4">Ми видамо персональний код — вкажіть його в коментарі до переказу, і статус активується автоматично.</p>
                                <form action="{{ route('premium.monobank') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="w-full bg-gray-900 hover:bg-black text-white font-bold py-3 px-4 rounded-xl transition">Отримати код і підтримати</button>
                                </form>
                            @endif
                        </div>
                    @endif

                    @if ($paypal)
                        <div class="rounded-xl border-2 border-sky-200 p-5">
                            <p class="font-extrabold text-lg text-sky-800 mb-1">PayPal</p>
                            <p class="text-sm text-gray-500 mb-3">Для оплати з-за кордону (PayPal не працює з гривнею)</p>
                            <p class="text-sm text-gray-700 mb-4">Вас буде перенаправлено на сайт PayPal. Після оплати статус «Преміум» активується одразу.</p>
                            <form action="{{ route('premium.paypal') }}" method="POST" class="grid grid-cols-2 gap-2">
                                @csrf
                                <button type="submit" name="plan" value="month" class="bg-sky-500 hover:bg-sky-600 text-white font-bold py-3 px-2 rounded-xl transition">
                                    {{ $paypalPrice }}<span class="block text-xs font-normal text-sky-100">на місяць</span>
                                </button>
                                <button type="submit" name="plan" value="lifetime" class="bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 px-2 rounded-xl transition">
                                    {{ $paypalLifetimePrice }}<span class="block text-xs font-normal text-amber-100">назавжди</span>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>

                <p class="mt-4 text-xs text-gray-500">
                    Пожертва добровільна й неповоротна. {{ $price }} грн додають 1 місяць «Преміум», {{ $lifetimePrice }} грн — «Преміум» назавжди для вашого облікового запису.
                    Проблеми з оплатою — пишіть {!! $admin !!} у Telegram.
                    Детальніше — у <a href="{{ route('rules') }}#statuses" class="font-semibold text-indigo-600 underline">Правилах сервісу</a>.
                </p>
            @endif
        </div>

        @if (\App\Models\Payment::paymentsEnabled())
        <p class="mt-4 text-center text-sm text-gray-500">
            💛 Кошти пожертв використовуються на оплату хостингу та домену, розвиток і вдосконалення сервісу.
        </p>
        @endif
    </div>
@endsection
