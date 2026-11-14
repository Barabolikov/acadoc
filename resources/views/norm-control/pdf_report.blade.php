@php
    $t = $report['titlePage'];
    $m = $report['metrics'];
    $s = $report['stats'];
    $passed = $m['errors'] === 0;

    // Групи зауважень у звіті
    $categoryOf = function (array $e): string {
        return match (true) {
            (bool) preg_match('/Поля|Сторінка|Нумерація/u', $e['type']) => 'Параметри сторінки',
            (bool) preg_match('/Гарнітура|Шрифт|Інтервал|Абзац/u', $e['type']),
            $e['type'] === 'Форматування (Вирівнювання)' && !str_starts_with($e['message'], 'Заголовок') => 'Основний текст',
            $e['type'] === 'Посилання на джерела' => 'Посилання на джерела',
            $e['type'] === 'Відсутні підписи' || str_contains($e['message'], 'Рисунок') => 'Рисунки й таблиці',
            $e['type'] === 'Стилістика' => 'Стиль викладу',
            default => 'Структура та заголовки',
        };
    };
    $order = ['Параметри сторінки', 'Основний текст', 'Структура та заголовки', 'Рисунки й таблиці', 'Посилання на джерела', 'Стиль викладу'];
    $groups = array_fill_keys($order, []);
    foreach ($report['errors'] as $e) {
        $groups[$categoryOf($e)][] = $e;
    }
    $groups = array_filter($groups);

    $student = $t['student'];
    if ($student && ($t['course'] || $t['group'])) {
        $student .= ' (' . implode(', ', array_filter([$t['course'] ? $t['course'] . ' курс' : null, $t['group'] ? 'група ' . $t['group'] : null])) . ')';
    }
    $volume = implode(' · ', array_filter([
        $s['pages'] ? $s['pages'] . ' с.' : null,
        number_format($s['words'], 0, ',', ' ') . ' слів',
        $s['tables'] . ' табл.',
        $s['images'] . ' рис.',
    ]));
    $rows = [
        ['Файл', '<strong>' . e($report['fileName']) . '</strong>'],
        ['Вид роботи', e($t['workType'] ?? '–')],
        ['Тема', e($t['topic'] ?? '–')],
        ['Дисципліна', e($t['discipline'] ?? '–')],
        ['Студент', e($student ?? '–')],
        ['Спеціальність', $t['specialty'] ? e($t['specialty']) : null],
        ['Керівник', e($t['supervisor'] ?? '–')],
        ['Навчальний заклад', $t['institution'] ? e($t['institution']) : null],
        ['Рік', e(trim(($t['city'] ? $t['city'] . ', ' : '') . ($t['year'] ?? '–'), ', '))],
        ['Обсяг', e($volume)],
        ['Перевірив', $report['checkedBy'] ? e($report['checkedBy']) : null],
        ['SHA-256 роботи', '<span class="mono">' . e($report['fileHash']) . '</span><br><span class="muted small">контрольна сума завантаженого файлу</span>'],
    ];
@endphp
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <title>Звіт нормоконтролю {{ $report['number'] }}</title>
    <style>
        @page { margin: 22mm 18mm 20mm 18mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.45; }

        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; border-top: 1px solid #e5e7eb; padding-top: 2mm; font-size: 7.5pt; color: #6b7280; }
        .footer table { width: 100%; border-collapse: collapse; }

        .brand { font-size: 13pt; font-weight: bold; color: #1e1b4b; }
        .logo { display: inline-block; width: 16pt; height: 16pt; background: #4f46e5; color: #fff; border-radius: 4pt; text-align: center; font-weight: bold; line-height: 16pt; margin-right: 4pt; }
        h1 { font-size: 17pt; color: #1e1b4b; margin: 10pt 0 2pt; }
        .meta { color: #6b7280; font-size: 9pt; }
        .meta strong { color: #374151; }
        .status { display: inline-block; padding: 6pt 10pt; border-radius: 6pt; font-weight: bold; font-size: 9.5pt; text-align: center; }
        .status.ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .status.bad { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        h2 { font-size: 13pt; color: #1e1b4b; margin: 18pt 0 8pt; padding-bottom: 4pt; border-bottom: 1.5pt solid #6366f1; }
        h3 { font-size: 10.5pt; color: #1e1b4b; margin: 12pt 0 4pt; }

        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 3.5pt 0; vertical-align: top; }
        table.info td.label { width: 32%; color: #6b7280; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; }
        .muted { color: #6b7280; }
        .small { font-size: 7.5pt; }

        table.metrics { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
        table.metrics th { text-align: left; font-size: 7.5pt; color: #4b5563; padding: 0 6pt 4pt 0; border-bottom: 1px solid #d1d5db; vertical-align: bottom; }
        table.metrics td { padding: 6pt 6pt 6pt 0; vertical-align: top; border-bottom: 1px solid #f3f4f6; }
        .big { font-size: 17pt; font-weight: bold; color: #1e1b4b; }
        .big.red { color: #b91c1c; }
        .big.green { color: #15803d; }
        .note { font-size: 7.5pt; color: #6b7280; margin: 6pt 0; }
        ul.criteria { margin: 2pt 0 0 0; padding-left: 12pt; font-size: 7.5pt; color: #4b5563; }
        .callout { background: #f3f4f6; border-left: 3pt solid #6366f1; padding: 7pt 10pt; margin-top: 8pt; font-size: 9pt; }

        table.errors { width: 100%; border-collapse: collapse; margin-bottom: 6pt; }
        table.errors th { background: #f3f4f6; text-align: left; font-size: 7.5pt; color: #4b5563; padding: 4pt 6pt; }
        table.errors td { padding: 4pt 6pt; border-bottom: 1px solid #e5e7eb; vertical-align: top; font-size: 8.5pt; }
        table.errors td.n { width: 6%; color: #9ca3af; }
        table.errors td.where { width: 30%; color: #4b5563; font-size: 8pt; }
        .ctx { color: #6b7280; font-size: 7.5pt; }
        .count { color: #6b7280; font-weight: normal; font-size: 9pt; }

        .decision td { padding: 5pt 0; vertical-align: top; }
        .box { display: inline-block; width: 9pt; height: 9pt; border: 1px solid #374151; margin-right: 4pt; }
        .line { border-bottom: 1px solid #9ca3af; height: 16pt; }
        .sign { margin-top: 26pt; width: 60%; border-top: 1px solid #374151; padding-top: 3pt; font-size: 8pt; color: #4b5563; }
        .keep { page-break-inside: avoid; }
    </style>
</head>
<body>

<div class="footer">
    <table><tr>
        <td><strong>Acadoc</strong> · {{ $report['number'] }} · {{ $report['issuedAt'] }}</td>
        {{-- «Сторінка X з Y» дописує NormControlController::makeReportPdf() --}}
    </tr></table>
</div>

{{-- Шапка --}}
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="vertical-align: top;">
            <div class="brand"><span class="logo">A</span>Acadoc</div>
            <h1>Звіт нормоконтролю роботи</h1>
            <div class="meta">Acadoc · Номер звіту <strong>{{ $report['number'] }}</strong> · Видано {{ $report['issuedAt'] }}</div>
        </td>
        <td style="vertical-align: top; text-align: right; width: 36%;">
            <div class="status {{ $passed ? 'ok' : 'bad' }}">
                {{ $passed ? 'Відповідає вимогам' : 'Потребує доопрацювання' }}<br>
                <span style="font-weight: normal; font-size: 7.5pt;">за результатами автоматичної перевірки</span>
            </div>
        </td>
    </tr>
</table>

{{-- 1. Робота --}}
<h2>1. Робота</h2>
<table class="info">
    @foreach ($rows as [$label, $value])
        @continue($value === null)
        <tr><td class="label">{{ $label }}</td><td>{!! $value !!}</td></tr>
    @endforeach
</table>

{{-- 2. Показники --}}
<h2>2. Показники</h2>
<table class="metrics">
    <tr>
        <th style="width: 22%;">Основний текст</th>
        <th style="width: 20%;">Параметри сторінки</th>
        <th style="width: 19%;">Структура роботи</th>
        <th style="width: 21%;">Посилання на джерела</th>
        <th style="width: 18%;">Усього зауважень</th>
    </tr>
    <tr>
        <td>
            @if ($m['body']['percent'] === null)
                <span class="big">–</span><br><span class="small muted">не знайдено розділ «ВСТУП»</span>
            @else
                <span class="big {{ $m['body']['percent'] === 100 ? 'green' : '' }}">{{ $m['body']['percent'] }}%</span><br>
                <span class="small muted">абзаців без порушень<br>{{ $m['body']['ok'] }} з {{ $m['body']['checked'] }}</span>
            @endif
        </td>
        <td>
            <span class="big {{ $m['page'] ? 'red' : 'green' }}">{{ $m['page'] }}</span><br>
            <span class="small muted">зауважень: поля, формат, нумерація</span>
        </td>
        <td>
            <span class="big {{ $m['structure']['found'] === $m['structure']['total'] ? 'green' : 'red' }}">{{ $m['structure']['found'] }} з {{ $m['structure']['total'] }}</span><br>
            <span class="small muted">обов'язкових елементів</span>
        </td>
        <td>
            @if (!$m['citations']['found'] || $m['citations']['total'] === 0)
                <span class="big red">–</span><br><span class="small muted">список джерел не знайдено</span>
            @else
                <span class="big {{ $m['citations']['cited'] === $m['citations']['total'] ? 'green' : 'red' }}">{{ $m['citations']['cited'] }} з {{ $m['citations']['total'] }}</span><br>
                <span class="small muted">джерел мають посилання в тексті</span>
            @endif
        </td>
        <td>
            <span class="big {{ $passed ? 'green' : 'red' }}">{{ $m['errors'] }}</span><br>
            <span class="small muted">у {{ count($groups) }} {{ count($groups) === 1 ? 'категорії' : 'категоріях' }}</span>
        </td>
    </tr>
</table>

<p class="note keep">
    Показники є автоматичними допоміжними засобами для студента й нормоконтролера. Перевірка аналізує внутрішню
    розмітку файлу Word і може не врахувати оформлення, зроблене нестандартними засобами. Рішення про допуск роботи ухвалює нормоконтролер.
</p>
<div class="note" style="margin-bottom: 0;"><strong>Застосовані критерії</strong> (ДСТУ 3008:2015, ГОСТ 2.105, методичні вимоги закладу):</div>
<ul class="criteria">
    <li>Аркуш А4; ліве поле 30 мм, праве ≥ 10 мм, верхнє й нижнє ≥ 20 мм; нумерація в правому верхньому куті, без номера на титулці</li>
    <li>Основний текст: Times New Roman 14 пт, інтервал 1,5, абзацний відступ 1,25 см, вирівнювання по ширині (без таблиць, підписів і лістингів коду)</li>
    <li>Структура: титульний аркуш, завдання, зміст, вступ, висновки, список джерел; кожен структурний елемент і розділ — з нової сторінки</li>
    <li>Підписи «Рисунок N» і «Таблиця N»; посилання на джерела у форматі [1], [1, с. 25]; на кожне джерело списку є посилання</li>
</ul>

<div class="callout">
    @if ($m['mainFont'])
        Шрифт основного тексту: <strong>{{ $m['mainFont'] }}</strong> ({{ $m['mainFontShare'] }}% тексту){{ $m['mainFont'] === 'Times New Roman' ? '' : ' — треба Times New Roman' }}.
    @endif
    @if ($m['structure']['missing'])
        Не знайдено: {{ implode(', ', $m['structure']['missing']) }}.
    @elseif (!$m['mainFont'])
        Усі обов'язкові структурні елементи знайдено.
    @endif
</div>

{{-- 3. Зауваження --}}
<h2>3. Зауваження</h2>
@if (!$groups)
    <div class="callout" style="border-left-color: #22c55e;">Зауважень за застосованими критеріями не виявлено.</div>
@endif
@php $n = 0; @endphp
@foreach ($groups as $category => $errors)
    <h3>{{ $category }} <span class="count">· {{ count($errors) }}</span></h3>
    <table class="errors">
        <thead><tr><th>№</th><th>Зауваження</th><th>Місце в роботі</th></tr></thead>
        <tbody>
        @foreach ($errors as $error)
            <tr class="keep">
                <td class="n">{{ ++$n }}</td>
                <td>
                    <strong>{{ $error['message'] }}</strong>
                    @if ($error['context'])<br><span class="ctx">{{ $error['context'] }}</span>@endif
                </td>
                <td class="where">{{ $error['location'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endforeach

{{-- 4. Рішення нормоконтролера --}}
<div class="keep">
    <h2>4. Рішення нормоконтролера</h2>
    <table class="decision" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 32%;" class="muted">Статус</td>
            <td><span class="box"></span>Допущено до захисту &nbsp;&nbsp;&nbsp; <span class="box"></span>Повернуто на доопрацювання</td>
        </tr>
        <tr>
            <td class="muted">Коментар</td>
            <td><div class="line"></div><div class="line"></div><div class="line"></div></td>
        </tr>
    </table>

    <p class="note" style="margin-top: 14pt;">
        Цей звіт створено сервісом Acadoc. Він фіксує результати автоматичної перевірки файлу з контрольною сумою,
        зазначеною вище, на момент видачі звіту. Рішення щодо роботи ухвалює нормоконтролер.
    </p>
    <div class="sign">Підпис нормоконтролера та дата</div>
</div>

</body>
</html>
