<?php

namespace App\Http\Controllers;

use App\Services\NormControl\AppendixChecks;
use App\Services\NormControl\CaptionChecks;
use App\Services\NormControl\CitationChecks;
use App\Services\NormControl\FormulaChecks;
use App\Services\NormControl\FrameChecks;
use App\Services\NormControl\PageSetupChecks;
use App\Services\NormControl\TitlePageInfo;
use App\Services\NormControl\WordStyles;
use App\Models\User;
use App\Services\NormControl\ReportDocx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    /**
     * Чи стоїть таблиця ('table') чи рисунок ('drawing') одразу перед ($dir = -1) або після ($dir = 1)
     * абзацу $i. Порожні абзаци між ними пропускаємо, абзац з текстом зупиняє пошук.
     */
    private static function objectNear(array $chunks, int $i, int $dir, string $object): bool
    {
        $text = '/<w:t(?:\s[^>]*)?>\s*\S/u';
        $drawing = '/<w:drawing>|<w:pict>|<w:object\b|<v:shape[ >]/';

        if ($object === 'drawing' && $dir < 0) {
            // Рисунок і підпис в одному абзаці (через розрив рядка)
            $beforeText = preg_split('/<w:t[ >]/', $chunks[$i], 2)[0];
            if (preg_match($drawing, $beforeText)) {
                return true;
            }
        }
        if ($object === 'table' && $dir > 0 && str_contains(substr($chunks[$i], (int) strrpos($chunks[$i], '</w:p>')), '<w:tbl>')) {
            return true;
        }

        for ($k = 1; $k <= 3; $k++) {
            $chunk = $chunks[$i + $dir * $k] ?? null;
            if ($chunk === null) {
                return false;
            }
            if ($object === 'table') {
                if ($dir < 0 && str_contains($chunk, '</w:tbl>')) {
                    return true;
                }
                if ($dir > 0 && !preg_match($text, $chunk) && str_contains($chunk, '<w:tbl>')) {
                    return true;
                }
            } elseif (preg_match($drawing, $chunk)) {
                return true;
            }
            if (preg_match($text, $chunk)) {
                return false;
            }
        }

        return false;
    }

    /** Номер формули «(2.1)» у сусідній клітинці таблиці без меж */
    private static function numberInNextCell(array $chunks, int $i): ?string
    {
        foreach ([$i + 1, $i + 2] as $k) {
            if (!isset($chunks[$k]) || !preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $chunks[$k], $m)) {
                continue;
            }
            $text = trim(html_entity_decode(implode('', $m[1])));
            if (preg_match('/^\(\s*(\d+(?:\.\d+)*)\s*\)$/u', $text, $n)) {
                return '(' . $n[1] . ')';
            }
            if ($text !== '') {
                return null;
            }
        }

        return null;
    }

    /** Моноширинні шрифти лістингів програм — такі абзаци не перевіряємо як основний текст */
    private static function isCodeFont(?string $font): bool
    {
        return $font !== null && (bool) preg_match('/mono|code|courier|consolas|menlo|monaco|lucida console/i', $font);
    }

    public function index(Request $request)
    {
        return view('norm-control.index', [
            'remainingChecks' => $request->user()->remainingChecksToday(),
            'dailyLimit' => User::STUDENT_DAILY_CHECKS,
        ]);
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

        $user = $request->user();
        $file = $request->file('document');

        // Резервуємо перевірку ДО аналізу, заблокувавши рядок користувача,
        // щоб дві одночасні відправки не обійшли денний ліміт студента.
        $check = DB::transaction(function () use ($user, $file) {
            User::whereKey($user->id)->lockForUpdate()->first();
            if ($user->remainingChecksToday() === 0) {
                return null;
            }

            return $user->normControlChecks()->create([
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_hash' => hash_file('sha256', $file->getRealPath()),
            ]);
        });

        if (!$check) {
            return back()->withErrors(['document' => self::limitMessage()]);
        }

        try {
            $response = $this->analyze($request, $file);
        } catch (\Throwable $e) {
            $check->delete(); // збій перевірки не забирає спробу
            throw $e;
        }

        if ($response instanceof RedirectResponse) {
            $check->delete(); // пошкоджений файл не забирає спробу
            return $response;
        }

        $check->update(['errors_count' => count(session('norm_control_report.errors', []))]);

        return $response;
    }

    public static function limitMessage(): string
    {
        return 'Студенти можуть перевіряти ' . User::STUDENT_DAILY_CHECKS . ' документ на день. '
            . 'Ліміт на сьогодні вичерпано — наступна перевірка буде доступна завтра (з 00:00 за київським часом).';
    }

    private function analyze(Request $request, UploadedFile $file)
    {
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
            $captions = new CaptionChecks;
            $appendices = new AppendixChecks;
            $formulas = new FormulaChecks;
            $afterIntro = false; // посилання на рисунки, таблиці, формули, додатки шукаємо від ВСТУПУ
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
            foreach ($rawParagraphs as $pi => $rawP) {
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

                // Винесені формули (Word Equation або Equation/MathType): номер «(2.1)» у тому ж рядку
                // або, якщо формулу розміщено в таблиці без меж, — у сусідній клітинці
                $chunkText = $hasText ? trim(html_entity_decode(implode('', $tt[1]))) : '';
                if ($afterIntro && !$inReferences && FormulaChecks::isDisplayFormula($rawP, $chunkText)) {
                    $formulaNumber = FormulaChecks::numberIn($rawP, $chunkText)
                        ?? ($inTable ? self::numberInNextCell($rawParagraphs, $pi) : null);
                    $formulas->formula($formulaNumber, $currentSection);
                    continue;
                }

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
                    // Рядок великими літерами — заголовок, лише якщо в ньому є українські слова (не «TC-4», не «SQL»)
                    $isCapsHeading = $upperLine === $lineText && mb_strlen($lineText) > 3 && mb_strlen($lineText) < 100 && preg_match('/\p{Cyrillic}{2}/u', $lineText);
                    if ($isStructuralHeading || $isCapsHeading || preg_match('/^\d+\.\d+/', $lineText)) {
                        $currentSection = mb_substr($lineText, 0, 60);
                        $isHeadingLine = true;
                        if (str_contains($upperLine, 'ВСТУП')) $isMainContent = true;
                        if (self::matchTitle($upperLine, self::REFERENCES_TITLES) || str_contains($upperLine, 'ДОДАТОК')) $isMainContent = false;
                    }
                    if ($isMainContent && !$isTocEntry) {
                        $afterIntro = true;
                    }

                    // Підписи рисунків і таблиць (і чи стоїть поруч сам рисунок / таблиця)
                    $captionKind = $isTocEntry ? null : CaptionChecks::kindOf($lineText);
                    $isCaption = false;
                    if ($captionKind !== null) {
                        $object = $captionKind === 'table' ? 'table' : 'drawing';
                        $isCaption = $captions->caption($captionKind, $lineText, $currentSection, [
                            'before' => self::objectNear($rawParagraphs, $pi, -1, $object),
                            'after' => self::objectNear($rawParagraphs, $pi, 1, $object),
                        ]);
                        if ($isCaption) {
                            $captionKind === 'table' ? $foundTableCaptions++ : $foundImageCaptions++;
                        }
                    }

                    // Додатки: «ДОДАТОК А» + назва наступним рядком
                    $isAppendixHeading = !$isTocEntry && !$inTable && $looksLikeHeading
                        && preg_match('/^ДОДАТОК(?!\p{L}{2})\s*(.*)$/u', $upperLine, $appendixMatch);
                    if ($isAppendixHeading) {
                        $appendices->heading($lineText, $appendixMatch[1]);
                    } else {
                        $appendices->line($lineText, $isStructuralHeading || $isCaption);
                    }

                    // Посилання в тексті на рисунки, таблиці, формули й додатки (заголовки — не посилання)
                    if ($afterIntro && !$isCaption && !$isTocEntry && !$inReferences && !$isStructuralHeading && !$isAppendixHeading) {
                        $captions->addText($lineText);
                        $formulas->addText($lineText);
                        $appendices->addText($lineText);
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

                    // Форматування (Тільки вступ -> джерела)
                    // Основний текст: від ВСТУПУ до списку джерел, без заголовків, таблиць і підписів.
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
            array_push($actionableErrors, ...$citations->errors(), ...$captions->errors(), ...$formulas->errors(), ...$appendices->errors());
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

            // Рамка й основний напис (ГОСТ 2.104) — звіряємо з даними титулки
            array_push($actionableErrors, ...FrameChecks::check($xmlContent, $headerFooterParts, $titlePage));

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

            // Звіт (DOCX) будується з цих даних на сервері, а не з полів форми, які можна підмінити
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
    /** Звіт у форматі Word, зібраний з даних останньої перевірки в сесії */
    public function downloadReport(Request $request)
    {
        $report = $request->session()->get('norm_control_report');
        if (!$report) {
            return redirect()->route('norm-control.index')
                ->withErrors(['document' => 'Звіт не знайдено або сесія завершилася. Перевірте роботу ще раз.']);
        }

        $surname = $report['titlePage']['student'] ? strtok($report['titlePage']['student'], ' ') . '-' : '';
        $docx = ReportDocx::build($report);

        return response()->streamDownload(
            function () use ($docx) {
                echo $docx;
            },
            "Звіт-нормоконтролю-{$surname}{$report['number']}.docx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }
}
