<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class NormControlTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = 'Метою роботи є розроблення інформаційної системи для автоматизації обліку.';

    /** Стиль «Звичайний», що відповідає ДСТУ: 14 пт, інтервал 1,5, по ширині */
    private const GOOD_STYLE = ['sz' => 28, 'line' => 360, 'jc' => 'both', 'ind' => 709];

    public function test_accepts_alternative_titles_for_conclusions_and_references(): void
    {
        $response = $this->check($this->document([
            $this->p('ВИСНОВОК', '<w:jc w:val="center"/>'),
            $this->p('Отже, можна зробити висновок, що система відповідає поставленим вимогам.'),
            $this->p('СПИСОК ЛІТЕРАТУРИ', '<w:jc w:val="center"/>'),
        ]));

        $response->assertOk()->assertViewHas('structure', function (array $s) {
            return $s['conclusionsTitle'] === 'ВИСНОВОК' && $s['referencesTitle'] === 'СПИСОК ЛІТЕРАТУРИ';
        });
    }

    public function test_sentence_with_word_conclusion_is_not_a_section(): void
    {
        $response = $this->check($this->document([
            $this->p('Отже, можна зробити висновок, що система відповідає поставленим вимогам.'),
        ]));

        $response->assertViewHas('structure', fn (array $s) => $s['conclusionsIndex'] === -1);
    }

    public function test_detects_size_spacing_and_alignment_inherited_from_style(): void
    {
        // Жодного прямого форматування: усе береться зі стилю «Звичайний»
        $response = $this->check($this->document(
            [$this->p(self::BODY)],
            ['sz' => 24, 'line' => 240, 'jc' => 'left', 'ind' => 0],
        ));

        $messages = $this->messages($response);
        $this->assertContains('Розмір шрифту 12 пт (треба 14 пт)', $messages);
        $this->assertContains('Міжрядковий інтервал: множник 1 (треба 1,5)', $messages);
        $this->assertContains('Вирівнювання по лівому краю (треба по ширині)', $messages);
        $this->assertContains('Абзацний відступ 0 см (треба 1,25 см)', $messages);
    }

    public function test_direct_indent_overrides_style_without_indent(): void
    {
        $response = $this->check($this->document(
            [$this->p(self::BODY, '<w:ind w:firstLine="709"/>')],
            ['ind' => 0] + self::GOOD_STYLE,
        ));

        $this->assertSame([], $this->bodyFormattingErrors($response));
    }

    public function test_list_items_are_not_checked_for_indent(): void
    {
        $response = $this->check($this->document([
            $this->p(self::BODY, '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr><w:ind w:left="720" w:hanging="360"/>'),
        ]));

        $this->assertSame([], $this->bodyFormattingErrors($response));
    }

    public function test_correct_body_text_has_no_formatting_errors(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)]));

        $this->assertSame([], $this->bodyFormattingErrors($response));
    }

    public function test_direct_formatting_overrides_style(): void
    {
        $response = $this->check($this->document([
            $this->p(self::BODY, '<w:spacing w:line="240" w:lineRule="auto"/><w:jc w:val="center"/>', '<w:sz w:val="32"/>'),
        ]));

        $messages = $this->messages($response);
        $this->assertContains('Розмір шрифту 16 пт (треба 14 пт)', $messages);
        $this->assertContains('Міжрядковий інтервал: множник 1 (треба 1,5)', $messages);
        $this->assertContains('Вирівнювання по центру (треба по ширині)', $messages);
    }

    public function test_text_inside_tables_is_not_checked_for_body_formatting(): void
    {
        $cell = $this->p('Довгий текст у клітинці таблиці, набраний дрібнішим шрифтом.', '<w:jc w:val="left"/>', '<w:sz w:val="20"/>');
        $table = "<w:tbl><w:tblPr/><w:tr><w:tc>$cell</w:tc></w:tr></w:tbl>";

        $response = $this->check($this->document([$this->p('Таблиця 1 – Перелік модулів'), $table]));

        $this->assertSame([], $this->bodyFormattingErrors($response));
    }

    public function test_repeated_violations_are_grouped(): void
    {
        $response = $this->check($this->document(
            array_fill(0, 8, $this->p(self::BODY)),
            ['sz' => 24] + self::GOOD_STYLE,
        ));

        $sizeErrors = array_values(array_filter(
            $response->viewData('actionableErrors'),
            fn ($e) => $e['type'] === 'Форматування (Шрифт)',
        ));

        $this->assertCount(6, $sizeErrors); // 5 прикладів + підсумок
        $this->assertSame('Усього абзаців з порушенням: 8 з 8', $sizeErrors[5]['message']);
    }

    // --- Гарнітура ---

    public function test_detects_wrong_font_from_style(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)], ['font' => 'w:ascii="Arial" w:hAnsi="Arial"'] + self::GOOD_STYLE));

        $this->assertSame(['Шрифт «Arial» (треба Times New Roman)'], $this->errorsOfType($response, 'Форматування (Гарнітура)'));
    }

    public function test_resolves_theme_font(): void
    {
        $theme = '<?xml version="1.0"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:themeElements><a:fontScheme name="Office">'
            .'<a:majorFont><a:latin typeface="Calibri Light"/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/></a:minorFont>'
            .'</a:fontScheme></a:themeElements></a:theme>';

        $response = $this->check($this->document(
            [$this->p(self::BODY)],
            ['font' => 'w:asciiTheme="minorHAnsi" w:hAnsiTheme="minorHAnsi"'] + self::GOOD_STYLE,
            ['theme' => $theme],
        ));

        $this->assertSame(['Шрифт «Calibri» (треба Times New Roman)'], $this->errorsOfType($response, 'Форматування (Гарнітура)'));
    }

    public function test_times_new_roman_passes_and_code_listings_are_skipped(): void
    {
        $code = $this->p('public function store(Request $request): RedirectResponse { return back(); }', '<w:jc w:val="left"/>', '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New"/><w:sz w:val="20"/>');

        $response = $this->check($this->document(
            [$this->p(self::BODY), $code],
            ['font' => 'w:ascii="Times New Roman" w:hAnsi="Times New Roman"'] + self::GOOD_STYLE,
        ));

        $this->assertSame([], $this->errorsOfType($response, 'Форматування (Гарнітура)'));
        $this->assertSame([], $this->bodyFormattingErrors($response));
    }

    // --- Поля й формат ---

    public function test_correct_margins_pass(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)]));

        $this->assertSame([], $this->errorsOfType($response, 'Форматування (Поля)'));
        $this->assertSame([], $this->errorsOfType($response, 'Форматування (Сторінка)'));
    }

    public function test_detects_all_four_margins(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)], self::GOOD_STYLE, ['sectPr' => $this->sectPr([10, 5, 15, 25])]));

        $this->assertEqualsCanonicalizing([
            'Ліве поле: 25 мм (треба 30 мм)',
            'Праве поле: 5 мм (треба не менше 10 мм)',
            'Верхнє поле: 10 мм (треба не менше 20 мм)',
            'Нижнє поле: 15 мм (треба не менше 20 мм)',
        ], $this->errorsOfType($response, 'Форматування (Поля)'));
    }

    public function test_landscape_section_is_a4_and_its_margins_are_not_checked(): void
    {
        // Розрив розділу з альбомною сторінкою для широкої таблиці
        $landscapeBreak = '<w:p><w:pPr>'.$this->sectPr([20, 10, 20, 30]).'</w:pPr></w:p>';
        $lastSection = $this->sectPr([20, 10, 20, 30], '', false, true);
        $lastSection = str_replace('w:top="1134"', 'w:top="300"', $lastSection);

        $response = $this->check($this->document([$this->p(self::BODY), $landscapeBreak], self::GOOD_STYLE, ['sectPr' => $lastSection]));

        $this->assertSame([], $this->errorsOfType($response, 'Форматування (Поля)'));
        $this->assertSame([], $this->errorsOfType($response, 'Форматування (Сторінка)'));
    }

    public function test_margin_errors_name_the_section_in_multi_section_documents(): void
    {
        $firstSection = '<w:p><w:pPr>'.$this->sectPr([20, 10, 20, 30]).'</w:pPr></w:p>';
        $response = $this->check($this->document([$firstSection, $this->p(self::BODY)], self::GOOD_STYLE, ['sectPr' => $this->sectPr([20, 10, 20, 20], '')]));

        $errors = array_values(array_filter($response->viewData('actionableErrors'), fn ($e) => $e['type'] === 'Форматування (Поля)'));
        $this->assertCount(1, $errors);
        $this->assertSame('Розділи документа: 2', $errors[0]['location']);
    }

    // --- Нумерація сторінок ---

    public function test_correct_page_numbers_pass(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)]));

        $this->assertSame([], $this->errorsOfType($response, 'Оформлення (Нумерація)'));
    }

    public function test_detects_missing_page_numbers(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)], self::GOOD_STYLE, ['sectPr' => $this->sectPr(refs: ''), 'parts' => []]));

        $this->assertSame(['Сторінки не пронумеровано'], $this->errorsOfType($response, 'Оформлення (Нумерація)'));
    }

    public function test_detects_page_number_in_footer(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)], self::GOOD_STYLE, [
            'sectPr' => $this->sectPr(refs: '<w:footerReference w:type="default" r:id="rIdF"/>'),
            'parts' => ['rIdF' => ['footer', $this->pageNumberPart('center', 'ftr')]],
        ]));

        $this->assertSame(['Номер сторінки внизу (треба в правому верхньому куті)'], $this->errorsOfType($response, 'Оформлення (Нумерація)'));
    }

    public function test_detects_centered_page_number(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)], self::GOOD_STYLE, [
            'parts' => ['rIdH' => ['header', $this->pageNumberPart('center')]],
        ]));

        $this->assertSame(['Номер сторінки по центру (треба в правому верхньому куті)'], $this->errorsOfType($response, 'Оформлення (Нумерація)'));
    }

    public function test_detects_page_number_on_title_page(): void
    {
        $response = $this->check($this->document([$this->p(self::BODY)], self::GOOD_STYLE, ['sectPr' => $this->sectPr(titlePg: false)]));

        $this->assertSame(['Номер сторінки стоїть на титульному аркуші'], $this->errorsOfType($response, 'Оформлення (Нумерація)'));
    }

    // --- Нова сторінка ---

    public function test_detects_structural_heading_without_page_break(): void
    {
        $response = $this->check($this->document(
            [$this->p(self::BODY), $this->p('РОЗДІЛ 1 АНАЛІЗ ПРЕДМЕТНОЇ ОБЛАСТІ', '<w:jc w:val="center"/>'), $this->p(self::BODY)],
            self::GOOD_STYLE,
            ['intro' => [$this->p('ЗМІСТ', '<w:jc w:val="center"/>'), $this->p('ВСТУП', '<w:jc w:val="center"/>')]],
        ));

        $errors = array_values(array_filter($response->viewData('actionableErrors'), fn ($e) => $e['type'] === 'Оформлення (Нова сторінка)'));
        $this->assertSame(['ВСТУП', 'РОЗДІЛ 1 АНАЛІЗ ПРЕДМЕТНОЇ ОБЛАСТІ'], array_column($errors, 'location'));
    }

    public function test_page_break_in_run_or_style_or_section_break_counts_as_new_page(): void
    {
        $center = '<w:jc w:val="center"/>';
        $response = $this->check($this->document([
            $this->p(self::BODY),
            // розрив у тому самому абзаці перед текстом
            '<w:p><w:pPr>'.$center.'</w:pPr><w:r><w:br w:type="page"/></w:r><w:r><w:t>РОЗДІЛ 1 АНАЛІЗ</w:t></w:r></w:p>',
            $this->p(self::BODY),
            // стиль «Заголовок 1» з «З нової сторінки»
            $this->p('РОЗДІЛ 2 ПРОЄКТУВАННЯ', '<w:pStyle w:val="Heading1"/>'),
            $this->p(self::BODY),
            // розрив розділу (наступна сторінка)
            '<w:p><w:pPr>'.$this->sectPr().'</w:pPr></w:p>',
            $this->p('ВИСНОВКИ', $center),
        ]));

        $this->assertSame([], $this->errorsOfType($response, 'Оформлення (Нова сторінка)'));
    }

    public function test_toc_entries_are_not_checked_for_new_page(): void
    {
        $tocEntry = fn (string $title) => '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGEREF _Toc1 \h </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>'.$title.'</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';

        $response = $this->check($this->document([], self::GOOD_STYLE, ['intro' => [
            $this->p('ЗМІСТ', '<w:jc w:val="center"/>'),
            $tocEntry('ВСТУП'),
            $tocEntry('ВИСНОВКИ'),
            $this->pageBreak(),
            $this->p('ВСТУП', '<w:jc w:val="center"/>'),
        ]]));

        $this->assertSame([], $this->errorsOfType($response, 'Оформлення (Нова сторінка)'));
    }

    // --- Посилання на джерела ---

    private function withReferences(array $bodyParagraphs, int $referencesCount): string
    {
        $entries = array_map(fn ($i) => $this->p("Автор $i. Назва джерела номер $i. Київ, 2024. 120 с."), range(1, $referencesCount));

        return $this->document([
            ...$bodyParagraphs,
            $this->pageBreak(),
            $this->p('СПИСОК ВИКОРИСТАНИХ ДЖЕРЕЛ', '<w:jc w:val="center"/>'),
            ...$entries,
        ]);
    }

    public function test_all_sources_cited_passes(): void
    {
        $response = $this->check($this->withReferences([
            $this->p('Питання розглянуто в працях [1], [2, с. 15] та узагальнено в [3–4].'),
        ], 4));

        $this->assertSame([], $this->errorsOfType($response, 'Посилання на джерела'));
    }

    public function test_detects_citation_to_missing_source_and_uncited_sources(): void
    {
        $response = $this->check($this->withReferences([
            $this->p('Згідно з [1] та [5; 7, с. 3] система має відповідати вимогам.'),
        ], 3));

        $this->assertSame([
            'Посилання на джерела, яких немає у списку: [5], [7]',
            'Джерела без посилань у тексті: №2, 3',
        ], $this->errorsOfType($response, 'Посилання на джерела'));
    }

    public function test_detects_text_without_any_citations(): void
    {
        $response = $this->check($this->withReferences([$this->p(self::BODY)], 2));

        $this->assertSame(['У тексті немає посилань на джерела'], $this->errorsOfType($response, 'Посилання на джерела'));
    }

    // --- Заголовки: регістр і рамка ---

    public function test_heading_with_all_caps_effect_is_uppercase_and_starts_main_content(): void
    {
        $response = $this->check($this->document(
            [$this->p(self::BODY, '', '<w:sz w:val="24"/>')],
            self::GOOD_STYLE,
            ['intro' => [$this->p('ЗМІСТ', '<w:jc w:val="center"/>'), $this->pageBreak(), $this->p('Вступ', '<w:jc w:val="center"/>', '<w:caps/>')]],
        ));

        $this->assertSame([], $this->errorsOfType($response, 'Оформлення (Заголовки)'));
        // «Вступ» розпізнано, тож основний текст перевірено
        $this->assertSame(['Розмір шрифту 12 пт (треба 14 пт)'], $this->errorsOfType($response, 'Форматування (Шрифт)'));
    }

    public function test_heading_case_errors(): void
    {
        $center = '<w:jc w:val="center"/>';
        $response = $this->check($this->document([
            $this->p(self::BODY),
            $this->pageBreak(),
            $this->p('Висновки', $center, '<w:smallCaps/>'),
            $this->pageBreak(),
            $this->p('Список використаних джерел', $center),
        ]));

        $this->assertSame([
            'Заголовок набрано капітеллю, треба ВЕЛИКИМИ літерами',
            'Треба ВЕЛИКИМИ літерами',
        ], $this->errorsOfType($response, 'Оформлення (Заголовки)'));
    }

    public function test_text_box_inside_heading_does_not_break_it(): void
    {
        // Рамка ЄСКД з шифром документа — текстове поле, прив'язане до абзацу заголовка
        $frame = '<w:r><w:pict><v:shape><v:textbox><w:txbxContent><w:p><w:pPr><w:jc w:val="left"/></w:pPr><w:r><w:t>КР-121.00.000 ПЗ</w:t></w:r></w:p></w:txbxContent></v:textbox></v:shape></w:pict></w:r>';
        $heading = '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'.$frame.'<w:r><w:t>РОЗДІЛ 1 АНАЛІЗ</w:t></w:r></w:p>';

        $response = $this->check($this->document([$this->p(self::BODY), $this->pageBreak(), $heading, $this->p(self::BODY)]));

        $this->assertSame([], $this->errorsOfType($response, 'Форматування (Вирівнювання)'));
        $this->assertSame([], $this->errorsOfType($response, 'Оформлення (Нова сторінка)'));
    }

    // --- Звіт ---

    public function test_report_contains_title_page_data_even_with_tab_stops(): void
    {
        // <w:tabs>/<w:tab> раніше потрапляли в текст рядка як XML
        $tabbed = '<w:p><w:pPr><w:tabs><w:tab w:val="left" w:pos="5220"/></w:tabs></w:pPr><w:r><w:tab/><w:t>Петренко Іван Олегович</w:t></w:r></w:p>';

        $response = $this->check($this->document([$this->p(self::BODY)], self::GOOD_STYLE, ['intro' => [
            $this->p('КУРСОВА РОБОТА'),
            $this->p('на тему: «Система обліку»'),
            $this->p('Виконав студент 4 курсу, 741 групи'),
            $tabbed,
            $this->p('(прізвище та ініціали)'),
            $this->p('Керівник: Коваленко О. М.'),
            $this->p('м. Конотоп, 2026 рік'),
            $this->pageBreak(),
            $this->p('ЗМІСТ', '<w:jc w:val="center"/>'),
            $this->pageBreak(),
            $this->p('ВСТУП', '<w:jc w:val="center"/>'),
        ]]));

        $report = $response->viewData('report');
        $this->assertSame('Петренко Іван Олегович', $report['titlePage']['student']);
        $this->assertSame('Система обліку', $report['titlePage']['topic']);
        $this->assertSame('2026', $report['titlePage']['year']);
        $this->assertMatchesRegularExpression('/^NC-[0-9A-F]{8}$/', $report['number']);
        $this->assertSame('Times New Roman', $report['metrics']['mainFont']);
        $this->assertSame(['checked' => 1, 'ok' => 1, 'percent' => 100], $report['metrics']['body']);
    }

    public function test_pdf_report_is_built_from_session(): void
    {
        $this->check($this->document([$this->p(self::BODY)]));

        $response = $this->post(route('norm-control.download'));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_pdf_report_without_check_redirects(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('norm-control.download'))
            ->assertRedirect(route('norm-control.index'))
            ->assertSessionHasErrors('document');
    }

    public function test_pdf_report_view_renders_sections(): void
    {
        $this->check($this->document([$this->p(self::BODY, '<w:jc w:val="left"/>')]));
        $html = view('norm-control.pdf_report', ['report' => session('norm_control_report')])->render();

        foreach (['Звіт нормоконтролю роботи', '1. Робота', '2. Показники', '3. Зауваження', '4. Рішення нормоконтролера', 'Основний текст', 'Вирівнювання по лівому краю (треба по ширині)', 'Підпис нормоконтролера та дата'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    // --- допоміжні методи ---

    private function check(string $docxPath)
    {
        $file = new UploadedFile($docxPath, 'robota.docx', null, null, true);

        return $this->actingAs(User::factory()->create())
            ->post(route('norm-control.process'), ['document' => $file]);
    }

    private function messages($response): array
    {
        return array_column($response->viewData('actionableErrors'), 'message');
    }

    private function bodyFormattingErrors($response): array
    {
        return array_values(array_filter(
            $response->viewData('actionableErrors'),
            fn ($e) => in_array($e['type'], ['Форматування (Шрифт)', 'Форматування (Інтервал)', 'Форматування (Вирівнювання)', 'Форматування (Абзац)'], true),
        ));
    }

    private function p(string $text, string $pPr = '', string $rPr = ''): string
    {
        $pPr = $pPr ? "<w:pPr>$pPr</w:pPr>" : '';
        $rPr = $rPr ? "<w:rPr>$rPr</w:rPr>" : '';

        return "<w:p>$pPr<w:r>$rPr<w:t>$text</w:t></w:r></w:p>";
    }

    private function errorsOfType($response, string $type): array
    {
        return array_column(array_filter(
            $response->viewData('actionableErrors'),
            fn ($e) => $e['type'] === $type,
        ), 'message');
    }

    private function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    /** Розділ документа: А4, поля в мм (верхнє, праве, нижнє, ліве), посилання на колонтитули. */
    private function sectPr(array $marginsMm = [20, 10, 20, 30], string $refs = '<w:headerReference w:type="default" r:id="rIdH"/>', bool $titlePg = true, bool $landscape = false): string
    {
        [$top, $right, $bottom, $left] = array_map(fn ($mm) => (int) round($mm * 56.7), $marginsMm);
        $size = $landscape ? '<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>' : '<w:pgSz w:w="11906" w:h="16838"/>';

        return "<w:sectPr>$refs$size<w:pgMar w:top=\"$top\" w:right=\"$right\" w:bottom=\"$bottom\" w:left=\"$left\" w:header=\"709\" w:footer=\"709\" w:gutter=\"0\"/>"
            .($titlePg ? '<w:titlePg/>' : '').'</w:sectPr>';
    }

    /** Колонтитул із полем PAGE, вирівняним як $jc */
    private function pageNumberPart(string $jc = 'right', string $kind = 'hdr'): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:$kind $w><w:p><w:pPr><w:jc w:val=\"$jc\"/></w:pPr>"
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE   \* MERGEFORMAT </w:instrText></w:r>'
            ."<w:r><w:fldChar w:fldCharType=\"separate\"/></w:r><w:r><w:t>2</w:t></w:r><w:r><w:fldChar w:fldCharType=\"end\"/></w:r></w:p></w:$kind>";
    }

    /**
     * Документ: ЗМІСТ, розрив сторінки, ВСТУП, вказані абзаци, розділ документа.
     * Типово все, крім вказаного в тесті, оформлено правильно.
     *
     * @param  array{sectPr?: string, parts?: array<string, array{0: string, 1: string}>, theme?: string, intro?: list<string>}  $opts
     */
    private function document(array $bodyParagraphs, array $normal = self::GOOD_STYLE, array $opts = []): string
    {
        $center = '<w:jc w:val="center"/>';
        $body = implode('', [
            ...($opts['intro'] ?? [$this->p('ЗМІСТ', $center), $this->pageBreak(), $this->p('ВСТУП', $center)]),
            ...$bodyParagraphs,
            $opts['sectPr'] ?? $this->sectPr(),
        ]);
        $parts = $opts['parts'] ?? ['rIdH' => ['header', $this->pageNumberPart()]];

        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $document = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><w:document $ns><w:body>$body</w:body></w:document>";

        $fonts = isset($normal['font']) ? "<w:rFonts {$normal['font']}/>" : '';
        $styles = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><w:styles $ns>"
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/>'
            ."<w:pPr><w:spacing w:line=\"{$normal['line']}\" w:lineRule=\"auto\"/><w:ind w:firstLine=\"{$normal['ind']}\"/><w:jc w:val=\"{$normal['jc']}\"/></w:pPr>"
            ."<w:rPr>$fonts<w:sz w:val=\"{$normal['sz']}\"/></w:rPr></w:style>"
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:pageBreakBefore/><w:jc w:val="center"/></w:pPr></w:style>'
            .'</w:styles>';

        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rIdStyles" Type="'.$rel.'/styles" Target="styles.xml"/>';
        $files = [];
        foreach ($parts as $id => [$kind, $xml]) {
            $rels .= "<Relationship Id=\"$id\" Type=\"$rel/$kind\" Target=\"$id.xml\"/>";
            $files["word/$id.xml"] = $xml;
        }
        if (isset($opts['theme'])) {
            $rels .= "<Relationship Id=\"rIdTheme\" Type=\"$rel/theme\" Target=\"theme/theme1.xml\"/>";
            $files['word/theme/theme1.xml'] = $opts['theme'];
        }
        $rels .= '</Relationships>';

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->addFromString('word/_rels/document.xml.rels', $rels);
        foreach ($files as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        return $path;
    }
}
