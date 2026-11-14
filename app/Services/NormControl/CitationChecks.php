<?php

namespace App\Services\NormControl;

/**
 * Звірка посилань у тексті ([5], [5, с. 12], [2; 7–9]) зі списком використаних джерел.
 */
class CitationChecks
{
    /** @var array<int, true> номери джерел, на які є посилання */
    private array $cited = [];

    /** @var list<string> записи списку джерел */
    private array $entries = [];

    private bool $hasReferences = false;

    private ?string $referencesTitle = null;

    public function addText(string $text): void
    {
        if (! preg_match_all('/\[([^\[\]]{1,80})\]/u', $text, $m)) {
            return;
        }

        foreach ($m[1] as $inside) {
            foreach (preg_split('/[;,]/u', $inside) as $part) {
                $part = trim($part);
                if (preg_match('/^(\d{1,3})\s*[-–—]\s*(\d{1,3})$/u', $part, $r) && $r[2] >= $r[1] && $r[2] - $r[1] <= 50) {
                    foreach (range((int) $r[1], (int) $r[2]) as $n) {
                        $this->cited[$n] = true;
                    }
                } elseif (preg_match('/^\d{1,3}$/', $part)) {
                    $this->cited[(int) $part] = true;
                } else {
                    // «с. 12», «p. 5», «Електронний ресурс» — далі номерів джерел немає
                    break;
                }
            }
        }
    }

    /** Початок списку джерел. Повторна зустріч (рядок змісту, потім справжній заголовок) починає список заново. */
    public function startReferences(string $title): void
    {
        $this->hasReferences = true;
        $this->referencesTitle = $title;
        $this->entries = [];
    }

    public function addReference(string $text): void
    {
        $this->entries[] = $text;
    }

    public function referencesCount(): int
    {
        return count($this->entries);
    }

    /**
     * Оформлення записів за ДСТУ 8302:2015 — базові правила, які можна перевірити надійно.
     * Кожне правило дає одне зауваження з номерами записів.
     *
     * @return list<array{type: string, message: string, location: string, context: string}>
     */
    public function entryErrors(): array
    {
        $rules = [
            'link' => ['Запис складається лише з посилання', 'Додайте автора, назву, «URL:» і «(дата звернення: ДД.ММ.РРРР)»'],
            'url' => ['Перед електронною адресою немає «URL:»', 'Приклад: … URL: https://example.com (дата звернення: 05.04.2026).'],
            'access' => ['Для електронного ресурсу не вказано дату звернення', 'Додайте «(дата звернення: ДД.ММ.РРРР)» після адреси'],
            'year' => ['Не вказано рік видання', 'Рік зазначають після видавництва: «Київ : Наукова думка, 2020. 240 с.»'],
            'pages' => ['Не вказано кількість сторінок (книга) або діапазон сторінок (стаття)', '«240 с.» для книги, «С. 12–18» для статті'],
            'period' => ['Опис не закінчується крапкою', 'Наприкінці бібліографічного опису ставлять крапку'],
        ];
        $found = array_fill_keys(array_keys($rules), []);

        foreach ($this->entries as $i => $entry) {
            $n = $i + 1;
            $text = trim(preg_replace('/^\d+[.)]\s*/u', '', trim($entry)));
            $hasUrl = (bool) preg_match('~(https?://|www\.)~iu', $text);

            if (preg_match('~^(https?://|www\.)\S+$~iu', $text)) {
                $found['link'][] = $n;

                continue; // для голого посилання інші правила зайві
            }
            if ($hasUrl && ! preg_match('/URL\s*:/u', $text)) {
                $found['url'][] = $n;
            }
            if ($hasUrl && ! preg_match('/дата\s+звернення/iu', $text)) {
                $found['access'][] = $n;
            }
            if (! preg_match('/(?<!\d)(1[89]|20)\d{2}(?!\d)/', $text)) {
                $found['year'][] = $n;
            }
            if (! $hasUrl && ! preg_match('/\d+\s*(с|p|pp|S)\.|(?<!\p{L})(С|P|S|pp)\.\s*\d/u', $text)) {
                $found['pages'][] = $n;
            }
            if (! preg_match('/\.\s*$/u', $text)) {
                $found['period'][] = $n;
            }
        }

        $errors = [];
        foreach (array_filter($found) as $rule => $numbers) {
            [$message, $hint] = $rules[$rule];
            $errors[] = [
                'type' => 'Список джерел',
                'message' => $message.': №'.self::numbers($numbers),
                'location' => $this->referencesTitle ?? 'Список джерел',
                'context' => $hint,
            ];
        }

        return $errors;
    }

    /** @return array{found: bool, total: int, cited: int} cited — скільки джерел списку мають посилання в тексті */
    public function stats(): array
    {
        $total = count($this->entries);

        return [
            'found' => $this->hasReferences,
            'total' => $total,
            'cited' => count(array_filter(array_keys($this->cited), fn ($n) => $n >= 1 && $n <= $total)),
        ];
    }

    /** @return list<array{type: string, message: string, location: string, context: string}> */
    public function errors(): array
    {
        if (! $this->hasReferences) {
            return [];
        }

        $location = $this->referencesTitle;
        $total = count($this->entries);
        if ($total === 0) {
            return [self::error('Список джерел порожній', $location, 'Додайте джерела після заголовка списку')];
        }
        if (! $this->cited) {
            return [self::error('У тексті немає посилань на джерела', 'Основний текст', 'Посилайтеся на джерела у квадратних дужках: [1] або [1, с. 25]'), ...$this->entryErrors()];
        }

        $errors = $this->entryErrors();
        $missing = array_keys(array_filter($this->cited, fn ($v, $n) => $n < 1 || $n > $total, ARRAY_FILTER_USE_BOTH));
        if ($missing) {
            sort($missing);
            $errors[] = self::error(
                'Посилання на джерела, яких немає у списку: '.self::numbers($missing, fn ($n) => "[$n]"),
                'Основний текст',
                "У списку лише $total ".self::plural($total),
            );
        }

        $uncited = array_values(array_diff(range(1, $total), array_keys($this->cited)));
        if ($uncited) {
            $errors[] = self::error(
                'Джерела без посилань у тексті: №'.self::numbers($uncited),
                $location,
                'На кожне джерело зі списку має бути посилання в тексті',
            );
        }

        return $errors;
    }

    private static function numbers(array $numbers, ?callable $format = null): string
    {
        $shown = array_map($format ?? fn ($n) => (string) $n, array_slice($numbers, 0, 15));

        return implode(', ', $shown).(count($numbers) > 15 ? ' та ще '.(count($numbers) - 15) : '');
    }

    private static function plural(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'джерело',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'джерела',
            default => 'джерел',
        };
    }

    private static function error(string $message, string $location, string $context): array
    {
        return ['type' => 'Посилання на джерела', 'message' => $message, 'location' => $location, 'context' => $context];
    }
}
