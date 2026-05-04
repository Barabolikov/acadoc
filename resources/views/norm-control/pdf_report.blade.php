<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Звіт нормоконтролю</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; border-bottom: 2px solid #4f46e5; padding-bottom: 10px; margin-bottom: 20px; }
        .stat-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .stat-table td { padding: 8px; border: 1px solid #eee; }
        .error-table { width: 100%; border-collapse: collapse; }
        .error-table th { background: #f8fafc; padding: 10px; border: 1px solid #ddd; text-align: left; }
        .error-table td { padding: 10px; border: 1px solid #ddd; }
        .badge { padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
<div class="header">
    <h2>ЗВІТ НОРМОКОНТРОЛЮ КУРСОВОГО ПРОЄКТУ</h2>
    <p>Файл: {{ $fileName }} | Дата: {{ $checkDate }}</p>
</div>

<table class="stat-table">
    <tr>
        <td><strong>Студент:</strong> {{ $studentName }}</td>
        <td><strong>Керівник:</strong> {{ $supervisorName }}</td>
    </tr>
    <tr>
        <td><strong>Абзаців:</strong> {{ $paragraphsCount }}</td>
        <td><strong>Символів:</strong> {{ number_format($charsCount, 0, '', ' ') }}</td>
    </tr>
    <tr>
        <td><strong>Таблиць:</strong> {{ $tablesCount }}</td>
        <td><strong>Рисунків:</strong> {{ $imagesCount }}</td>
    </tr>
    <tr>
        <td colspan="2" style="background: #f1f5f9; text-align: center;">
            <strong>ЗАГАЛЬНА КІЛЬКІСТЬ ПОМИЛОК: {{ count($actionableErrors) }}</strong>
        </td>
    </tr>
</table>

<h3>ЖУРНАЛ ВИПРАВЛЕНЬ</h3>
<table class="error-table">
    <thead>
    <tr>
        <th>Тип / Місце</th>
        <th>Опис помилки</th>
    </tr>
    </thead>
    <tbody>
    @foreach($actionableErrors as $error)
        <tr>
            <td>
                <span class="badge">{{ $error['type'] }}</span><br>
                <small>{{ $error['location'] }}</small>
            </td>
            <td>
                <strong>{{ $error['message'] }}</strong><br>
                <small style="color: #666 italic">Контекст: {{ $error['context'] }}</small>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<div style="margin-top: 30px; font-size: 10px; text-align: right; color: #94a3b8;">
    Розроблено: {{ $developer }}
</div>
</body>
</html>
