@extends('layouts.app')

@section('title', 'Правила сервісу | Acadoc')

@php
    $admin = '<a href="https://t.me/Barabolikov" target="_blank" rel="noopener" class="font-semibold text-indigo-600 hover:underline">@Barabolikov</a>';
    $sections = [
        'general' => 'Загальні положення',
        'account' => 'Реєстрація та обліковий запис',
        'statuses' => 'Статуси та ліміти',
        'upload' => 'Завантаження робіт',
        'report' => 'Звіт про перевірку',
        'fix' => 'Автоматичне виправлення («Преміум»)',
        'privacy' => 'Конфіденційність і зберігання даних',
        'forbidden' => 'Що заборонено',
        'liability' => 'Відповідальність',
        'contacts' => 'Контакти та зміни правил',
    ];
    $n = 0;
@endphp

@section('content')
    <div class="max-w-5xl mx-auto px-4 py-6">

        <div class="mb-5">
            <p class="text-sm font-semibold text-indigo-600 uppercase tracking-wide mb-1">Acadoc</p>
            <h1 class="text-3xl font-extrabold text-gray-900 mb-2">Правила сервісу</h1>
            <p class="text-gray-600">
                Правила користування сервісом автоматичного нормоконтролю Acadoc для студентів і викладачів
                ВСП «Конотопський індустріально-педагогічний фаховий коледж СумДУ». Користуючись сервісом, ви погоджуєтеся з цими правилами.
            </p>
        </div>

        {{-- Коротко --}}
        <div class="grid sm:grid-cols-3 gap-3 mb-6">
            <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4">
                <p class="text-2xl mb-1">📄</p>
                <p class="font-bold text-gray-900">Лише .docx до 5 МБ</p>
                <p class="text-sm text-gray-600">Остаточна версія вашої курсової чи дипломної роботи.</p>
            </div>
            <div class="rounded-xl border border-amber-100 bg-amber-50 p-4">
                <p class="text-2xl mb-1">⏱️</p>
                <p class="font-bold text-gray-900">Студент — {{ \App\Models\User::STUDENT_DAILY_CHECKS }} перевірка на день</p>
                <p class="text-sm text-gray-600">Викладач і «Преміум» — без обмежень.</p>
            </div>
            <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-4">
                <p class="text-2xl mb-1">🔒</p>
                <p class="font-bold text-gray-900">Файли не зберігаються</p>
                <p class="text-sm text-gray-600">Лише назва, контрольна сума та кількість зауважень.</p>
            </div>
        </div>

        <div class="grid lg:grid-cols-[230px_1fr] gap-6 items-start">
            {{-- Зміст --}}
            <nav class="lg:sticky lg:top-24 rounded-xl border border-gray-200 bg-white p-4 text-sm">
                <p class="font-bold text-gray-900 mb-2">Зміст</p>
                <ol class="space-y-1 list-decimal pl-5 text-gray-600">
                    @foreach ($sections as $id => $title)
                        <li><a href="#{{ $id }}" class="hover:text-indigo-600">{{ $title }}</a></li>
                    @endforeach
                </ol>
            </nav>

            <div class="space-y-3 text-gray-700 leading-relaxed">

                <section id="general" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['general'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Acadoc — корпоративний сервіс коледжу, який автоматично перевіряє оформлення студентських робіт (нормоконтроль) на відповідність ДСТУ 3008:2015, ДСТУ 8302:2015, стандартам ЄСКД та методичним рекомендаціям коледжу.</li>
                        <li>Сервіс створено й підтримує студентський гурток «WEB DEVELOPER» під керівництвом викладача коледжу.</li>
                        <li>Сервіс перевіряє лише <strong>оформлення</strong>: поля, шрифти, інтервали, заголовки, структуру, підписи, посилання, список джерел, рамку тощо. Зміст роботи й унікальність тексту (плагіат) не перевіряються.</li>
                        <li>Результат перевірки має рекомендаційний характер. Остаточне рішення щодо роботи ухвалюють керівник і нормоконтролер.</li>
                    </ol>
                </section>

                <section id="account" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['account'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Для перевірки робіт потрібен обліковий запис. Під час реєстрації вказуйте справжні прізвище та ім'я і робочу електронну пошту.</li>
                        <li>Одна людина — один обліковий запис. Не передавайте свій пароль іншим особам: за дії у вашому обліковому записі відповідаєте ви.</li>
                        <li>Після реєстрації кожен користувач отримує статус «Студент». Якщо під час реєстрації ви обрали «Викладач», роль підтверджує адміністратор — напишіть йому {!! $admin !!} у Telegram.</li>
                        <li>Форми входу та реєстрації захищено перевіркою «Я не робот» (Cloudflare Turnstile).</li>
                        <li>Адміністратор може змінити статус користувача або заблокувати обліковий запис у разі порушення цих правил.</li>
                    </ol>
                </section>

                <section id="statuses" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-3">{{ ++$n }}. {{ $sections['statuses'] }}</h2>
                    <div class="overflow-x-auto mb-3">
                        <table class="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                            <thead class="bg-gray-50 text-left text-gray-900">
                                <tr>
                                    <th class="p-3 font-bold">Статус</th>
                                    <th class="p-3 font-bold">Перевірок на день</th>
                                    <th class="p-3 font-bold">Автовиправлення</th>
                                    <th class="p-3 font-bold">Як отримати</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr>
                                    <td class="p-3 font-semibold">Студент</td>
                                    <td class="p-3">{{ \App\Models\User::STUDENT_DAILY_CHECKS }}</td>
                                    <td class="p-3">—</td>
                                    <td class="p-3">Автоматично після реєстрації</td>
                                </tr>
                                <tr>
                                    <td class="p-3 font-semibold">Викладач</td>
                                    <td class="p-3">Без обмежень</td>
                                    <td class="p-3">—</td>
                                    <td class="p-3">Надає адміністратор на запит</td>
                                </tr>
                                <tr class="bg-amber-50/50">
                                    <td class="p-3 font-semibold">Преміум</td>
                                    <td class="p-3">Без обмежень</td>
                                    <td class="p-3">✔</td>
                                    <td class="p-3">Пожертва {{ \App\Models\Payment::priceUah() }} грн/місяць або {{ \App\Models\Payment::priceUah('lifetime') }} грн назавжди на <a href="{{ route('premium') }}" class="text-indigo-600 underline">сторінці «Преміум»</a> (або надає адміністратор)</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Денний ліміт студента оновлюється щодня о 00:00 за київським часом. Перевірка рахується в момент завантаження файлу, тож завантажуйте остаточну версію роботи.</li>
                        <li>Якщо файл пошкоджений і його не вдалося перевірити, спроба не зараховується.</li>
                        <li>Щоб отримати статус «Викладач», зверніться до адміністратора {!! $admin !!} у Telegram.</li>
                        <li>Статус «Преміум» надається після символічної пожертви через monobank або PayPal: {{ \App\Models\Payment::priceUah() }} грн — на 1 місяць (кожна наступна така пожертва продовжує його ще на місяць), {{ \App\Models\Payment::priceUah('lifetime') }} грн — назавжди. Для monobank тариф визначається сумою переказу. Після закінчення місячного строку застосовуються звичайні ліміти. Пожертва добровільна й неповоротна; кошти використовуються на оплату хостингу, домену, розвиток і вдосконалення сервісу.</li>
                        <li>Для «Преміум» максимальний розмір файлу — {{ \App\Http\Controllers\NormControlController::PREMIUM_MAX_UPLOAD_MB }} МБ.</li>
                    </ol>
                </section>

                <section id="upload" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['upload'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Приймаються лише документи Word у форматі <strong>.docx</strong> розміром до <strong>{{ \App\Http\Controllers\NormControlController::MAX_UPLOAD_MB }} МБ</strong> (зі статусом «Преміум» — до {{ \App\Http\Controllers\NormControlController::PREMIUM_MAX_UPLOAD_MB }} МБ). Якщо файл більший — стисніть рисунки (Формат рисунка → Стиснути рисунки).</li>
                        <li>Файли .doc, .pdf, .odt і скани не перевіряються — збережіть роботу у Word як .docx.</li>
                        <li>Студент завантажує лише власні роботи. Викладач — роботи студентів, якими керує, або методичні матеріали.</li>
                        <li>Перед кожним завантаженням потрібно пройти перевірку «Я не робот».</li>
                    </ol>
                </section>

                <section id="report" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['report'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Після перевірки сервіс показує перелік зауважень із місцем у тексті та порадою, як їх виправити, і формує звіт у форматі Word (.docx).</li>
                        <li>Звіт має унікальний номер (NC-…) та контрольну суму файлу (SHA-256), за якою керівник може переконатися, що звіт стосується саме поданої версії роботи.</li>
                        <li>Отриманий файл звіту слід надіслати керівнику проєкту. Не редагуйте звіт вручну.</li>
                        <li>Звіт доступний для завантаження до виходу із системи або до наступної перевірки.</li>
                    </ol>
                </section>

                <section id="fix" class="scroll-mt-20 rounded-2xl border border-amber-200 bg-amber-50/40 p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['fix'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Користувачі зі статусом «Преміум» можуть натиснути «Виправити помилки» і завантажити виправлену копію роботи.</li>
                        <li>Автоматично виправляються: формат аркуша A4 і поля сторінки; шрифт Times New Roman, кегль 14 пт, міжрядковий інтервал 1,5, вирівнювання по ширині та абзацний відступ 1,25 см в основному тексті; заголовки (з нової сторінки, великими літерами, по центру, без крапки в кінці).</li>
                        <li>Не виправляються автоматично й потребують вашої уваги: посилання на джерела та оформлення списку джерел, підписи рисунків і таблиць, формули, додатки, рамка й основний напис, автоматичний зміст, нумерація сторінок, стилістика тексту.</li>
                        <li>Після виправлення обов'язково перегляньте роботу, оновіть зміст (ПКМ → Оновити поле) і за потреби перевірте її повторно. Відповідальність за остаточний вигляд роботи несе її автор.</li>
                        <li>Посилання на виправлену роботу діє 24 години або до вашої наступної перевірки.</li>
                    </ol>
                </section>

                <section id="privacy" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['privacy'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Завантажені роботи <strong>не зберігаються</strong>: файл аналізується й одразу видаляється. В історії перевірок лишаються тільки назва файлу, його контрольна сума, кількість зауважень і дата.</li>
                        <li>Виняток — виправлена копія для «Преміум»: вона тимчасово зберігається на сервері коледжу, доступна лише вам і автоматично видаляється через 24 години або під час наступної перевірки.</li>
                        <li>Платежі обробляють monobank і PayPal: дані картки сервіс не отримує й не зберігає. Ми зберігаємо лише суму, дату, ідентифікатор операції та коментар з кодом.</li>
                        <li>Дані облікового запису (ім'я, пошта, статус) використовуються лише для роботи сервісу й не передаються третім особам.</li>
                        <li>Для захисту від ботів використовується сервіс Cloudflare Turnstile, який обробляє технічні дані браузера відповідно до власної політики конфіденційності.</li>
                    </ol>
                </section>

                <section id="forbidden" class="scroll-mt-20 rounded-2xl border border-red-100 bg-red-50/40 p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['forbidden'] }}</h2>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>створювати кілька облікових записів, щоб обійти денний ліміт перевірок;</li>
                        <li>передавати обліковий запис або виправлені файли іншим особам для отримання переваг;</li>
                        <li>завантажувати чужі роботи без згоди автора, а також файли з шкідливим кодом;</li>
                        <li>надсилати автоматизовані запити, намагатися обійти капчу чи отримати несанкціонований доступ до сервісу;</li>
                        <li>видавати змінений вручну звіт за звіт сервісу.</li>
                    </ul>
                    <p class="mt-2">За порушення адміністратор може обмежити доступ до сервісу та повідомити керівника.</p>
                </section>

                <section id="liability" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['liability'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Сервіс надається «як є». Автоматична перевірка може не помітити окремих помилок або помилково позначити правильне оформлення — у спірних випадках керуйтеся вимогами стандартів і порадами керівника.</li>
                        <li>Сервіс не замінює керівника, нормоконтролера та перевірку на академічну доброчесність.</li>
                        <li>Зберігайте резервну копію своєї роботи: розробники не відповідають за втрату даних на вашому пристрої.</li>
                    </ol>
                </section>

                <section id="contacts" class="scroll-mt-20 rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">{{ ++$n }}. {{ $sections['contacts'] }}</h2>
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>З питань статусів, помилок у роботі сервісу та пропозицій звертайтеся до адміністратора {!! $admin !!} у Telegram.</li>
                        <li>Правила можуть оновлюватися. Чинна редакція завжди розміщена на цій сторінці; продовження користування сервісом означає згоду з нею.</li>
                    </ol>
                    <p class="mt-3 text-sm text-gray-500">Редакція від 15.11.2026.</p>
                </section>
            </div>
        </div>
    </div>
@endsection
