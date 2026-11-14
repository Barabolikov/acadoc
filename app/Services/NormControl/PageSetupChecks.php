<?php

namespace App\Services\NormControl;

/**
 * Перевірки параметрів сторінки, які задаються в розділах документа (<w:sectPr>)
 * та колонтитулах: формат А4, поля, нумерація сторінок.
 */
class PageSetupChecks
{
    private const TWIPS_PER_MM = 56.7;

    /**
     * @param  string  $documentXml  word/document.xml
     * @param  array<string, string>  $headerFooterParts  r:id => XML колонтитула
     * @return list<array{type: string, message: string, location: string, context: string}>
     */
    public static function check(string $documentXml, array $headerFooterParts, WordStyles $styles): array
    {
        preg_match_all('/<w:sectPr\b.*?<\/w:sectPr>/s', $documentXml, $m);
        $sections = $m[0];

        return [
            ...self::checkPageSize($sections),
            ...self::checkMargins($sections),
            ...self::checkPageNumbers($sections, $headerFooterParts, $styles),
        ];
    }

    /** @param list<string> $sections */
    private static function checkPageSize(array $sections): array
    {
        $bad = [];
        foreach ($sections as $i => $sect) {
            if (preg_match('/<w:pgSz\b([^>]*)>/', $sect, $m)) {
                $dims = [self::mm(self::attr($m[1], 'w')), self::mm(self::attr($m[1], 'h'))];
                sort($dims);
                // Альбомна орієнтація (для широких таблиць і схем) теж є А4
                if (abs($dims[0] - 210) > 2 || abs($dims[1] - 297) > 2) {
                    $bad[] = $i + 1;
                }
            }
        }

        return $bad ? [self::error('Форматування (Сторінка)', 'Формат аркуша не А4 (210 × 297 мм)', self::where($bad, count($sections)), 'Макет → Розмір → A4')] : [];
    }

    /**
     * Ліве поле 30 мм (вимога методички), решта — мінімуми ДСТУ 3008:2015:
     * праве не менше 10 мм, верхнє й нижнє — не менше 20 мм.
     *
     * @param  list<string>  $sections
     */
    private static function checkMargins(array $sections): array
    {
        $byMessage = [];
        foreach ($sections as $i => $sect) {
            if (! preg_match('/<w:pgMar\b([^>]*)>/', $sect, $m)) {
                continue;
            }
            // В альбомних розділах поле підшивки зміщується — їх не перевіряємо
            if (preg_match('/<w:pgSz\b([^>]*)>/', $sect, $sz) && self::attr($sz[1], 'w') > self::attr($sz[1], 'h')) {
                continue;
            }

            $mm = fn (string $side) => self::mm(abs(self::attr($m[1], $side)));
            $left = $mm('left');
            if ($left < 29 || $left > 31) {
                $byMessage["Ліве поле: $left мм (треба 30 мм)"][] = $i + 1;
            }
            foreach (['right' => ['Праве', 10], 'top' => ['Верхнє', 20], 'bottom' => ['Нижнє', 20]] as $side => [$name, $min]) {
                if ($mm($side) < $min) {
                    $byMessage["$name поле: {$mm($side)} мм (треба не менше $min мм)"][] = $i + 1;
                }
            }
        }

        $errors = [];
        foreach ($byMessage as $message => $sectionNumbers) {
            $errors[] = self::error('Форматування (Поля)', $message, self::where($sectionNumbers, count($sections)), 'Макет → Поля → Настроювані поля');
        }

        return $errors;
    }

    /**
     * ДСТУ 3008:2015: сторінки нумерують арабськими цифрами в правому верхньому куті;
     * титульний аркуш входить до загальної нумерації, але номер на ньому не ставлять.
     *
     * @param  list<string>  $sections
     * @param  array<string, string>  $parts
     */
    private static function checkPageNumbers(array $sections, array $parts, WordStyles $styles): array
    {
        $refs = []; // [['kind' => header|footer, 'type' => default|first|even, 'xml' => ...], ...] для кожного розділу
        foreach ($sections as $i => $sect) {
            preg_match_all('/<w:(header|footer)Reference\b([^>]*)>/', $sect, $mm, PREG_SET_ORDER);
            foreach ($mm as [, $kind, $attrs]) {
                $id = preg_match('/r:id="([^"]+)"/', $attrs, $r) ? $r[1] : null;
                $type = preg_match('/w:type="([^"]+)"/', $attrs, $t) ? $t[1] : 'default';
                if ($id !== null && isset($parts[$id])) {
                    $refs[$i][] = ['kind' => $kind, 'type' => $type, 'xml' => $parts[$id]];
                }
            }
        }

        $withNumber = fn (array $list) => array_values(array_filter($list, fn ($p) => self::hasPageField($p['xml'])));
        $all = $withNumber(array_merge([], ...array_values($refs)));

        $fix = 'Вставлення → Номер сторінки → Угорі сторінки → Звичайний номер 3';
        if (! $all) {
            return [self::error('Оформлення (Нумерація)', 'Сторінки не пронумеровано', 'Колонтитули', $fix)];
        }

        $errors = [];
        $headers = array_filter($all, fn ($p) => $p['kind'] === 'header');
        if (! $headers) {
            $errors[] = self::error('Оформлення (Нумерація)', 'Номер сторінки внизу (треба в правому верхньому куті)', 'Нижній колонтитул', $fix);
        } else {
            foreach ($headers as $header) {
                $align = self::pageNumberAlignment($header['xml'], $styles);
                if ($align !== null && ! in_array($align, ['right', 'end'], true)) {
                    $names = ['left' => 'ліворуч', 'start' => 'ліворуч', 'center' => 'по центру', 'both' => 'ліворуч'];
                    $errors[] = self::error('Оформлення (Нумерація)', 'Номер сторінки '.($names[$align] ?? $align).' (треба в правому верхньому куті)', 'Верхній колонтитул', 'Вирівняйте номер по правому краю');
                    break;
                }
            }
        }

        // Титульний аркуш — перша сторінка першого розділу
        $first = $sections[0] ?? '';
        $firstPageType = preg_match('/<w:titlePg(?:\s*\/>|\s+w:val="(?!0|false|off)[^"]*"\s*\/>)/', $first) ? 'first' : 'default';
        $onTitle = array_filter($refs[0] ?? [], fn ($p) => $p['type'] === $firstPageType && self::hasPageField($p['xml']));
        if ($onTitle) {
            $errors[] = self::error('Оформлення (Нумерація)', 'Номер сторінки стоїть на титульному аркуші', 'Титульний аркуш', 'Колонтитул → «Особливий колонтитул для першої сторінки»');
        }

        return $errors;
    }

    private static function hasPageField(string $xml): bool
    {
        return (bool) preg_match('/w:instr="\s*PAGE\b|<w:instrText\b[^>]*>\s*PAGE\b/', $xml);
    }

    /** Вирівнювання абзацу з полем PAGE; null — якщо визначити не можна (номер у написі, рамці тощо). */
    private static function pageNumberAlignment(string $xml, WordStyles $styles): ?string
    {
        foreach (preg_split('/(?=<w:p[ >])/', $xml) as $p) {
            if (! self::hasPageField($p)) {
                continue;
            }
            if (str_contains($p, '<w:txbxContent') || preg_match('/<w:framePr\b[^>]*w:xAlign="(right|outside)"/', $p)
                || preg_match('/<w:ptab\b[^>]*w:alignment="right"/', $p)) {
                return str_contains($p, '<w:txbxContent') ? null : 'right';
            }

            return $styles->paragraphProps($p)['jc'];
        }

        return null;
    }

    private static function where(array $sectionNumbers, int $total): string
    {
        return $total <= 1 ? 'Налаштування сторінки' : 'Розділи документа: '.implode(', ', $sectionNumbers);
    }

    private static function attr(string $attrs, string $name): int
    {
        return preg_match('/w:'.$name.'="(-?\d+)"/', $attrs, $m) ? (int) $m[1] : 0;
    }

    private static function mm(int $twips): int
    {
        return (int) round($twips / self::TWIPS_PER_MM);
    }

    private static function error(string $type, string $message, string $location, string $context): array
    {
        return compact('type', 'message', 'location', 'context');
    }
}
