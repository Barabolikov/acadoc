<?php

namespace App\Services\NormControl;

/**
 * Підписи рисунків і таблиць (ДСТУ 3008:2015): «Рисунок 1.2 – Назва» під рисунком,
 * «Таблиця 1.2 – Назва» над таблицею; нумерація в межах розділу; посилання в тексті на кожен.
 */
class CaptionChecks
{
    /** Номер: 1, 1.2 або А.1 (у додатках) */
    public const NUMBER = '(?:[А-ЯҐЄІЇ]\.)?\d+(?:\.\d+)*';

    private const NAMES = ['figure' => ['рисунок', 'рисунки', 'рисунків'], 'table' => ['таблиця', 'таблиці', 'таблиць']];

    /** @var array<string, list<array{number: string, location: string}>> */
    private array $captions = ['figure' => [], 'table' => []];

    /** @var array<string, array<string, true>> */
    private array $mentions = ['figure' => [], 'table' => []];

    /** @var list<array{type: string, message: string, location: string, context: string}> */
    private array $formatErrors = [];

    /** Вид підпису за початком рядка: 'figure', 'table' або null */
    public static function kindOf(string $line): ?string
    {
        if (preg_match('/^(?:Рисунок|Рис\.?|Мал\.?|Малюнок)\s*'.self::NUMBER.'/u', $line)) {
            return 'figure';
        }
        if (preg_match('/^(?:Таблиця|Табл\.?)\s*'.self::NUMBER.'/u', $line) && ! preg_match('/^Продовження/u', $line)) {
            return 'table';
        }

        return null;
    }

    /**
     * Рядок, що починається як підпис. Повертає true, якщо це справді підпис
     * (а не речення «Таблиця 1.1 містить…»).
     *
     * @param  array{before: bool, after: bool}  $object  чи стоїть рисунок/таблиця одразу перед / після рядка
     */
    public function caption(string $kind, string $line, string $location, array $object): bool
    {
        preg_match('/^(\S+?)\.?\s*('.self::NUMBER.')(\.?)\s*(.*)$/u', $line, $m);
        [, $word, $number, $dotAfterNumber, $rest] = $m + ['', '', '', '', ''];
        $hasDash = (bool) preg_match('/^[–—-]/u', $rest);
        $adjacent = $object['before'] || $object['after'];

        // Речення, що починається словом «Таблиця 1.1 містить…», — не підпис:
        // назва в підписі починається з великої літери, а речення продовжується з малої
        if (! $hasDash && (preg_match('/^\p{Ll}/u', $rest) || (! $adjacent && (str_ends_with($line, '.') || mb_strlen($line) > 120)))) {
            return false;
        }

        $this->captions[$kind][] = ['number' => $number, 'location' => $location];

        $issues = [];
        $full = $kind === 'figure' ? 'Рисунок' : 'Таблиця';
        if ($word !== $full) {
            $issues[] = "пишіть «{$full}» повністю";
        }
        if (substr_count($number, '.') > 1) {
            $issues[] = 'номер складається з номера розділу й порядкового номера (напр. 1.2), а не '.$number;
        }
        if ($dotAfterNumber !== '') {
            $issues[] = 'після номера крапку не ставлять';
        }
        $title = trim(preg_replace('/^[–—-]\s*/u', '', $rest));
        if ($title === '') {
            $issues[] = 'немає назви';
        } elseif (! preg_match('/^[–—]/u', $rest)) {
            $issues[] = $hasDash ? 'між номером і назвою ставлять тире «–», а не дефіс' : 'між номером і назвою потрібне тире: «'.$full.' 1.2 – Назва»';
        } elseif (! preg_match('/\s[–—]\s/u', ' '.$rest) || ! preg_match('/\d\s+[–—]/u', $line)) {
            $issues[] = 'тире відокремлюють пробілами з обох боків';
        }
        if ($title !== '' && str_ends_with($title, '.')) {
            $issues[] = 'у кінці назви крапку не ставлять';
        }

        // Розташування: назва таблиці — над таблицею, підпис рисунка — під рисунком
        if ($kind === 'table' && $object['before'] && ! $object['after']) {
            $issues[] = 'назву таблиці розміщують над таблицею';
        }
        if ($kind === 'figure' && $object['after'] && ! $object['before']) {
            $issues[] = 'підпис рисунка розміщують під рисунком';
        }

        if ($issues) {
            $this->formatErrors[] = [
                'type' => 'Оформлення (Підписи)',
                'message' => self::ucfirst(implode('; ', $issues)),
                'location' => $location,
                'context' => mb_substr($line, 0, 90),
            ];
        }

        return true;
    }

    /** Посилання на рисунки й таблиці в тексті: «рис. 1.2», «(табл. 2.1)», «рисунки 1.2–1.4», «таблиці 2.1 і 2.2» */
    public function addText(string $text): void
    {
        $n = self::NUMBER;
        if (! preg_match_all("/(?<!\p{L})(рис\.|рисун\p{L}*|мал\.|малюн\p{L}*|табл\.|таблиц\p{L}*)\s*($n(?:\s*(?:,|;|і|та|й|–|—|-)\s*$n)*)/iu", $text, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as [, $word, $list]) {
            $kind = preg_match('/^(рис|мал)/iu', $word) ? 'figure' : 'table';
            preg_match_all("/($n)(?:\s*[–—-]\s*($n))?/u", $list, $parts, PREG_SET_ORDER);
            foreach ($parts as $part) {
                $from = rtrim($part[1], '.');
                $this->mentions[$kind][$from] = true;
                // Діапазон «1.2–1.4» у межах одного розділу
                if (! empty($part[2])) {
                    $to = rtrim($part[2], '.');
                    $prefixFrom = preg_replace('/\d+$/', '', $from);
                    if ($prefixFrom === preg_replace('/\d+$/', '', $to)) {
                        $a = (int) preg_replace('/^.*\./', '', $from);
                        $b = (int) preg_replace('/^.*\./', '', $to);
                        foreach (range($a, min($b, $a + 50)) as $k) {
                            $this->mentions[$kind][$prefixFrom.$k] = true;
                        }
                    }
                }
            }
        }
    }

    public function count(string $kind): int
    {
        return count($this->captions[$kind]);
    }

    /** @return list<array{type: string, message: string, location: string, context: string}> */
    public function errors(): array
    {
        $errors = array_slice($this->formatErrors, 0, 10);
        if (count($this->formatErrors) > 10) {
            $errors[] = ['type' => 'Оформлення (Підписи)', 'message' => 'Усього підписів з порушеннями: '.count($this->formatErrors), 'location' => 'Рисунки й таблиці', 'context' => 'Правильно: «Рисунок 1.2 – Назва» під рисунком, «Таблиця 1.2 – Назва» над таблицею'];
        }

        foreach (['figure', 'table'] as $kind) {
            [$one, $many, $gen] = self::NAMES[$kind];
            $numbers = array_column($this->captions[$kind], 'number');

            // Нумерація в межах розділу: повтори й пропуски
            $byPrefix = [];
            foreach ($numbers as $number) {
                $prefix = preg_replace('/\d+$/', '', $number);
                $byPrefix[$prefix][] = (int) preg_replace('/^.*\./', '', $number);
            }
            $duplicates = self::sorted(array_keys(array_filter(array_count_values($numbers), fn ($c) => $c > 1)));
            $gaps = [];
            foreach ($byPrefix as $prefix => $seq) {
                foreach (range(1, max($seq)) as $k) {
                    if (! in_array($k, $seq, true)) {
                        $gaps[] = $prefix.$k;
                    }
                }
            }
            if ($duplicates) {
                $errors[] = ['type' => 'Оформлення (Підписи)', 'message' => 'Номери '.$gen.' повторюються: '.implode(', ', $duplicates), 'location' => mb_convert_case($many, MB_CASE_TITLE), 'context' => 'Кожен '.($kind === 'figure' ? 'рисунок' : 'таблиця').' має власний номер у межах розділу'];
            }
            if ($gaps) {
                $errors[] = ['type' => 'Оформлення (Підписи)', 'message' => 'У нумерації '.$gen.' пропущено: '.self::list($gaps), 'location' => mb_convert_case($many, MB_CASE_TITLE), 'context' => 'Нумерація в межах розділу: 1.1, 1.2, 1.3…'];
            }

            // Посилання в тексті
            $unreferenced = self::sorted(array_unique(array_filter($numbers, fn ($n) => ! isset($this->mentions[$kind][$n]))));
            if ($unreferenced) {
                $hint = $kind === 'figure'
                    ? 'На кожен рисунок посилаються в тексті: «(рис. 1.2)», «на рисунку 1.2»'
                    : 'На кожну таблицю посилаються в тексті: «(табл. 2.1)», «у таблиці 2.1»';
                $errors[] = ['type' => 'Посилання на рисунки й таблиці', 'message' => 'Немає посилань у тексті на '.$many.': '.self::list($unreferenced), 'location' => 'Основний текст', 'context' => $hint];
            }
            $missing = self::sorted(array_diff(array_keys($this->mentions[$kind]), $numbers));
            if ($missing && $numbers) {
                $errors[] = ['type' => 'Посилання на рисунки й таблиці', 'message' => 'Посилання на '.$many.', яких немає в роботі: '.self::list($missing), 'location' => 'Основний текст', 'context' => 'Перевірте номери в посиланнях'];
            }
        }

        return $errors;
    }

    private static function list(array $items): string
    {
        return implode(', ', array_slice($items, 0, 15)).(count($items) > 15 ? ' та ще '.(count($items) - 15) : '');
    }

    /** Природний порядок номерів: 1.2, 1.10, 2.1 */
    private static function sorted(array $numbers): array
    {
        $numbers = array_values(array_map('strval', $numbers));
        usort($numbers, 'strnatcmp');

        return $numbers;
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
