<?php

namespace App\Services\NormControl;

use RuntimeException;
use ZipArchive;

/**
 * Звіт нормоконтролю у форматі Word (.docx), зібраний вручну з OOXML через ZipArchive
 * (без PhpWord, якому потрібне розширення gd).
 *
 * Сам звіт оформлено так, як вимагає сервіс: Times New Roman, поля 30/15/20/20 мм.
 */
class ReportDocx
{
    private const INDIGO = '4F46E5';

    private const NAVY = '1E1B4B';

    private const GRAY = '6B7280';

    private const LIGHT = 'F3F4F6';

    private const RED = 'B91C1C';

    private const GREEN = '15803D';

    /** Ширина тексту: 210 − 30 − 15 мм у twips */
    private const WIDTH = 9356;

    /** Порядок і назви груп зауважень */

    public static function categoryOf(array $error): string
    {
        return match (true) {
            (bool) preg_match('/Поля|Сторінка|Нумерація/u', $error['type']) => 'Параметри сторінки',
            (bool) preg_match('/Гарнітура|Шрифт|Інтервал|Абзац/u', $error['type']),
            $error['type'] === 'Форматування (Вирівнювання)' && ! str_starts_with($error['message'], 'Заголовок') => 'Основний текст',
            $error['type'] === 'Стилістика' => 'Стиль викладу',
            default => 'Структура та заголовки',
        };
    }

    /** Вміст файлу .docx */
    public static function build(array $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nc-report');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не вдалося створити файл звіту.');
        }

        $footerText = 'Acadoc · '.$report['number'].' · '.$report['issuedAt'];
        foreach ([
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::rootRels(),
            'docProps/core.xml' => self::coreProps($report),
            'word/_rels/document.xml.rels' => self::documentRels(),
            'word/styles.xml' => self::styles(),
            'word/settings.xml' => self::settings(),
            'word/footer1.xml' => self::footer($footerText),
            'word/document.xml' => self::document($report),
        ] as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    // ---------------------------------------------------------------- вміст

    private static function document(array $report): string
    {
        $t = $report['titlePage'];
        $m = $report['metrics'];
        $s = $report['stats'];
        $passed = $m['errors'] === 0;

        $groups = array_fill_keys(self::CATEGORIES, []);
        foreach ($report['errors'] as $error) {
            $groups[self::categoryOf($error)][] = $error;
        }
        $groups = array_filter($groups);

        $body = '';

        // Шапка: бренд, назва, номер — ліворуч; статус — праворуч
        $left = self::p(self::r('Acadoc', ['bold' => true, 'color' => self::INDIGO, 'size' => 13]), ['after' => 4])
            .self::p(self::r('Звіт нормоконтролю роботи', ['bold' => true, 'color' => self::NAVY, 'size' => 20]), ['after' => 2])
            .self::p(self::r('Acadoc · Номер звіту ', ['color' => self::GRAY, 'size' => 9.5])
                .self::r($report['number'], ['bold' => true, 'size' => 9.5])
                .self::r(' · Видано '.$report['issuedAt'], ['color' => self::GRAY, 'size' => 9.5]));
        $status = self::p(self::r($passed ? 'Відповідає вимогам' : 'Потребує доопрацювання', ['bold' => true, 'color' => $passed ? '166534' : '991B1B', 'size' => 11]), ['align' => 'center', 'after' => 2])
            .self::p(self::r('за результатами автоматичної перевірки', ['color' => $passed ? '166534' : '991B1B', 'size' => 8]), ['align' => 'center']);
        $body .= self::table([[
            self::cell($left, 6100, ['valign' => 'center']),
            self::cell('', 300),
            self::cell($status, 2956, ['fill' => $passed ? 'DCFCE7' : 'FEE2E2', 'border' => $passed ? '86EFAC' : 'FCA5A5', 'valign' => 'center', 'margin' => 120]),
        ]], [6100, 300, 2956]);

        // 1. Робота
        $body .= self::heading('1. Робота');
        $student = $t['student'];
        if ($student && ($t['course'] || $t['group'])) {
            $student .= ' ('.implode(', ', array_filter([$t['course'] ? $t['course'].' курс' : null, $t['group'] ? 'група '.$t['group'] : null])).')';
        }
        $volume = implode(' · ', array_filter([
            $s['pages'] ? $s['pages'].' с.' : null,
            number_format($s['words'], 0, ',', ' ').' слів',
            $s['tables'].' табл.',
            $s['images'].' рис.',
        ]));
        $info = [
            ['Файл', self::r($report['fileName'], ['bold' => true])],
            ['Вид роботи', $t['workType'] ?? '–'],
            ['Тема', $t['topic'] ?? '–'],
            ['Дисципліна', $t['discipline'] ?? '–'],
            ['Студент', $student ?? '–'],
            ['Спеціальність', $t['specialty']],
            ['Керівник', $t['supervisor'] ?? '–'],
            ['Навчальний заклад', $t['institution']],
            ['Рік', trim(($t['city'] ? $t['city'].', ' : '').($t['year'] ?? '–'), ', ')],
            ['Обсяг', $volume],
            ['Перевірив', $report['checkedBy']],
            ['SHA-256 роботи', self::r($report['fileHash'], ['font' => 'Consolas', 'size' => 8])
                .self::r('контрольна сума завантаженого файлу', ['color' => self::GRAY, 'size' => 8, 'break' => true])],
        ];
        $rows = [];
        foreach ($info as [$label, $value]) {
            if ($value === null) {
                continue;
            }
            $rows[] = [
                self::cell(self::p(self::r($label, ['color' => self::GRAY])), 2900),
                self::cell(self::p(str_starts_with($value, '<w:r>') ? $value : self::r($value)), 6456),
            ];
        }
        $body .= self::table($rows, [2900, 6456], ['cellAfter' => 40]);

        // 2. Показники
        $body .= self::heading('2. Показники');
        $cits = $m['citations'];
        $metric = fn (string $big, string $color, string ...$small) => self::p(self::r($big, ['bold' => true, 'size' => 18, 'color' => $color]), ['after' => 0])
            .implode('', array_map(fn ($line) => self::p(self::r($line, ['color' => self::GRAY, 'size' => 8]), ['after' => 0]), $small));
        $cells = [
            $m['body']['percent'] === null
                ? $metric('–', self::NAVY, 'не знайдено розділ «ВСТУП»')
                : $metric($m['body']['percent'].'%', $m['body']['percent'] === 100 ? self::GREEN : self::NAVY, 'абзаців без порушень', $m['body']['ok'].' з '.$m['body']['checked']),
            $metric((string) $m['page'], $m['page'] ? self::RED : self::GREEN, 'зауважень: поля,', 'формат, нумерація'),
            $metric($m['structure']['found'].' з '.$m['structure']['total'], $m['structure']['found'] === $m['structure']['total'] ? self::GREEN : self::RED, 'обов\'язкових елементів'),
            (! $cits['found'] || $cits['total'] === 0)
                ? $metric('–', self::RED, 'список джерел не знайдено')
                : $metric($cits['cited'].' з '.$cits['total'], $cits['cited'] === $cits['total'] ? self::GREEN : self::RED, 'джерел мають посилання', 'в тексті'),
            $metric((string) $m['errors'], $passed ? self::GREEN : self::RED, 'у '.count($groups).' '.(count($groups) === 1 ? 'категорії' : 'категоріях')),
        ];
        $widths = [2000, 1850, 1800, 1950, 1756];
        $headers = ['Основний текст', 'Параметри сторінки', 'Структура роботи', 'Посилання на джерела', 'Усього зауважень'];
        $body .= self::table([
            array_map(fn ($h, $w) => self::cell(self::p(self::r($h, ['bold' => true, 'size' => 8, 'color' => '4B5563'])), $w, ['bottom' => 'D1D5DB']), $headers, $widths),
            array_map(fn ($c, $w) => self::cell($c, $w, ['bottom' => 'F3F4F6']), $cells, $widths),
        ], $widths, ['cellAfter' => 60]);

        $note = ['color' => self::GRAY, 'size' => 8];
        $body .= self::p(self::r('Показники є автоматичними допоміжними засобами для студента й нормоконтролера. Перевірка аналізує внутрішню розмітку файлу Word і може не врахувати оформлення, зроблене нестандартними засобами. Рішення про допуск роботи ухвалює нормоконтролер.', $note), ['before' => 6, 'after' => 6]);
        $body .= self::p(self::r('Застосовані критерії', $note + ['bold' => true]).self::r(' (ДСТУ 3008:2015, ГОСТ 2.105, методичні вимоги закладу):', $note), ['after' => 2]);
        foreach ([
            'Аркуш А4; ліве поле 30 мм, праве ≥ 10 мм, верхнє й нижнє ≥ 20 мм; нумерація в правому верхньому куті, без номера на титулці',
            'Основний текст: Times New Roman 14 пт, інтервал 1,5, абзацний відступ 1,25 см, вирівнювання по ширині (без таблиць, підписів і лістингів коду)',
            'Структура: титульний аркуш, завдання, зміст, вступ, висновки, список джерел; кожен структурний елемент і розділ — з нової сторінки',
        ] as $criterion) {
            $body .= self::p(self::r('•', $note).'<w:r><w:tab/></w:r>'.self::r($criterion, $note), ['after' => 1, 'indent' => [227, 227]]);
        }

        $callout = [];
        if ($m['mainFont']) {
            $callout[] = self::r('Шрифт основного тексту: ').self::r($m['mainFont'], ['bold' => true])
                .self::r(' ('.$m['mainFontShare'].'% тексту)'.($m['mainFont'] === 'Times New Roman' ? '.' : ' — треба Times New Roman.'));
        }
        $callout[] = $m['structure']['missing']
            ? self::r('Не знайдено: '.implode(', ', $m['structure']['missing']).'.')
            : self::r('Усі обов\'язкові структурні елементи знайдено.');
        $body .= self::callout(implode('', array_map(fn ($runs) => self::p($runs, ['after' => 0]), $callout)), self::INDIGO);

        // 3. Зауваження
        $body .= self::heading('3. Зауваження');
        if (! $groups) {
            $body .= self::callout(self::p(self::r('Зауважень за застосованими критеріями не виявлено.'), ['after' => 0]), '22C55E');
        }
        $n = 0;
        $widths = [600, 5656, 3100];
        foreach ($groups as $category => $errors) {
            $body .= self::p(self::r($category, ['bold' => true, 'color' => self::NAVY, 'size' => 11]).self::r(' · '.count($errors), ['color' => self::GRAY, 'size' => 10]), ['before' => 10, 'after' => 4, 'keepNext' => true]);
            $rows = [array_map(fn ($h, $w) => self::cell(self::p(self::r($h, ['bold' => true, 'size' => 8, 'color' => '4B5563'])), $w, ['fill' => self::LIGHT]), ['№', 'Зауваження', 'Місце в роботі'], $widths)];
            foreach ($errors as $error) {
                $text = self::p(self::r($error['message'], ['bold' => true, 'size' => 9.5]), ['after' => 0]);
                if ($error['context'] !== '') {
                    $text .= self::p(self::r($error['context'], ['color' => self::GRAY, 'size' => 8]), ['after' => 0]);
                }
                $rows[] = [
                    self::cell(self::p(self::r((string) ++$n, ['color' => '9CA3AF', 'size' => 9])), $widths[0], ['bottom' => 'E5E7EB']),
                    self::cell($text, $widths[1], ['bottom' => 'E5E7EB']),
                    self::cell(self::p(self::r($error['location'], ['color' => '4B5563', 'size' => 9])), $widths[2], ['bottom' => 'E5E7EB']),
                ];
            }
            $body .= self::table($rows, $widths, ['header' => true, 'cellAfter' => 40]);
        }

        // 4. Рішення нормоконтролера
        $body .= self::heading('4. Рішення нормоконтролера');
        // Рядки для коментаря — нижні межі окремих комірок (межі сусідніх абзаців Word зливає в одну)
        $line = fn (string $label = '') => [
            self::cell(self::p(self::r($label, ['color' => self::GRAY])), 2900),
            self::cell(self::p('', ['line' => 360]), 6456, ['bottom' => '9CA3AF']),
        ];
        $body .= self::table([
            [self::cell(self::p(self::r('Статус', ['color' => self::GRAY])), 2900), self::cell(self::p(self::r('☐ ', ['size' => 13]).self::r('Допущено до захисту').'<w:r><w:tab/></w:r>'.self::r('☐ ', ['size' => 13]).self::r('Повернуто на доопрацювання'), ['tabs' => [3000]]), 6456)],
            $line('Коментар'),
            $line(),
            $line(),
        ], [2900, 6456], ['cellAfter' => 60]);
        $body .= self::p(self::r('Цей звіт створено сервісом Acadoc. Він фіксує результати автоматичної перевірки файлу з контрольною сумою, зазначеною вище, на момент видачі звіту. Рішення щодо роботи ухвалює нормоконтролер.', $note), ['before' => 14, 'after' => 30, 'keepNext' => true]);
        $body .= self::p(self::r('Підпис нормоконтролера та дата', ['color' => '4B5563', 'size' => 9]), ['borderTop' => '374151', 'indent' => [0, 0, 3700]]);

        // Розділ: А4, поля 30/15/20/20 мм, нижній колонтитул
        $body .= '<w:sectPr><w:footerReference w:type="default" r:id="rIdFooter"/>'
            .'<w:pgSz w:w="11906" w:h="16838"/>'
            .'<w:pgMar w:top="1134" w:right="851" w:bottom="1134" w:left="1701" w:header="567" w:footer="567" w:gutter="0"/>'
            .'</w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body>'.$body.'</w:body></w:document>';
    }

    // ---------------------------------------------------------- будівельні блоки

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Фрагмент тексту. $o: bold, color, size (пт), font, break (розрив рядка перед текстом) */
    private static function r(string $text, array $o = []): string
    {
        $rPr = '';
        if (isset($o['font'])) {
            $rPr .= '<w:rFonts w:ascii="'.$o['font'].'" w:hAnsi="'.$o['font'].'" w:cs="'.$o['font'].'"/>';
        }
        if (! empty($o['bold'])) {
            $rPr .= '<w:b/><w:bCs/>';
        }
        if (isset($o['color'])) {
            $rPr .= '<w:color w:val="'.$o['color'].'"/>';
        }
        if (isset($o['size'])) {
            $half = (int) round($o['size'] * 2);
            $rPr .= '<w:sz w:val="'.$half.'"/><w:szCs w:val="'.$half.'"/>';
        }

        return '<w:r>'.($rPr ? "<w:rPr>$rPr</w:rPr>" : '').(! empty($o['break']) ? '<w:br/>' : '')
            .'<w:t xml:space="preserve">'.self::esc($text).'</w:t></w:r>';
    }

    /**
     * Абзац. $o: align, before/after (пт), line (240 = одинарний), keepNext,
     * borderTop/borderBottom (колір), indent [left, hanging, right] (twips), tabs [позиції], style
     */
    private static function p(string $runs, array $o = []): string
    {
        $pPr = isset($o['style']) ? '<w:pStyle w:val="'.$o['style'].'"/>' : '';
        if (! empty($o['keepNext'])) {
            $pPr .= '<w:keepNext/>';
        }
        $borders = '';
        foreach (['top' => 'borderTop', 'bottom' => 'borderBottom'] as $side => $key) {
            if (isset($o[$key])) {
                $borders .= '<w:'.$side.' w:val="single" w:sz="6" w:space="4" w:color="'.$o[$key].'"/>';
            }
        }
        if ($borders) {
            $pPr .= "<w:pBdr>$borders</w:pBdr>";
        }
        if (isset($o['tabs'])) {
            $pPr .= '<w:tabs>'.implode('', array_map(fn ($pos) => '<w:tab w:val="left" w:pos="'.$pos.'"/>', $o['tabs'])).'</w:tabs>';
        }
        if (isset($o['before']) || isset($o['after']) || isset($o['line'])) {
            $pPr .= '<w:spacing'
                .(isset($o['before']) ? ' w:before="'.(int) ($o['before'] * 20).'"' : '')
                .(isset($o['after']) ? ' w:after="'.(int) ($o['after'] * 20).'"' : '')
                .(isset($o['line']) ? ' w:line="'.$o['line'].'" w:lineRule="auto"' : '')
                .'/>';
        }
        if (isset($o['indent'])) {
            [$left, $hanging, $right] = $o['indent'] + [0, 0, 0];
            $pPr .= '<w:ind w:left="'.$left.'" w:right="'.$right.'"'.($hanging ? ' w:hanging="'.$hanging.'"' : '').'/>';
        }
        if (isset($o['align'])) {
            $pPr .= '<w:jc w:val="'.$o['align'].'"/>';
        }

        return '<w:p>'.($pPr ? "<w:pPr>$pPr</w:pPr>" : '').$runs.'</w:p>';
    }

    private static function heading(string $text): string
    {
        return self::p(self::r($text), ['style' => 'Heading1']);
    }

    /** Комірка. $o: fill, border (усі сторони), bottom (лише нижня), valign, margin (twips) */
    private static function cell(string $content, int $width, array $o = []): array
    {
        return ['content' => $content === '' ? self::p('', ['after' => 0]) : $content, 'width' => $width, 'o' => $o];
    }

    /** Таблиця без зовнішніх меж. $o: header (повторювати перший рядок), cellAfter (відступ під текстом комірки, twips) */
    private static function table(array $rows, array $widths, array $o = []): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum($widths).'" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
            .'<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>'
            .'<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="80" w:type="dxa"/><w:bottom w:w="'.($o['cellAfter'] ?? 60).'" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar>'
            .'</w:tblPr><w:tblGrid>'.implode('', array_map(fn ($w) => '<w:gridCol w:w="'.$w.'"/>', $widths)).'</w:tblGrid>';

        foreach ($rows as $i => $row) {
            $trPr = '<w:cantSplit/>'.($i === 0 && ! empty($o['header']) ? '<w:tblHeader/>' : '');
            $xml .= "<w:tr><w:trPr>$trPr</w:trPr>";
            foreach ($row as $cell) {
                $c = $cell['o'];
                $tcPr = '<w:tcW w:w="'.$cell['width'].'" w:type="dxa"/>';
                if (isset($c['border']) || isset($c['bottom'])) {
                    $tcPr .= '<w:tcBorders>';
                    foreach (['top', 'left', 'bottom', 'right'] as $side) {
                        $color = $c['border'] ?? ($side === 'bottom' ? $c['bottom'] : null);
                        $tcPr .= $color ? '<w:'.$side.' w:val="single" w:sz="6" w:color="'.$color.'"/>' : '<w:'.$side.' w:val="nil"/>';
                    }
                    $tcPr .= '</w:tcBorders>';
                }
                if (isset($c['fill'])) {
                    $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="'.$c['fill'].'"/>';
                }
                if (isset($c['margin'])) {
                    $mm = $c['margin'];
                    $tcPr .= "<w:tcMar><w:top w:w=\"$mm\" w:type=\"dxa\"/><w:left w:w=\"$mm\" w:type=\"dxa\"/><w:bottom w:w=\"$mm\" w:type=\"dxa\"/><w:right w:w=\"$mm\" w:type=\"dxa\"/></w:tcMar>";
                }
                $tcPr .= '<w:vAlign w:val="'.($c['valign'] ?? 'top').'"/>';
                $xml .= "<w:tc><w:tcPr>$tcPr</w:tcPr>{$cell['content']}</w:tc>";
            }
            $xml .= '</w:tr>';
        }

        // Абзац після таблиці обов'язковий, інакше дві таблиці поспіль зіллються
        return $xml.'</w:tbl>'.self::p('', ['after' => 0, 'line' => 120]);
    }

    /** Виділений блок: світлий фон і кольорова смуга ліворуч */
    private static function callout(string $content, string $barColor): string
    {
        return '<w:tbl><w:tblPr><w:tblW w:w="'.self::WIDTH.'" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
            .'<w:tblCellMar><w:top w:w="100" w:type="dxa"/><w:left w:w="200" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:right w:w="200" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            .'<w:tblGrid><w:gridCol w:w="'.self::WIDTH.'"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="'.self::WIDTH.'" w:type="dxa"/>'
            .'<w:tcBorders><w:top w:val="nil"/><w:left w:val="single" w:sz="24" w:color="'.$barColor.'"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>'
            .'<w:shd w:val="clear" w:color="auto" w:fill="'.self::LIGHT.'"/></w:tcPr>'.$content.'</w:tc></w:tr></w:tbl>'
            .self::p('', ['after' => 0, 'line' => 120]);
    }

    // ------------------------------------------------------------ службові частини

    private static function footer(string $text): string
    {
        // Формат результату поля Word бере з його службових фрагментів, тож задаємо rPr і їм
        $rPr = '<w:rPr><w:color w:val="'.self::GRAY.'"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>';
        $field = fn (string $code) => "<w:r>$rPr<w:fldChar w:fldCharType=\"begin\"/></w:r><w:r>$rPr<w:instrText xml:space=\"preserve\"> $code </w:instrText></w:r>"
            ."<w:r>$rPr<w:fldChar w:fldCharType=\"separate\"/></w:r><w:r>$rPr<w:t>1</w:t></w:r><w:r>$rPr<w:fldChar w:fldCharType=\"end\"/></w:r>";
        $small = ['color' => self::GRAY, 'size' => 8];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="E5E7EB"/></w:pBdr>'
            .'<w:tabs><w:tab w:val="right" w:pos="'.self::WIDTH.'"/></w:tabs><w:spacing w:after="0"/></w:pPr>'
            .self::r('Acadoc', $small + ['bold' => true]).self::r(substr($text, strlen('Acadoc')), $small)
            .'<w:r><w:tab/></w:r>'.self::r('Сторінка ', $small).$field('PAGE').self::r(' з ', $small).$field('NUMPAGES')
            .'</w:p></w:ftr>';
    }

    private static function styles(): string
    {
        $font = '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman" w:eastAsia="Times New Roman"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr>'.$font.'<w:color w:val="1F2937"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="uk-UA"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            .'<w:pPr><w:keepNext/><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="4" w:color="6366F1"/></w:pBdr><w:spacing w:before="320" w:after="160"/><w:outlineLvl w:val="0"/></w:pPr>'
            .'<w:rPr><w:b/><w:bCs/><w:color w:val="'.self::NAVY.'"/><w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:style>'
            .'<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/>'
            .'<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            .'</w:styles>';
    }

    private static function settings(): string
    {
        // Поля PAGE/NUMPAGES у колонтитулі Word перераховує сам під час розмітки сторінок
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:defaultTabStop w:val="708"/><w:characterSpacingControl w:val="doNotCompress"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
            .'</w:settings>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            .'<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';
    }

    private static function documentRels(): string
    {
        $base = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdStyles" Type="'.$base.'/styles" Target="styles.xml"/>'
            .'<Relationship Id="rIdSettings" Type="'.$base.'/settings" Target="settings.xml"/>'
            .'<Relationship Id="rIdFooter" Type="'.$base.'/footer" Target="footer1.xml"/>'
            .'</Relationships>';
    }

    private static function coreProps(array $report): string
    {
        $title = 'Звіт нормоконтролю '.$report['number'].($report['titlePage']['student'] ? ' — '.$report['titlePage']['student'] : '');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.self::esc($title).'</dc:title><dc:creator>Acadoc</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created>'
            .'</cp:coreProperties>';
    }
}
