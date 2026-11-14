<?php

namespace App\Services\NormControl;

use DOMDocument;
use DOMXPath;

/**
 * Визначає фактичні (успадковані) параметри абзацу Word:
 * пряме форматування → стиль символів/абзацу → ланцюжок basedOn → docDefaults.
 *
 * Без цього перевірка бачить лише ручне форматування, а розмір шрифту,
 * інтервал і вирівнювання, задані через стиль «Звичайний», пропускає.
 */
class WordStyles
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const NS_DRAWING = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const KEYS = ['jc', 'line', 'lineRule', 'sz', 'firstLine', 'font', 'pageBreakBefore', 'caps', 'smallCaps'];

    /** @var array<string, array<string, mixed>> styleId => basedOn + KEYS */
    private array $styles = [];

    /** @var array<string, mixed> */
    private array $defaults;

    private ?string $defaultParagraphStyle = null;

    /** Шрифти теми: ['minor' => 'Calibri', 'major' => 'Calibri Light'] */
    private array $themeFonts = [];

    private function __construct()
    {
        $this->defaults = array_fill_keys(self::KEYS, null);
    }

    public static function fromXml(?string $stylesXml, ?string $themeXml = null): self
    {
        $instance = new self;
        $instance->themeFonts = self::parseThemeFonts($themeXml);

        if (! $stylesXml) {
            return $instance;
        }

        $dom = new DOMDocument;
        if (! @$dom->loadXML($stylesXml, LIBXML_NONET)) {
            return $instance;
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        $attr = function (string $query, $context = null) use ($xp): ?string {
            $node = $xp->query($query, $context)->item(0);

            return $node ? $node->nodeValue : null;
        };
        $tag = function (string $query, $context = null) use ($xp): ?string {
            return $xp->query($query, $context)->item(0)?->C14N();
        };

        $instance->defaults = [
            'jc' => $attr('/w:styles/w:docDefaults/w:pPrDefault/w:pPr/w:jc/@w:val'),
            'line' => self::toInt($attr('/w:styles/w:docDefaults/w:pPrDefault/w:pPr/w:spacing/@w:line')),
            'lineRule' => $attr('/w:styles/w:docDefaults/w:pPrDefault/w:pPr/w:spacing/@w:lineRule'),
            'sz' => self::toInt($attr('/w:styles/w:docDefaults/w:rPrDefault/w:rPr/w:sz/@w:val')),
            'firstLine' => self::indent($tag('/w:styles/w:docDefaults/w:pPrDefault/w:pPr/w:ind')),
            'font' => $instance->font($tag('/w:styles/w:docDefaults/w:rPrDefault/w:rPr/w:rFonts')),
            'pageBreakBefore' => self::toggle($tag('/w:styles/w:docDefaults/w:pPrDefault/w:pPr/w:pageBreakBefore')),
            'caps' => self::toggle($tag('/w:styles/w:docDefaults/w:rPrDefault/w:rPr/w:caps')),
            'smallCaps' => self::toggle($tag('/w:styles/w:docDefaults/w:rPrDefault/w:rPr/w:smallCaps')),
        ];

        foreach ($xp->query('/w:styles/w:style') as $style) {
            $id = $style->getAttributeNS(self::NS, 'styleId');
            if ($id === '') {
                continue;
            }

            $instance->styles[$id] = [
                'basedOn' => $attr('w:basedOn/@w:val', $style),
                'jc' => $attr('w:pPr/w:jc/@w:val', $style),
                'line' => self::toInt($attr('w:pPr/w:spacing/@w:line', $style)),
                'lineRule' => $attr('w:pPr/w:spacing/@w:lineRule', $style),
                'sz' => self::toInt($attr('w:rPr/w:sz/@w:val', $style)),
                'firstLine' => self::indent($tag('w:pPr/w:ind', $style)),
                'font' => $instance->font($tag('w:rPr/w:rFonts', $style)),
                'pageBreakBefore' => self::toggle($tag('w:pPr/w:pageBreakBefore', $style)),
                'caps' => self::toggle($tag('w:rPr/w:caps', $style)),
                'smallCaps' => self::toggle($tag('w:rPr/w:smallCaps', $style)),
            ];

            if ($style->getAttributeNS(self::NS, 'type') === 'paragraph'
                && in_array($style->getAttributeNS(self::NS, 'default'), ['1', 'true'], true)) {
                $instance->defaultParagraphStyle = $id;
            }
        }

        return $instance;
    }

    /**
     * Фактичні параметри абзацу з XML-фрагмента <w:p>…</w:p>.
     *
     * sz і font — розмір (у пів-пунктах) і шрифт, що покривають найбільше символів абзацу.
     *
     * @return array{jc: string, line: int, lineRule: string, sz: ?int, font: ?string, firstLine: int, pageBreakBefore: bool}
     */
    public function paragraphProps(string $paragraphXml): array
    {
        $pPr = self::paragraphPropertiesXml($paragraphXml);
        $styleId = preg_match('/<w:pStyle w:val="([^"]+)"/', $pPr, $m) ? $m[1] : $this->defaultParagraphStyle;

        // Вирівнювання
        $jc = preg_match('/<w:jc w:val="([^"]+)"/', $pPr, $m) ? $m[1] : null;
        $jc ??= $this->inherited($styleId, 'jc') ?? 'left';

        // Міжрядковий інтервал: беремо line і lineRule з того самого рівня, де задано line.
        $line = null;
        $lineRule = null;
        if (preg_match('/<w:spacing\b([^>]*)\/?>/', $pPr, $m) && preg_match('/w:line="(\d+)"/', $m[1], $lm)) {
            $line = (int) $lm[1];
            $lineRule = preg_match('/w:lineRule="([^"]+)"/', $m[1], $rm) ? $rm[1] : null;
        }
        if ($line === null) {
            foreach ([...$this->chain($styleId), $this->defaults] as $level) {
                if ($level['line'] !== null) {
                    [$line, $lineRule] = [$level['line'], $level['lineRule']];
                    break;
                }
            }
        }

        // Абзацний відступ (twips; від'ємний — виступ)
        $firstLine = preg_match('/<w:ind\b[^>]*>/', $pPr, $m) ? self::indent($m[0]) : null;
        $firstLine ??= $this->inherited($styleId, 'firstLine') ?? 0;

        // «З нової сторінки» (Абзац → Положення на сторінці)
        $pageBreakBefore = preg_match('/<w:pageBreakBefore\b[^>]*>/', $pPr, $m) ? self::toggle($m[0]) : null;
        $pageBreakBefore ??= $this->inherited($styleId, 'pageBreakBefore') ?? false;

        [$sz, $font] = $this->dominantRunProps($paragraphXml, $styleId);

        return [
            'firstLine' => $firstLine,
            'jc' => $jc,
            'line' => $line ?? 240,
            'lineRule' => $lineRule ?? 'auto',
            'sz' => $sz,
            'font' => $font,
            'pageBreakBefore' => $pageBreakBefore,
        ];
    }

    /**
     * Ефект регістру першого фрагмента тексту: 'caps' («Усі великі»), 'smallCaps' («Капітель») або null.
     * Текст «Вступ» з ефектом «Усі великі» у Word відображається як ВСТУП.
     */
    public function textCase(string $paragraphXml): ?string
    {
        $pPr = self::paragraphPropertiesXml($paragraphXml);
        $styleId = preg_match('/<w:pStyle w:val="([^"]+)"/', $pPr, $m) ? $m[1] : $this->defaultParagraphStyle;

        $body = preg_replace('/^(<w:p\b[^>]*>)\s*<w:pPr>.*?<\/w:pPr>/s', '$1', $paragraphXml);
        $rPr = '';
        if (preg_match_all('/<w:r\b[^>]*>(.*?)<\/w:r>/s', $body, $runs)) {
            foreach ($runs[1] as $run) {
                if (preg_match('/<w:t\b[^>]*>\s*\S/', $run)) {
                    $rPr = preg_match('/<w:rPr>(.*?)<\/w:rPr>/s', $run, $m) ? $m[1] : '';
                    break;
                }
            }
        }
        $charStyle = preg_match('/<w:rStyle w:val="([^"]+)"/', $rPr, $m) ? $m[1] : null;

        foreach (['caps', 'smallCaps'] as $effect) {
            $value = preg_match('/<w:'.$effect.'\b[^>]*>/', $rPr, $m) ? self::toggle($m[0]) : null;
            if ($value ?? $this->fromChain($charStyle, $effect) ?? $this->inherited($styleId, $effect)) {
                return $effect;
            }
        }

        return null;
    }

    /** Вміст <w:pPr> абзацу без властивостей знака абзацу (<w:rPr>), які на текст не впливають. */
    public static function paragraphPropertiesXml(string $paragraphXml): string
    {
        if (! preg_match('/^<w:p\b[^>]*>\s*<w:pPr>(.*?)<\/w:pPr>/s', $paragraphXml, $m)) {
            return '';
        }

        return preg_replace('/<w:rPr>.*?<\/w:rPr>/s', '', $m[1]);
    }

    /** @return array{0: ?int, 1: ?string} */
    private function dominantRunProps(string $paragraphXml, ?string $paragraphStyle): array
    {
        $body = preg_replace('/^(<w:p\b[^>]*>)\s*<w:pPr>.*?<\/w:pPr>/s', '$1', $paragraphXml);
        if (! preg_match_all('/<w:r\b[^>]*>(.*?)<\/w:r>/s', $body, $runs)) {
            return [null, null];
        }

        $sizes = [];
        $fonts = [];
        foreach ($runs[1] as $run) {
            if (! preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $run, $t)) {
                continue;
            }
            $length = mb_strlen(trim(html_entity_decode(implode('', $t[1]))));
            if ($length === 0) {
                continue;
            }

            $rPr = preg_match('/<w:rPr>(.*?)<\/w:rPr>/s', $run, $m) ? $m[1] : '';
            $charStyle = preg_match('/<w:rStyle w:val="([^"]+)"/', $rPr, $m) ? $m[1] : null;

            $sz = preg_match('/<w:sz w:val="(\d+)"/', $rPr, $m) ? (int) $m[1] : null;
            $sz ??= $this->fromChain($charStyle, 'sz') ?? $this->inherited($paragraphStyle, 'sz') ?? 20;

            $font = $this->font(preg_match('/<w:rFonts\b[^>]*>/', $rPr, $m) ? $m[0] : null);
            $font ??= $this->fromChain($charStyle, 'font') ?? $this->inherited($paragraphStyle, 'font') ?? 'Times New Roman';

            $sizes[$sz] = ($sizes[$sz] ?? 0) + $length;
            $fonts[$font] = ($fonts[$font] ?? 0) + $length;
        }

        if (! $sizes) {
            return [null, null];
        }
        arsort($sizes);
        arsort($fonts);

        return [(int) array_key_first($sizes), (string) array_key_first($fonts)];
    }

    /**
     * Назва шрифту з тега <w:rFonts>. Кирилиця належить до діапазону hAnsi,
     * тому він має пріоритет над ascii; атрибут теми переважає явну назву.
     */
    private function font(?string $rFontsTag): ?string
    {
        if ($rFontsTag === null) {
            return null;
        }

        foreach (['hAnsi', 'ascii'] as $range) {
            if (preg_match('/w:'.$range.'Theme="(minor|major)[^"]*"/', $rFontsTag, $m) && isset($this->themeFonts[$m[1]])) {
                return $this->themeFonts[$m[1]];
            }
            if (preg_match('/w:'.$range.'="([^"]+)"/', $rFontsTag, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private static function parseThemeFonts(?string $themeXml): array
    {
        if (! $themeXml) {
            return [];
        }

        $dom = new DOMDocument;
        if (! @$dom->loadXML($themeXml, LIBXML_NONET)) {
            return [];
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('a', self::NS_DRAWING);

        $fonts = [];
        foreach (['minor' => 'a:minorFont', 'major' => 'a:majorFont'] as $key => $node) {
            $typeface = $xp->query("//a:fontScheme/$node/a:latin/@typeface")->item(0)?->nodeValue;
            if ($typeface) {
                $fonts[$key] = $typeface;
            }
        }

        return $fonts;
    }

    /** Значення зі стилю абзацу (з ланцюжком basedOn), а якщо його немає — з docDefaults. */
    private function inherited(?string $styleId, string $key): mixed
    {
        return $this->fromChain($styleId, $key) ?? $this->defaults[$key];
    }

    private function fromChain(?string $styleId, string $key): mixed
    {
        foreach ($this->chain($styleId) as $style) {
            if ($style[$key] !== null) {
                return $style[$key];
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function chain(?string $styleId): array
    {
        $chain = [];
        while ($styleId !== null && isset($this->styles[$styleId]) && ! isset($chain[$styleId])) {
            $chain[$styleId] = $this->styles[$styleId];
            $styleId = $this->styles[$styleId]['basedOn'];
        }

        return array_values($chain);
    }

    /** firstLine / hanging з тега <w:ind>; null, якщо тег їх не задає */
    private static function indent(?string $indTag): ?int
    {
        if ($indTag === null) {
            return null;
        }
        if (preg_match('/w:hanging="(\d+)"/', $indTag, $m)) {
            return -(int) $m[1];
        }

        return preg_match('/w:firstLine="(\d+)"/', $indTag, $m) ? (int) $m[1] : null;
    }

    /** Логічна властивість OOXML (<w:x/> = true, <w:x w:val="0|false|off"/> = false) */
    private static function toggle(?string $tag): ?bool
    {
        if ($tag === null) {
            return null;
        }

        return ! preg_match('/w:val="(0|false|off)"/', $tag);
    }

    private static function toInt(?string $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
