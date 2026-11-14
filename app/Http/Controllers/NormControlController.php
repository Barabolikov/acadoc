<?php

namespace App\Http\Controllers;

use App\Services\NormControl\CitationChecks;
use App\Services\NormControl\PageSetupChecks;
use App\Services\NormControl\TitlePageInfo;
use App\Services\NormControl\WordStyles;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use ZipArchive;

class NormControlController extends Controller
{
    /** Допустимі назви розділу висновків */
    private const CONCLUSION_TITLES = ['ВИСНОВКИ', 'ВИСНОВОК'];

    /** Допустимі назви списку джерел (останній варіант — термін самого ДСТУ 3008:2015) */
    private const REFERENCES_TITLES = [
        'СПИСОК ВИКОРИСТАНИХ ДЖЕРЕЛ',
        'СПИСОК ЛІТЕРАТУРИ',
        'СПИСОК ВИКОРИСТАНОЇ ЛІТЕРАТУРИ',
        'СПИСОК ЛІТЕРАТУРНИХ ДЖЕРЕЛ',
        'ПЕРЕЛІК ДЖЕРЕЛ ПОСИЛАННЯ',
    ];

    /** Скільки прикладів однієї помилки форматування показувати у звіті */
    private const MAX_EXAMPLES_PER_RULE = 5;

    /**
     * Чи є рядок заголовком із переліку. Ігнорує номер сторінки й заповнювач
     * з крапок у рядках змісту («ВИСНОВКИ.....38»), щоб «…зробити висновок…»
     * у звичайному тексті не вважався розділом.
     */
    private static function matchTitle(string $upperLine, array $titles): ?string
    {
        $normalized = trim(preg_replace('/[\s.\x{2026}]*\d*$/u', '', $upperLine));

        return in_array($normalized, $titles, true) ? $normalized : null;
    }

    /** Моноширинні шрифти лістингів програм — такі абзаци не перевіряємо як основний текст */
    private static function isCodeFont(?string $font): bool
    {
        return $font !== null && (bool) preg_match('/mono|code|courier|consolas|menlo|monaco|lucida console/i', $font);
    }

    public function index()
    {
        return view('norm-control.index');
    }

    public function process(Request $request)
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:docx', 'max:10240'],
        ], [
            'document.required' => 'Будь ласка, оберіть файл.',
            'document.mimes' => 'Файл має бути у форматі .docx!',
            'document.max' => 'Розмір файлу не повинен перевищувати 10 МБ.'
        ]);

        $file = $request->file('document');
        $fileName = $file->getClientOriginalName();
        $tempPath = $file->getRealPath();

        $zip = new ZipArchive;
        $res = $zip->open($tempPath);

        if ($res === true) {
            $xmlContent = $zip->getFromName('word/document.xml');

            // Зв'язки документа: колонтитули (r:id => XML) і тема (шрифти «основний текст»/«заголовки»)
            $headerFooterParts = [];
            $themeXml = null;
            preg_match_all('/<Relationship\b[^>]*>/', $zip->getFromName('word/_rels/document.xml.rels') ?: '', $relTags);
            foreach ($relTags[0] as $tag) {
                if (!preg_match('/Type="[^"]*\/(header|footer|theme)"/', $tag, $relType)
                    || !preg_match('/\bId="([^"]+)"/', $tag, $relId)
                    || !preg_match('/Target="([^"]+)"/', $tag, $relTarget)) {
                    continue;
                }
                $partPath = str_starts_with($relTarget[1], '/') ? ltrim($relTarget[1], '/') : 'word/' . $relTarget[1];
                $partXml = $zip->getFromName($partPath);
                if ($partXml === false) continue;

                if ($relType[1] === 'theme') {
                    $themeXml = $partXml;
                } else {
                    $headerFooterParts[$relId[1]] = $partXml;
                }
            }

            $styles = WordStyles::fromXml($zip->getFromName('word/styles.xml') ?: null, $themeXml);
            // Кількість сторінок, яку Word записав під час останнього збереження
            $pagesCount = preg_match('/<Pages>(\d+)<\/Pages>/', $zip->getFromName('docProps/app.xml') ?: '', $pagesMatch) ? (int) $pagesMatch[1] : null;
            $zip->close();

            if ($xmlContent === false) {
                return back()->withErrors(['document' => 'Файл не схожий на документ Word: відсутній word/document.xml.']);
            }

            // 1. Базова статистика та ініціалізація
            // <w:t> — лише текст; без (?:\s…) шаблон захоплював би й <w:tab>, <w:tabs>, <w:tbl>
            $textTag = '/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s';
            $paragraphsCount = preg_match_all('/<w:p[ >]/', $xmlContent);
            $rawText = '';
            if (preg_match_all($textTag, $xmlContent, $matches)) {
                $rawText = html_entity_decode(implode('', $matches[1]));
            }
            $charsCount = mb_strlen($rawText);
            // Шрифти, задані вручну (довідково; фактичний шрифт основного тексту — $bodyFontWeights)
            $fonts = [];
            if (preg_match_all('/w:ascii="([^"]+)"/', $xmlContent, $fontMatches)) {
                $fonts = array_values(array_unique($fontMatches[1]));
            }
            $textLines = [];
            $bodyFontWeights = [];
            $bodyParagraphsWithIssues = 0;

            $actionableErrors = [];
            $lines = [];
            $currentSection = 'Початок документа';
            $isMainContent = false;
            $checkDate = date('d.m.Y H:i');

            $structure = [
                'hasTitle' => false, 'taskIndex' => -1, 'tocIndex' => -1,
                'introIndex' => -1, 'conclusionsIndex' => -1, 'referencesIndex' => -1,
                'appendicesIndex' => -1, 'isOrderValid' => false,
                'conclusionsTitle' => null,
                'referencesTitle' => null,
                'usesPronounI' => false,
            ];

            // Порушення форматування основного тексту: rule => ['count' => N, 'examples' => [...]]
            $bodyViolations = [];
            $bodyParagraphsChecked = 0;

            $physicalTablesCount = substr_count($xmlContent, '<w:tbl>');
            $physicalImagesCount = substr_count($xmlContent, '<w:drawing>') + preg_match_all('/<v:shape[ >]/', $xmlContent);
            $foundTableCaptions = 0;
            $foundImageCaptions = 0;

            if (preg_match('/\bя\b/iu', strip_tags($rawText))) {
                $structure['usesPronounI'] = true;
            }

            // 2. Глобальні перевірки: формат А4, усі чотири поля в кожному розділі, нумерація сторінок
            array_push($actionableErrors, ...PageSetupChecks::check($xmlContent, $headerFooterParts, $styles));

            $citations = new CitationChecks;
            $inReferences = false;
            // Чи почнеться наступний абзац із нової сторінки (розрив сторінки / розрив розділу перед ним)
            $pageBreakPending = true;
            $inToc = false;
            $tocDepth = 0;

            // 3. Поцикловий аналіз
            // Ділимо і за <w:p ...>, і за <w:p> без атрибутів; рахуємо глибину таблиць,
            // щоб не перевіряти кегль/інтервал тексту в клітинках.
            // Текстові поля (рамка й основний напис ЄСКД, підписи на схемах) вкладені в абзац і мають
            // власні абзаци. Якщо їх не прибрати, вони розірвуть «свій» абзац і підмінять його властивості.
            $flowXml = preg_replace('/<w:txbxContent>.*?<\/w:txbxContent>/s', '', $xmlContent);
            $rawParagraphs = preg_split('/(?=<w:p[ >])/', $flowXml);
            $tableDepth = 0;
            foreach ($rawParagraphs as $rawP) {
                $inTable = $tableDepth > 0;
                $tableDepth = max(0, $tableDepth + substr_count($rawP, '<w:tbl>') - substr_count($rawP, '</w:tbl>'));

                // Рядки автоматичного змісту (поле TOC) — не справжні заголовки
                $wasInToc = $inToc;
                if (!$inToc && preg_match('/<w:instrText[^>]*>\s*TOC\b/', $rawP)) {
                    $inToc = true;
                    $tocDepth = 0;
                }
                if ($inToc) {
                    $tocDepth += substr_count($rawP, 'w:fldCharType="begin"') - substr_count($rawP, 'w:fldCharType="end"');
                    if ($tocDepth <= 0) $inToc = false;
                }
                $isTocEntry = $wasInToc || $inToc || str_contains($rawP, 'PAGEREF') || str_contains($rawP, 'w:anchor="_Toc');

                // Розриви сторінок: до першого тексту абзацу, після останнього, розрив розділу
                $hasText = preg_match_all($textTag, $rawP, $tt) && trim(html_entity_decode(implode('', $tt[1]))) !== '';
                $firstText = preg_match('/<w:t[ >]/', $rawP, $pos, PREG_OFFSET_CAPTURE) ? $pos[0][1] : PHP_INT_MAX;
                $lastText = max((int) strrpos($rawP, '<w:t>'), (int) strrpos($rawP, '<w:t '));
                preg_match_all('/<w:br\b[^>]*w:type="page"|<w:lastRenderedPageBreak/', $rawP, $breaks, PREG_OFFSET_CAPTURE);
                $breakBeforeText = $breakAfterText = false;
                foreach ($breaks[0] as [$breakTag, $breakPos]) {
                    if ($breakPos < $firstText) $breakBeforeText = true;
                    // lastRenderedPageBreak — лише слід останнього розмічування, не явний розрив
                    if ($breakPos > $lastText && str_starts_with($breakTag, '<w:br')) $breakAfterText = true;
                }
                $sectionBreak = preg_match('/<w:sectPr\b.*?<\/w:sectPr>/s', $rawP, $sect)
                    && !preg_match('/<w:type w:val="(continuous|nextColumn)"/', $sect[0]);

                $startsOnNewPage = $pageBreakPending || ($hasText && $breakBeforeText);
                $pageBreakPending = $hasText
                    ? ($breakAfterText || $sectionBreak)
                    : ($pageBreakPending || $breaks[0] !== [] || $sectionBreak);

                if ($hasText) {
                    $lineText = trim(html_entity_decode(implode('', $tt[1])));

                    $upperLine = mb_strtoupper($lineText, 'UTF-8');
                    $lines[] = $upperLine;
                    $textLines[] = $lineText;

                    // Структурні заголовки впізнаємо незалежно від регістру: «Вступ» з ефектом
                    // «Усі великі» чи «Капітель» у Word виглядає як ВСТУП
                    $looksLikeHeading = mb_strlen($lineText) < 150 && !str_ends_with($lineText, '.');
                    $isStructural = in_array($upperLine, ['ЗМІСТ', 'РЕФЕРАТ', 'ВСТУП', 'ДОДАТКИ', ...self::CONCLUSION_TITLES, ...self::REFERENCES_TITLES], true)
                        || str_starts_with($upperLine, 'ПЕРЕЛІК УМОВНИХ ПОЗНАЧЕНЬ')
                        || ($looksLikeHeading && preg_match('/^(РОЗДІЛ\s+\d+|ДОДАТОК\s+[А-ЯҐЄІЇ](\s|$))/u', $upperLine));
                    $isStructuralHeading = $isStructural && !$isTocEntry && !$inTable;

                    // Радар заголовків
                    $isHeadingLine = false;
                    if ($isStructuralHeading || ($upperLine === $lineText && mb_strlen($lineText) > 3 && mb_strlen($lineText) < 100) || preg_match('/^\d+\.\d+/', $lineText)) {
                        $currentSection = mb_substr($lineText, 0, 60);
                        $isHeadingLine = true;
                        if (str_contains($upperLine, 'ВСТУП')) $isMainContent = true;
                        if (self::matchTitle($upperLine, self::REFERENCES_TITLES) || str_contains($upperLine, 'ДОДАТОК')) $isMainContent = false;
                    }

                    // Заголовки структурних елементів і розділів (ДСТУ 3008:2015): з нової сторінки,
                    // великими літерами, посередині рядка
                    if ($isStructuralHeading) {
                        $headingProps = $styles->paragraphProps($rawP);
                        $headingAt = mb_substr($lineText, 0, 60);

                        if (!$startsOnNewPage && !$headingProps['pageBreakBefore']) {
                            $actionableErrors[] = ['type' => 'Оформлення (Нова сторінка)', 'message' => 'Перед заголовком немає розриву сторінки (має починатися з нової сторінки)', 'location' => $headingAt, 'context' => 'Вставлення → Розрив сторінки (Ctrl+Enter) або Абзац → «З нової сторінки»'];
                        }
                        if ($lineText !== $upperLine) {
                            $textCase = $styles->textCase($rawP);
                            if ($textCase === 'smallCaps') {
                                $actionableErrors[] = ['type' => 'Оформлення (Заголовки)', 'message' => 'Заголовок набрано капітеллю, треба ВЕЛИКИМИ літерами', 'location' => $headingAt, 'context' => 'Шрифт → зніміть «Капітель» і ввімкніть «Усі великі»'];
                            } elseif ($textCase !== 'caps') {
                                $actionableErrors[] = ['type' => 'Оформлення (Заголовки)', 'message' => 'Треба ВЕЛИКИМИ літерами', 'location' => $headingAt, 'context' => 'Головна → Регістр → ВСІ ВЕЛИКІ'];
                            }
                        }
                        if ($headingProps['jc'] !== 'center') {
                            $actionableErrors[] = ['type' => 'Форматування (Вирівнювання)', 'message' => 'Заголовок має бути по центру', 'location' => $headingAt, 'context' => 'Головна → Вирівнювання по центру'];
                        }
                    }

                    // Список джерел: записи після заголовка до додатків; посилання [n] — в основному тексті
                    if (!$isTocEntry && !$inTable && in_array($upperLine, self::REFERENCES_TITLES, true)) {
                        $citations->startReferences($upperLine);
                        $inReferences = true;
                    } elseif ($inReferences && $looksLikeHeading && preg_match('/^ДОДАТ(ОК|КИ)/u', $upperLine)) {
                        $inReferences = false;
                    } elseif ($inReferences && !$inTable && mb_strlen($lineText) >= 10) {
                        $citations->addReference($lineText);
                    }
                    if ($isMainContent) {
                        $citations->addText($lineText);
                    }

                    // Крапки та Суржик/Я
                    if ($isHeadingLine && mb_substr($lineText, -1) === '.' && !preg_match('/^[\d\.\s]+$/', $lineText)) {
                        $actionableErrors[] = [ 'type' => 'Оформлення (Заголовки)', 'message' => 'Приберіть крапку в кінці.', 'location' => $currentSection, 'context' => $lineText ];
                    }
                    if (preg_match('/(?<=^|\s)я(?=\s|[.,!?:;]|$)/iu', $lineText)) {
                        $actionableErrors[] = [ 'type' => 'Стилістика', 'message' => 'Знайдено "я", треба "ми".', 'location' => $currentSection, 'context' => mb_substr($lineText, 0, 50) ];
                    }

                    // Підписи
                    if (preg_match('/^Рисунок\s+\d+/iu', $lineText)) $foundImageCaptions++;
                    if (preg_match('/^Таблиця\s+\d+/iu', $lineText)) $foundTableCaptions++;
                    if (preg_match('/^(Мал|Рис)\.?\s*\d+/iu', $lineText)) {
                        $actionableErrors[] = ['type' => 'Оформлення', 'message' => 'Пишіть "Рисунок" повністю', 'location' => $currentSection, 'context' => $lineText];
                        $foundImageCaptions++;
                    }

                    // Форматування (Тільки вступ -> джерела)
                    // Основний текст: від ВСТУПУ до списку джерел, без заголовків, таблиць і підписів.
                    $isCaption = preg_match('/^(Рисунок|Таблиця|Рис\.?|Мал\.?)\s*\d+/iu', $lineText);
                    if ($isMainContent && !$isHeadingLine && !$inTable && !$isTocEntry && !$isCaption && mb_strlen($lineText) > 30
                        && !self::isCodeFont(($props = $styles->paragraphProps($rawP))['font'])) {
                        $bodyParagraphsChecked++;
                        $example = ['location' => $currentSection, 'context' => mb_substr($lineText, 0, 50)];
                        $violationsBefore = array_sum(array_map('count', $bodyViolations));
                        $bodyFontWeights[$props['font'] ?? 'Times New Roman'] = ($bodyFontWeights[$props['font'] ?? 'Times New Roman'] ?? 0) + mb_strlen($lineText);

                        // Гарнітура (з урахуванням стилів і шрифтів теми)
                        if ($props['font'] !== null && $props['font'] !== 'Times New Roman') {
                            $bodyViolations['font'][] = $example + ['message' => "Шрифт «{$props['font']}» (треба Times New Roman)"];
                        }

                        // Кегль 14 пт = 28 пів-пунктів
                        if ($props['sz'] !== null && $props['sz'] !== 28) {
                            $pt = rtrim(rtrim(number_format($props['sz'] / 2, 1, ',', ''), '0'), ',');
                            $bodyViolations['size'][] = $example + ['message' => "Розмір шрифту $pt пт (треба 14 пт)"];
                        }

                        // Інтервал 1,5: для lineRule=auto це 360 (1/240 рядка); exact/atLeast — у twips (~21–25 пт)
                        $isOneAndHalf = $props['lineRule'] === 'auto'
                            ? abs($props['line'] - 360) <= 15
                            : $props['line'] >= 400 && $props['line'] <= 500;
                        if (!$isOneAndHalf) {
                            $actual = $props['lineRule'] === 'auto'
                                ? 'множник ' . rtrim(rtrim(number_format($props['line'] / 240, 2, ',', ''), '0'), ',')
                                : round($props['line'] / 20, 1) . ' пт';
                            $bodyViolations['spacing'][] = $example + ['message' => "Міжрядковий інтервал: $actual (треба 1,5)"];
                        }

                        // Вирівнювання по ширині
                        if (!in_array($props['jc'], ['both', 'distribute'], true)) {
                            $jcNames = ['left' => 'по лівому краю', 'start' => 'по лівому краю', 'center' => 'по центру', 'right' => 'по правому краю', 'end' => 'по правому краю'];
                            $bodyViolations['align'][] = $example + ['message' => 'Вирівнювання ' . ($jcNames[$props['jc']] ?? $props['jc']) . ' (треба по ширині)'];
                        }

                        // Абзацний відступ 1,25 см ≈ 709 twips (пункти списків мають власний виступ — пропускаємо)
                        if (!str_contains($rawP, '<w:numPr>') && ($props['firstLine'] < 680 || $props['firstLine'] > 740)) {
                            $cm = rtrim(rtrim(number_format($props['firstLine'] / 567, 2, ',', ''), '0'), ',');
                            $bodyViolations['indent'][] = $example + ['message' => "Абзацний відступ $cm см (треба 1,25 см)"];
                        }

                        if (array_sum(array_map('count', $bodyViolations)) > $violationsBefore) {
                            $bodyParagraphsWithIssues++;
                        }
                    }
                }
            }

            // Групуємо порушення основного тексту: кілька прикладів + підсумок,
            // бо неправильний стиль «Звичайний» дає ту саму помилку в кожному абзаці.
            $ruleTypes = [
                'font' => ['Форматування (Гарнітура)', 'шрифт Times New Roman'],
                'size' => ['Форматування (Шрифт)', 'кегль 14 пт'],
                'spacing' => ['Форматування (Інтервал)', 'інтервал 1,5'],
                'align' => ['Форматування (Вирівнювання)', 'вирівнювання по ширині'],
                'indent' => ['Форматування (Абзац)', 'абзацний відступ 1,25 см'],
            ];
            foreach ($ruleTypes as $rule => [$type, $requirement]) {
                $violations = $bodyViolations[$rule] ?? [];
                foreach (array_slice($violations, 0, self::MAX_EXAMPLES_PER_RULE) as $v) {
                    $actionableErrors[] = ['type' => $type] + $v;
                }
                if (count($violations) > self::MAX_EXAMPLES_PER_RULE) {
                    $actionableErrors[] = [
                        'type' => $type,
                        'message' => 'Усього абзаців з порушенням: ' . count($violations) . " з $bodyParagraphsChecked",
                        'location' => 'Основний текст',
                        'context' => "Змініть стиль абзацу (Головна → Стилі), щоб задати $requirement для всього тексту",
                    ];
                }
            }

            // 4. Фінальні перевірки
            array_push($actionableErrors, ...$citations->errors());
            if (!preg_match('/<w:instrText[^>]*>\s*TOC/i', $xmlContent)) {
                $actionableErrors[] = ['type' => 'Оформлення', 'message' => 'Зміст не автоматичний', 'location' => 'ЗМІСТ', 'context' => 'Вкладка Посилання'];
            }
            if ($physicalTablesCount > $foundTableCaptions) {
                $actionableErrors[] = ['type' => 'Відсутні підписи', 'message' => "Є непідписані таблиці (таблиць: $physicalTablesCount, підписів: $foundTableCaptions)", 'location' => 'Глобально', 'context' => 'Перевірте назви таблиць'];
            }
            if ($physicalImagesCount > ($foundImageCaptions + 1)) {
                $actionableErrors[] = ['type' => 'Відсутні підписи', 'message' => "Є непідписані рисунки", 'location' => 'Глобально', 'context' => 'Перевірте назви рисунків'];
            }

            // Розрахунок структури для діаграми
            foreach ($lines as $idx => $line) {
                if ($idx < 40 && (str_contains($line, 'КОНОТОП') || str_contains($line, 'МІНІСТЕРСТВО'))) $structure['hasTitle'] = true;
                if ($structure['taskIndex'] === -1 && str_contains($line, 'ЗАВДАННЯ')) $structure['taskIndex'] = $idx;
                if ($structure['tocIndex'] === -1 && str_contains($line, 'ЗМІСТ')) $structure['tocIndex'] = $idx;
                if ($structure['introIndex'] === -1 && str_contains($line, 'ВСТУП')) $structure['introIndex'] = $idx;
                if ($structure['conclusionsIndex'] === -1 && ($title = self::matchTitle($line, self::CONCLUSION_TITLES))) {
                    $structure['conclusionsIndex'] = $idx;
                    $structure['conclusionsTitle'] = $title;
                }
                if ($structure['referencesIndex'] === -1 && ($title = self::matchTitle($line, self::REFERENCES_TITLES))) {
                    $structure['referencesIndex'] = $idx;
                    $structure['referencesTitle'] = $title;
                }
                if ($structure['appendicesIndex'] === -1 && str_contains($line, 'ДОДАТОК')) $structure['appendicesIndex'] = $idx;
            }
            if ($structure['taskIndex'] !== -1 && $structure['tocIndex'] > $structure['taskIndex'] && $structure['introIndex'] > $structure['tocIndex']) $structure['isOrderValid'] = true;

            // Дані титульного аркуша (рядки до першого ЗМІСТУ)
            $tocAt = array_search('ЗМІСТ', $lines, true);
            $titlePage = TitlePageInfo::fromLines(array_slice($textLines, 0, $tocAt === false ? 60 : $tocAt));

            // Фактичний шрифт основного тексту (за кількістю символів)
            arsort($bodyFontWeights);
            $mainFont = array_key_first($bodyFontWeights);
            $mainFontShare = $bodyFontWeights ? (int) round(100 * reset($bodyFontWeights) / array_sum($bodyFontWeights)) : null;

            $fileHash = hash_file('sha256', $tempPath);
            $requiredParts = [
                'Титульний аркуш' => $structure['hasTitle'],
                'Завдання' => $structure['taskIndex'] !== -1,
                'Зміст' => $structure['tocIndex'] !== -1,
                'Вступ' => $structure['introIndex'] !== -1,
                'Висновки' => $structure['conclusionsIndex'] !== -1,
                'Список джерел' => $structure['referencesIndex'] !== -1,
            ];

            $report = [
                'number' => 'NC-' . strtoupper(substr($fileHash, 0, 8)),
                'issuedAt' => now()->format('d.m.Y H:i'),
                'checkedBy' => $request->user()?->name,
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'titlePage' => $titlePage,
                'stats' => [
                    'pages' => $pagesCount,
                    'words' => preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}’\'\-]*/u', implode(' ', $textLines)),
                    'paragraphs' => count($textLines),
                    'chars' => $charsCount,
                    'tables' => $physicalTablesCount,
                    'images' => $physicalImagesCount,
                ],
                'metrics' => [
                    'body' => [
                        'checked' => $bodyParagraphsChecked,
                        'ok' => $bodyParagraphsChecked - $bodyParagraphsWithIssues,
                        'percent' => $bodyParagraphsChecked ? (int) floor(100 * ($bodyParagraphsChecked - $bodyParagraphsWithIssues) / $bodyParagraphsChecked) : null,
                    ],
                    'mainFont' => $mainFont,
                    'mainFontShare' => $mainFontShare,
                    'page' => count(array_filter($actionableErrors, fn ($e) => preg_match('/Поля|Сторінка|Нумерація/u', $e['type']))),
                    'structure' => ['found' => count(array_filter($requiredParts)), 'total' => count($requiredParts), 'missing' => array_keys(array_filter($requiredParts, fn ($ok) => !$ok))],
                    'citations' => $citations->stats(),
                    'errors' => count($actionableErrors),
                ],
                'structure' => $structure,
                'errors' => $actionableErrors,
            ];

            // Звіт PDF будується з цих даних на сервері, а не з полів форми, які можна підмінити
            session(['norm_control_report' => $report]);

            return view('norm-control.results', [
                'report' => $report,
                'errorsCount' => count($actionableErrors),
                'fileName' => $fileName,
                'paragraphsCount' => $paragraphsCount,
                'charsCount' => $charsCount,
                'fonts' => $fonts,
                'mainFont' => $mainFont,
                'mainFontShare' => $mainFontShare,
                'structure' => $structure,
                'actionableErrors' => $actionableErrors,
                'tablesCount' => $physicalTablesCount,
                'imagesCount' => $physicalImagesCount,
                'studentName' => $titlePage['student'] ?? 'Не знайдено',
                'supervisorName' => $titlePage['supervisor'] ?? 'Не знайдено',
                'checkDate' => $checkDate,
            ]);
        }
        return back()->withErrors(['document' => 'Помилка архіву.']);
    }
    public function downloadPdf(Request $request)
    {
        $report = $request->session()->get('norm_control_report');
        if (!$report) {
            return redirect()->route('norm-control.index')
                ->withErrors(['document' => 'Звіт не знайдено або сесія завершилася. Перевірте роботу ще раз.']);
        }

        $surname = $report['titlePage']['student'] ? strtok($report['titlePage']['student'], ' ') . '-' : '';

        return self::makeReportPdf($report)->download("normcontrol-report-{$surname}{$report['number']}.pdf");
    }

    /** PDF-звіт із номерами «Сторінка X з Y» у колонтитулі (CSS counter(pages) dompdf не підтримує). */
    public static function makeReportPdf(array $report): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadView('norm-control.pdf_report', ['report' => $report])->setPaper('a4');
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $size = 7.5;
        $text = 'Сторінка {PAGE_NUM} з {PAGE_COUNT}';
        // Праве поле 18 мм; ширину рахуємо для найдовшого випадку (двоцифрові номери)
        $width = $dompdf->getFontMetrics()->getTextWidth('Сторінка 00 з 00', $font, $size);
        $mm = 72 / 25.4;
        $canvas->page_text($canvas->get_width() - 18 * $mm - $width, $canvas->get_height() - 20 * $mm + 8 * $mm, $text, $font, $size, [0.42, 0.45, 0.5]);

        return $pdf;
    }
}
