<?php

namespace App\Services\NormControl;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Автоматичне виправлення помилок оформлення (функція для користувачів «Преміум»).
 *
 * Виправляє лише те, що можна змінити без ризику зіпсувати зміст:
 * формат аркуша й поля, шрифт, кегль, інтервал, вирівнювання й абзацний відступ основного тексту,
 * оформлення заголовків. Які абзаци виправляти, вирішує аналізатор (план $plan):
 * індекс абзацу збігається з індексом шматка в NormControlController::analyze()
 * (документ без текстових полів, поділений за <w:p ...>).
 */
class DocxFixer
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const INDEX_ATTR = 'acadocIx';

    private const TWIPS_PER_MM = 56.7;

    /** Порядок дочірніх елементів <w:pPr> за схемою OOXML (Word не відкриває файл з іншим порядком) */
    private const PPR_ORDER = ['pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl', 'numPr',
        'suppressLineNumbers', 'pBdr', 'shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct',
        'topLinePunct', 'autoSpaceDE', 'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind',
        'contextualSpacing', 'mirrorIndents', 'suppressOverlap', 'jc', 'textDirection', 'textAlignment',
        'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle', 'rPr', 'sectPr', 'pPrChange'];

    /** Порядок дочірніх елементів <w:rPr> */
    private const RPR_ORDER = ['rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike',
        'outline', 'shadow', 'emboss', 'imprint', 'noProof', 'snapToGrid', 'vanish', 'webHidden', 'color', 'spacing',
        'w', 'kern', 'position', 'sz', 'szCs', 'highlight', 'u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl',
        'cs', 'em', 'lang', 'eastAsianLayout', 'specVanish', 'oMath', 'rPrChange'];

    /** Назви виправлень для звіту */
    public const LABELS = [
        'pageSize' => 'Формат аркуша A4',
        'margins' => 'Поля сторінки (ліве 30 мм, праве ≥ 10 мм, верхнє й нижнє ≥ 20 мм)',
        'font' => 'Шрифт основного тексту Times New Roman',
        'size' => 'Кегль основного тексту 14 пт',
        'spacing' => 'Міжрядковий інтервал 1,5',
        'align' => 'Вирівнювання основного тексту по ширині',
        'indent' => 'Абзацний відступ 1,25 см',
        'pageBreak' => 'Заголовки з нової сторінки',
        'caps' => 'Заголовки великими літерами',
        'center' => 'Заголовки по центру',
        'dot' => 'Крапки в кінці заголовків прибрано',
    ];

    /** Типи помилок звіту, які виправляє цей клас (решту користувач виправляє вручну) */
    public const FIXABLE_TYPES = ['Форматування (Сторінка)', 'Форматування (Поля)', 'Форматування (Гарнітура)',
        'Форматування (Шрифт)', 'Форматування (Інтервал)', 'Форматування (Вирівнювання)', 'Форматування (Абзац)',
        'Оформлення (Нова сторінка)', 'Оформлення (Заголовки)'];

    /** @var array<string, int> виконані виправлення: ключ LABELS => кількість */
    private array $done = [];

    /**
     * @param  array<int, array{text: string, ops: list<string>}>  $plan  індекс абзацу => що виправити
     * @return array{content: string, fixes: array<string, int>}|null  null — документ не вдалося обробити
     */
    public static function fix(string $sourcePath, array $plan): ?array
    {
        $fixer = new self;
        $tmp = tempnam(sys_get_temp_dir(), 'acadoc-fix');
        try {
            if (! copy($sourcePath, $tmp)) {
                return null;
            }
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $xml = $zip->getFromName('word/document.xml');
            $fixed = $xml === false ? null : $fixer->fixDocument($xml, $plan);
            if ($fixed === null) {
                $zip->close();

                return null;
            }
            $zip->addFromString('word/document.xml', $fixed);
            $zip->close();

            return ['content' => file_get_contents($tmp), 'fixes' => $fixer->done];
        } finally {
            @unlink($tmp);
        }
    }

    private function fixDocument(string $xml, array $plan): ?string
    {
        // Нумеруємо абзаци так само, як аналізатор: без абзаців усередині текстових полів
        $boxes = [];
        $marked = preg_replace_callback('/<w:txbxContent>.*?<\/w:txbxContent>/s', function ($m) use (&$boxes) {
            $boxes[] = $m[0];

            return "\x01".(count($boxes) - 1)."\x01";
        }, $xml);
        $n = 0;
        $marked = preg_replace_callback('/<w:p(?=[ >])/', function () use (&$n) {
            return '<w:p '.self::INDEX_ATTR.'="'.(++$n).'"';
        }, $marked);
        $marked = preg_replace_callback("/\x01(\d+)\x01/", fn ($m) => $boxes[(int) $m[1]], $marked);

        $dom = new DOMDocument;
        if (! @$dom->loadXML($marked, LIBXML_PARSEHUGE | LIBXML_NONET)) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W);

        $this->fixSections($xpath);

        foreach ($xpath->query('//w:p[@'.self::INDEX_ATTR.']') as $p) {
            /** @var DOMElement $p */
            $index = (int) $p->getAttribute(self::INDEX_ATTR);
            $p->removeAttribute(self::INDEX_ATTR);
            if (! isset($plan[$index]) || ! $this->sameText($xpath, $p, $plan[$index]['text'])) {
                continue;
            }
            foreach ($plan[$index]['ops'] as $op) {
                $this->apply($xpath, $p, $op);
            }
        }

        return $dom->saveXML();
    }

    /** Захист від зсуву нумерації: виправляємо лише той абзац, текст якого бачив аналізатор */
    private function sameText(DOMXPath $xpath, DOMElement $p, string $expected): bool
    {
        $text = '';
        foreach ($xpath->query('.//w:t[not(ancestor::w:txbxContent)]', $p) as $t) {
            $text .= $t->textContent;
        }
        $norm = fn (string $s) => preg_replace('/\s+/u', ' ', trim($s));

        return $norm($text) === $norm($expected);
    }

    private function apply(DOMXPath $xpath, DOMElement $p, string $op): void
    {
        $pPr = $this->child($p, 'pPr', ['pPr']);
        $runs = iterator_to_array($xpath->query('.//w:r[not(ancestor::w:txbxContent)]', $p));
        $applied = true;

        switch ($op) {
            case 'font':
                foreach ([...$runs, $pPr] as $node) {
                    $rPr = $node === $pPr ? $this->child($pPr, 'rPr', self::PPR_ORDER) : $this->child($node, 'rPr', ['rPr']);
                    $fonts = $this->child($rPr, 'rFonts', self::RPR_ORDER);
                    // Вбудовані фрагменти коду моноширинним шрифтом не чіпаємо
                    if (preg_match('/mono|code|courier|consolas|menlo|monaco|lucida console/i', $fonts->getAttributeNS(self::W, 'ascii'))) {
                        continue;
                    }
                    foreach (['ascii', 'hAnsi', 'cs', 'eastAsia'] as $attr) {
                        $fonts->setAttributeNS(self::W, 'w:'.$attr, 'Times New Roman');
                    }
                    foreach (['asciiTheme', 'hAnsiTheme', 'cstheme', 'eastAsiaTheme'] as $attr) {
                        $fonts->removeAttributeNS(self::W, $attr);
                    }
                }
                break;
            case 'size':
                foreach ([...$runs, $pPr] as $node) {
                    $rPr = $node === $pPr ? $this->child($pPr, 'rPr', self::PPR_ORDER) : $this->child($node, 'rPr', ['rPr']);
                    $this->child($rPr, 'sz', self::RPR_ORDER)->setAttributeNS(self::W, 'w:val', '28');
                    $this->child($rPr, 'szCs', self::RPR_ORDER)->setAttributeNS(self::W, 'w:val', '28');
                }
                break;
            case 'spacing':
                $spacing = $this->child($pPr, 'spacing', self::PPR_ORDER);
                $spacing->setAttributeNS(self::W, 'w:line', '360');
                $spacing->setAttributeNS(self::W, 'w:lineRule', 'auto');
                break;
            case 'align':
            case 'center':
                $this->child($pPr, 'jc', self::PPR_ORDER)->setAttributeNS(self::W, 'w:val', $op === 'align' ? 'both' : 'center');
                break;
            case 'indent':
                $ind = $this->child($pPr, 'ind', self::PPR_ORDER);
                $ind->setAttributeNS(self::W, 'w:firstLine', '709');
                foreach (['firstLineChars', 'hanging', 'hangingChars'] as $attr) {
                    $ind->removeAttributeNS(self::W, $attr);
                }
                break;
            case 'pageBreak':
                $this->child($pPr, 'pageBreakBefore', self::PPR_ORDER)->removeAttributeNS(self::W, 'val');
                break;
            case 'caps':
                foreach ($runs as $run) {
                    $rPr = $this->child($run, 'rPr', ['rPr']);
                    $this->child($rPr, 'caps', self::RPR_ORDER)->removeAttributeNS(self::W, 'val');
                    $this->remove($rPr, 'smallCaps');
                }
                break;
            case 'dot':
                $applied = false;
                $texts = iterator_to_array($xpath->query('.//w:t[not(ancestor::w:txbxContent)]', $p));
                foreach (array_reverse($texts) as $t) {
                    $value = rtrim($t->textContent);
                    if ($value === '') {
                        continue;
                    }
                    if (str_ends_with($value, '.') && ! str_ends_with($value, '..')) {
                        $t->textContent = substr($value, 0, -1);
                        $applied = true;
                    }
                    break;
                }
                break;
            default:
                $applied = false;
        }

        if ($applied) {
            $this->done[$op] = ($this->done[$op] ?? 0) + 1;
        }
    }

    /** Формат A4 (зі збереженням орієнтації) і поля в кожному розділі */
    private function fixSections(DOMXPath $xpath): void
    {
        $mm = fn (int $twips) => (int) round($twips / self::TWIPS_PER_MM);

        foreach ($xpath->query('//w:sectPr[not(ancestor::w:sectPrChange)]') as $sect) {
            $size = $xpath->query('w:pgSz', $sect)->item(0);
            $landscape = false;
            if ($size instanceof DOMElement) {
                $w = (int) $size->getAttributeNS(self::W, 'w');
                $h = (int) $size->getAttributeNS(self::W, 'h');
                $landscape = $w > $h;
                $dims = [$mm($w), $mm($h)];
                sort($dims);
                if (abs($dims[0] - 210) > 2 || abs($dims[1] - 297) > 2) {
                    $size->setAttributeNS(self::W, 'w:w', $landscape ? '16838' : '11906');
                    $size->setAttributeNS(self::W, 'w:h', $landscape ? '11906' : '16838');
                    $this->done['pageSize'] = ($this->done['pageSize'] ?? 0) + 1;
                }
            }

            $margins = $xpath->query('w:pgMar', $sect)->item(0);
            if ($landscape || ! $margins instanceof DOMElement) {
                continue; // в альбомних розділах поле підшивки зміщується — як і аналізатор, не чіпаємо
            }
            $changed = false;
            $get = fn (string $side) => $mm(abs((int) $margins->getAttributeNS(self::W, $side)));
            if ($get('left') < 29 || $get('left') > 31) {
                $margins->setAttributeNS(self::W, 'w:left', '1701');
                $changed = true;
            }
            foreach (['right' => [10, '567'], 'top' => [20, '1134'], 'bottom' => [20, '1134']] as $side => [$min, $twips]) {
                if ($get($side) < $min) {
                    $margins->setAttributeNS(self::W, 'w:'.$side, $twips);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->done['margins'] = ($this->done['margins'] ?? 0) + 1;
            }
        }
    }

    /** Дочірній елемент w:$name (створюється на своєму місці за схемою, якщо його немає) */
    private function child(DOMElement $parent, string $name, array $order): DOMElement
    {
        $position = array_search($name, $order, true);
        $before = null;
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::W) {
                continue;
            }
            if ($node->localName === $name) {
                return $node;
            }
            $nodePosition = array_search($node->localName, $order, true);
            if ($before === null && ($nodePosition === false || $nodePosition > $position)) {
                $before = $node;
            }
        }

        $element = $parent->ownerDocument->createElementNS(self::W, 'w:'.$name);

        return $before ? $parent->insertBefore($element, $before) : $parent->appendChild($element);
    }

    private function remove(DOMElement $parent, string $name): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::W && $node->localName === $name) {
                $parent->removeChild($node);
            }
        }
    }
}
