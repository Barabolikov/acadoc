<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
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

    public function test_docx_report_is_built_from_session(): void
    {
        // Текст із символами, які треба екранувати в XML звіту
        $this->check($this->document([$this->p('Порівняння A &amp; B: значення &lt;x&gt; має бути «коректним» у тексті', '<w:jc w:val="left"/>')]));

        $response = $this->post(route('norm-control.download'));
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('.docx', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'report');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        // Усі частини — коректний XML
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels', 'word/document.xml', 'word/styles.xml', 'word/settings.xml', 'word/footer1.xml', 'docProps/core.xml'] as $part) {
            $xml = $zip->getFromName($part);
            $this->assertNotFalse($xml, "Немає частини $part");
            $this->assertTrue((new \DOMDocument)->loadXML($xml), "Некоректний XML у $part");
        }

        $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $zip->getFromName('word/document.xml'))));
        foreach (['Звіт нормоконтролю роботи', '1. Робота', '2. Показники', '3. Зауваження', '4. Рішення нормоконтролера',
            'Вирівнювання по лівому краю (треба по ширині)', 'Порівняння A & B: значення <x>', 'Підпис нормоконтролера та дата'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringContainsString('NUMPAGES', $zip->getFromName('word/footer1.xml'));
        $zip->close();
    }

    public function test_report_without_check_redirects(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('norm-control.download'))
            ->assertRedirect(route('norm-control.index'))
            ->assertSessionHasErrors('document');
    }

    public function test_results_page_has_report_note_and_docx_button(): void
    {
        $this->check($this->document([$this->p(self::BODY)]))
            ->assertSee('Отриманий файл звіту слід надіслати керівнику проєкту.')
            ->assertSee('Завантажити звіт (.docx)')
            ->assertDontSee('Наразі це базова перевірка');
    }

    // --- Автовиправлення помилок (Преміум) ---

    public function test_fix_button_is_locked_without_premium(): void
    {
        Storage::fake('local');

        $this->check($this->document([$this->p(self::BODY)], ['sz' => 24, 'line' => 240, 'jc' => 'left', 'ind' => 0]))
            ->assertSee('Виправити помилки')
            ->assertSee('Для отримання статусу «Преміум» зверніться до адміністратора', false)
            ->assertSee('@Barabolikov')
            ->assertDontSee(route('norm-control.fixed'));

        $this->get(route('norm-control.fixed'))->assertRedirect(route('norm-control.index'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_premium_user_downloads_fixed_document(): void
    {
        Storage::fake('local');
        $user = $this->userWithRole(User::ROLE_PREMIUM);
        $sectPr = '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1134" w:right="567" w:bottom="1134" w:left="1134"/></w:sectPr>';
        $doc = $this->document(
            [$this->p(self::BODY), $this->p(self::BODY, '', '<w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>'), $this->p('1.1 Загальні відомості.'), $this->p('ВИСНОВКИ', '<w:jc w:val="left"/>')],
            ['sz' => 24, 'line' => 240, 'jc' => 'left', 'ind' => 0],
            ['sectPr' => $sectPr],
        );

        $this->upload($user, $doc)->assertOk()
            ->assertSee(route('norm-control.fixed'))
            ->assertSee('Сервіс виправив у вашій роботі');

        $response = $this->actingAs($user)->get(route('norm-control.fixed'));
        $response->assertOk();
        $this->assertStringContainsString(rawurlencode('robota-виправлено.docx'), $response->headers->get('Content-Disposition'));

        $fixedPath = tempnam(sys_get_temp_dir(), 'fixed');
        file_put_contents($fixedPath, $response->streamedContent());
        $before = array_column($this->check($doc)->viewData('actionableErrors'), 'type');
        $after = array_column($this->check($fixedPath)->viewData('actionableErrors'), 'type');

        // Помилки, які виправляє сервіс, були — і зникли
        foreach (['Форматування (Шрифт)', 'Форматування (Гарнітура)', 'Форматування (Інтервал)', 'Форматування (Вирівнювання)',
            'Форматування (Абзац)', 'Форматування (Сторінка)', 'Форматування (Поля)', 'Оформлення (Заголовки)', 'Оформлення (Нова сторінка)'] as $type) {
            $this->assertContains($type, $before);
            $this->assertNotContains($type, $after, "Лишилася помилка типу «{$type}»");
        }
    }

    public function test_new_check_replaces_previous_fixed_document(): void
    {
        Storage::fake('local');
        $user = $this->userWithRole(User::ROLE_PREMIUM);
        $doc = $this->document([$this->p(self::BODY)], ['sz' => 24, 'line' => 240, 'jc' => 'left', 'ind' => 0]);

        $this->upload($user, $doc)->assertOk();
        $this->upload($user, $doc)->assertOk();

        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    // --- Підписи рисунків і таблиць, посилання на них ---

    private function figure(): string
    {
        return '<w:p><w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"/></w:drawing></w:r></w:p>';
    }

    private function tableXml(): string
    {
        return '<w:tbl><w:tblPr/><w:tr><w:tc>'.$this->p('Модуль обліку').'</w:tc></w:tr></w:tbl>';
    }

    public function test_correct_captions_and_references_pass(): void
    {
        $response = $this->check($this->document([
            $this->p('Загальну схему системи показано на рисунку 1.1, а модулі наведено в таблиці 1.1.'),
            $this->figure(),
            $this->p('Рисунок 1.1 – Схема системи', '<w:jc w:val="center"/>'),
            $this->p('Таблиця 1.1 – Перелік модулів'),
            $this->tableXml(),
            // Речення, що починається словом «Таблиця», — не підпис, а посилання
            $this->p('Таблиця 1.1 містить перелік модулів, що розробляються в межах роботи.'),
        ]));

        $this->assertSame([], $this->errorsOfType($response, 'Оформлення (Підписи)'));
        $this->assertSame([], $this->errorsOfType($response, 'Посилання на рисунки й таблиці'));
    }

    public function test_caption_format_errors(): void
    {
        $response = $this->check($this->document([
            $this->p('Див. рис. 1.2.1 та табл. 1.1.'),
            $this->figure(),
            $this->p('Рис. 1.2.1. Схема системи.'),
            $this->tableXml(),
            $this->p('Таблиця 1.1 - Модулі'),
        ]));

        $this->assertSame([
            'Пишіть «Рисунок» повністю; номер складається з номера розділу й порядкового номера (напр. 1.2), а не 1.2.1; після номера крапку не ставлять; між номером і назвою потрібне тире: «Рисунок 1.2 – Назва»; у кінці назви крапку не ставлять',
            'Між номером і назвою ставлять тире «–», а не дефіс; назву таблиці розміщують над таблицею',
        ], array_slice($this->errorsOfType($response, 'Оформлення (Підписи)'), 0, 2));
    }

    public function test_caption_numbering_and_missing_references(): void
    {
        $response = $this->check($this->document([
            $this->p('Інтерфейс показано на рис. 1.1 і рис. 1.5, а головне вікно описано нижче.'),
            $this->figure(), $this->p('Рисунок 1.1 – Вікно входу'),
            $this->figure(), $this->p('Рисунок 1.1 – Головне вікно'),
            $this->figure(), $this->p('Рисунок 1.3 – Налаштування'),
        ]));

        $this->assertSame([
            'Номери рисунків повторюються: 1.1',
            'У нумерації рисунків пропущено: 1.2',
        ], $this->errorsOfType($response, 'Оформлення (Підписи)'));
        $this->assertSame([
            'Немає посилань у тексті на рисунки: 1.3',
            'Посилання на рисунки, яких немає в роботі: 1.5',
        ], $this->errorsOfType($response, 'Посилання на рисунки й таблиці'));
    }

    // --- Додатки ---

    private function appendix(string $heading, ?string $title = 'Лістинг програми'): array
    {
        return array_filter([$this->pageBreak(), $this->p($heading, '<w:jc w:val="center"/>'), $title ? $this->p($title, '<w:jc w:val="center"/>') : null]);
    }

    public function test_correct_appendices_pass(): void
    {
        $response = $this->check($this->document([
            $this->p('Код програми наведено в додатку А, а результати тестів — у додатках Б–В.'),
            ...$this->appendix('ДОДАТОК А'),
            ...$this->appendix('ДОДАТОК Б'),
            ...$this->appendix('ДОДАТОК В. Результати тестування', null),
        ]));

        $this->assertSame([], $this->errorsOfType($response, 'Додатки'));
    }

    public function test_appendix_errors(): void
    {
        $response = $this->check($this->document([
            $this->p('Код програми наведено в додатку А.'),
            ...$this->appendix('ДОДАТОК А'),
            ...$this->appendix('ДОДАТОК 2'),
            ...$this->appendix('ДОДАТОК З'),
            ...$this->appendix('ДОДАТОК Е', null),
        ]));

        $this->assertSame([
            'Додатки позначають великими літерами (А, Б, В…), а не цифрами',
            'Літеру «З» для позначення додатків не використовують (не можна: Ґ, Є, З, І, Ї, Й, О, Ч, Ь)',
            'Порушено послідовність: очікується «ДОДАТОК Г», а не «ДОДАТОК Е»',
            'Додаток не має назви (наступний рядок після «ДОДАТОК …»)',
            'Немає посилань у тексті на додатки 2, З, Е',
        ], $this->errorsOfType($response, 'Додатки'));
    }

    // --- Формули ---

    private function formula(string $math, string $number = ''): string
    {
        $num = $number !== '' ? '<w:r><w:tab/><w:t>'.$number.'</w:t></w:r>' : '';

        return '<w:p><m:oMath xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"><m:r><m:t>'.$math.'</m:t></m:r></m:oMath>'.$num.'</w:p>';
    }

    public function test_correct_formulas_pass(): void
    {
        $response = $this->check($this->document([
            $this->p('Енергію обчислюють за формулою (2.1), а потужність — згідно з (2.2).'),
            $this->formula('E=m·c²', '(2.1)'),
            $this->formula('P=A/t', '(2.2)'),
            // Формула всередині речення — не винесена, номер не потрібен
            '<w:p><w:r><w:t>Тут x = </w:t></w:r><m:oMath xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"><m:r><m:t>a+b</m:t></m:r></m:oMath><w:r><w:t> — сума двох доданків у рядку тексту.</w:t></w:r></w:p>',
        ]));

        $this->assertSame([], $this->errorsOfType($response, 'Формули'));
    }

    public function test_formula_errors(): void
    {
        $response = $this->check($this->document([
            $this->p('Розрахунок виконано за формулою (2.1).'),
            $this->formula('E=m·c²', '(2.1)'),
            $this->formula('P=A/t', '2.2'),
            $this->formula('F=m·a'),
        ]));

        $this->assertSame([
            'Номер формули беруть у круглі дужки: (2.2)',
            'Формули без номера: 1 з 3',
            'Немає посилань у тексті на формули: (2.2)',
        ], $this->errorsOfType($response, 'Формули'));
    }

    // --- Оформлення списку джерел (ДСТУ 8302:2015) ---

    public function test_reference_entries_format(): void
    {
        $response = $this->check($this->document([
            $this->p('Огляд підходів наведено в [1–6].'),
            $this->pageBreak(),
            $this->p('СПИСОК ВИКОРИСТАНИХ ДЖЕРЕЛ', '<w:jc w:val="center"/>'),
            $this->p('Шелдон Р. SQL. Базовий курс. Київ : Діалектика, 2019. 1024 с.'),
            $this->p('Android Developers. Guide to app architecture. URL: https://developer.android.com/topic/architecture (дата звернення: 06.04.2026).'),
            $this->p('https://laravel.com/docs'),
            $this->p('Laravel documentation. https://laravel.com/docs/12.x'),
            $this->p('Іваненко І. І. Основи програмування. Київ : Освіта. 300 с.'),
            $this->p('Петренко П. П. Бази даних. Львів : Світ, 2018'),
        ]));

        $this->assertSame([
            'Запис складається лише з посилання: №3',
            'Перед електронною адресою немає «URL:»: №4',
            'Для електронного ресурсу не вказано дату звернення: №4',
            'Не вказано рік видання: №4, 5',
            'Не вказано кількість сторінок (книга) або діапазон сторінок (стаття): №6',
            'Опис не закінчується крапкою: №4, 6',
        ], $this->errorsOfType($response, 'Список джерел'));
    }

    // --- Рамка й основний напис ---

    /** Рамка ЄСКД: група фігур з текстовими полями, прив'язана до абзацу ЗМІСТ */
    private function framedContents(array $items): string
    {
        $boxes = implode('', array_map(fn ($t) => '<v:shape><v:textbox><w:txbxContent><w:p><w:r><w:t>'.$t.'</w:t></w:r></w:p></w:txbxContent></v:textbox></v:shape>', $items));

        return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:pict><v:group>'.$boxes.'</v:group></w:pict></w:r><w:r><w:t>ЗМІСТ</w:t></w:r></w:p>';
    }

    private function thesis(?array $frame): string
    {
        return $this->document([$this->p(self::BODY)], self::GOOD_STYLE, ['intro' => array_filter([
            $this->p('КУРСОВА РОБОТА'),
            $this->p('на тему: «Система обліку кадрів»'),
            $this->p('Виконав студент 4 курсу, 741 групи'),
            $this->p('Петренко Іван Олегович'),
            $this->p('(прізвище та ініціали)'),
            $this->p('Керівник: Коваленко О. М.'),
            $this->p('м. Конотоп, 2026 рік'),
            $this->pageBreak(),
            $frame === null ? $this->p('ЗМІСТ', '<w:jc w:val="center"/>') : $this->framedContents($frame),
            $this->pageBreak(),
            $this->p('ВСТУП', '<w:jc w:val="center"/>'),
        ])]);
    }

    private const FRAME = ['Змн.', 'Арк.', '№ докум.', 'Підпис', 'Дата', 'КР.121.741.26.ПЗ', 'Розро б.', 'Петренко І.О.', 'Перевір.', 'Коваленко О.М.',
        'Реценз.', 'Н. Контр.', 'Коваленко О.М.', 'Затверд.', '« Система обліку кадрів »', 'Літ.', 'Аркушів', '4 0', 'КІПФК СумДУ'];

    public function test_correct_frame_passes(): void
    {
        $this->assertSame([], $this->errorsOfType($this->check($this->thesis(self::FRAME)), 'Оформлення (Рамка)'));
    }

    public function test_frame_errors(): void
    {
        $frame = ['Змн.', 'Арк.', '№ докум.', 'Підпис', 'Дата', 'Схема',     // немає шифру
            'Розроб.', 'Петренко О.І.',                                     // переставлені ініціали
            'Перевір.', 'Коваленко О.М.', 'Реценз.',
            'Н. Контр.',                                                   // не заповнено
            'Затверд.', 'Інша тема роботи',                                // не та тема
            'Літ.', 'Аркушів', 'КІПФК СумДУ'];                              // немає кількості аркушів

        $this->assertSame([
            'В основному написі не заповнено «Н. контр.»',
            'Розробник в основному написі («Петренко О.І.») не відповідає студенту на титулці («Петренко Іван Олегович»)',
            'Назва роботи в основному написі не збігається з темою на титулці',
            'В основному написі не вказано кількість аркушів («Аркушів»)',
            'В основному написі немає позначення (шифру) документа',
        ], $this->errorsOfType($this->check($this->thesis($frame)), 'Оформлення (Рамка)'));
    }

    public function test_missing_frame_in_thesis_and_not_in_other_documents(): void
    {
        $this->assertSame(['Не знайдено рамку з основним написом (форма 2) на аркуші ЗМІСТ'], $this->errorsOfType($this->check($this->thesis(null)), 'Оформлення (Рамка)'));
        $this->assertSame([], $this->errorsOfType($this->check($this->document([$this->p(self::BODY)])), 'Оформлення (Рамка)'));
    }

    // --- Денний ліміт для студентів ---

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($role, 'web'));

        return $user;
    }

    private function upload(User $user, string $docxPath)
    {
        return $this->actingAs($user)->post(route('norm-control.process'), [
            'document' => new UploadedFile($docxPath, 'robota.docx', null, null, true), ...self::CAPTCHA,
        ]);
    }

    public function test_student_can_check_one_document_per_day(): void
    {
        $student = $this->userWithRole(User::ROLE_STUDENT);
        $doc = $this->document([$this->p(self::BODY)]);

        $this->upload($student, $doc)->assertOk()->assertViewHas('report');
        $this->assertSame(0, $student->remainingChecksToday());

        $this->from(route('norm-control.index'))->upload($student, $doc)
            ->assertRedirect(route('norm-control.index'))
            ->assertSessionHasErrors('document');
        $this->assertSame(1, $student->normControlChecks()->count());

        $this->actingAs($student)->get(route('norm-control.index'))
            ->assertSee('Ліміт перевірок на сьогодні вичерпано');
    }

    public function test_limit_resets_at_midnight_kyiv_time(): void
    {
        $student = $this->userWithRole(User::ROLE_STUDENT);
        $doc = $this->document([$this->p(self::BODY)]);

        // 23:30 за Києвом (21:30 UTC взимку) — перевірка «сьогодні»
        $this->travelTo(Carbon::parse('2026-11-15 23:30', User::LIMIT_TIMEZONE));
        $this->upload($student, $doc)->assertOk();
        $this->assertSame(0, $student->remainingChecksToday());

        // 00:10 наступного дня за Києвом — новий ліміт, хоча за UTC ще 15-те число
        $this->travelTo(Carbon::parse('2026-11-16 00:10', User::LIMIT_TIMEZONE));
        $this->assertSame(1, $student->remainingChecksToday());
        $this->upload($student, $doc)->assertOk();
    }

    public function test_broken_file_does_not_use_up_the_limit(): void
    {
        $student = $this->userWithRole(User::ROLE_STUDENT);

        // Справжній zip, але без word/document.xml
        $broken = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($broken, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/styles.xml', '<x/>');
        $zip->close();

        $this->from(route('norm-control.index'))->upload($student, $broken)->assertSessionHasErrors('document');

        $this->assertSame(0, $student->normControlChecks()->count());
        $this->assertSame(1, $student->remainingChecksToday());
    }

    public function test_teacher_and_admin_have_no_limit(): void
    {
        $doc = $this->document([$this->p(self::BODY)]);

        foreach ([User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_PREMIUM] as $role) {
            $user = $this->userWithRole($role);
            // Навіть якщо в нього лишилася роль студента
            $user->assignRole(Role::findOrCreate(User::ROLE_STUDENT, 'web'));

            $this->assertNull($user->remainingChecksToday());
            $this->upload($user, $doc)->assertOk();
            $this->upload($user, $doc)->assertOk();
            $this->assertSame(2, $user->normControlChecks()->count());
        }
    }

    // --- допоміжні методи ---

    private function check(string $docxPath)
    {
        $file = new UploadedFile($docxPath, 'robota.docx', null, null, true);

        return $this->actingAs(User::factory()->create())
            ->post(route('norm-control.process'), ['document' => $file, ...self::CAPTCHA]);
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
