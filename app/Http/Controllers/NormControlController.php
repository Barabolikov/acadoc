<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use ZipArchive;

class NormControlController extends Controller
{
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
            $zip->close();

            // 1. Базова статистика та ініціалізація
            $paragraphsCount = substr_count($xmlContent, '<w:p');
            $rawText = '';
            if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/', $xmlContent, $matches)) {
                $rawText = implode('', $matches[1]);
            }
            $charsCount = mb_strlen(strip_tags($rawText));
            $fonts = [];
            if (preg_match_all('/w:ascii="([^"]+)"/', $xmlContent, $fontMatches)) {
                $fonts = array_unique($fontMatches[1]);
            }

            $actionableErrors = [];
            $lines = [];
            $currentSection = 'Початок документа';
            $isMainContent = false;
            $studentName = 'Не знайдено';
            $supervisorName = 'Не знайдено';
            $checkDate = date('d.m.Y H:i');

            $structure = [
                'hasTitle' => false, 'taskIndex' => -1, 'tocIndex' => -1,
                'introIndex' => -1, 'conclusionsIndex' => -1, 'referencesIndex' => -1,
                'appendicesIndex' => -1, 'isOrderValid' => false,
                'hasWrongConclusionWord' => false,
                'hasWrongReferencesWord' => false,
                'usesPronounI' => false,
            ];

            $physicalTablesCount = substr_count($xmlContent, '<w:tbl');
            $physicalImagesCount = substr_count($xmlContent, '<w:drawing') + substr_count($xmlContent, '<v:shape');
            $foundTableCaptions = 0;
            $foundImageCaptions = 0;

            if (preg_match('/\bя\b/iu', strip_tags($rawText))) {
                $structure['usesPronounI'] = true;
            }

            // 2. Глобальні перевірки (Поля та Розмір сторінки)
            if (preg_match('/<w:pgMar w:top="(\d+)" w:right="(\d+)" w:bottom="(\d+)" w:left="(\d+)"/U', $xmlContent, $marMatch)) {
                $left = round($marMatch[4] / 56.7);
                if ($left < 29 || $left > 31) {
                    $actionableErrors[] = ['type' => 'Форматування (Поля)', 'message' => "Ліве поле: $left мм (треба 30 мм)", 'location' => 'Налаштування сторінки', 'context' => 'Макет -> Поля'];
                }
            }

            if (preg_match('/<w:pgSz w:w="(\d+)" w:h="(\d+)"/U', $xmlContent, $szMatch)) {
                $width = round($szMatch[1] / 56.7);
                if (abs($width - 210) > 2) {
                    $actionableErrors[] = ['type' => 'Форматування (Сторінка)', 'message' => "Формат не А4", 'location' => 'Глобально', 'context' => 'Макет -> Розмір'];
                }
            }

            // 3. Поцикловий аналіз
            $rawParagraphs = explode('<w:p ', $xmlContent);
            foreach ($rawParagraphs as $rawP) {
                if (preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/', $rawP, $tMatches)) {
                    $lineText = trim(strip_tags(implode('', $tMatches[1])));
                    if (empty($lineText)) continue;

                    $upperLine = mb_strtoupper($lineText, 'UTF-8');
                    $lines[] = $upperLine;

                    // Пошук імен на титулці
                    if (!$isMainContent) {
                        if (preg_match('/Виконав:\s*(.*)/iu', $lineText, $m)) $studentName = trim($m[1]);
                        if (preg_match('/Керівник:\s*(.*)/iu', $lineText, $m)) $supervisorName = trim($m[1]);
                    }

                    // Радар заголовків
                    $isHeadingLine = false;
                    if (($upperLine === $lineText && mb_strlen($lineText) > 3 && mb_strlen($lineText) < 100) || preg_match('/^\d+\.\d+/', $lineText)) {
                        $currentSection = mb_substr($lineText, 0, 60);
                        $isHeadingLine = true;
                        if (str_contains($upperLine, 'ВСТУП')) $isMainContent = true;
                        if (str_contains($upperLine, 'СПИСОК ВИКОРИСТАНИХ ДЖЕРЕЛ') || str_contains($upperLine, 'ДОДАТОК')) $isMainContent = false;
                    }

                    // Перевірка ГОЛОВНИХ заголовків
                    $mainHeadings = ['ЗМІСТ', 'ВСТУП', 'ВИСНОВКИ', 'СПИСОК ВИКОРИСТАНИХ ДЖЕРЕЛ', 'ДОДАТКИ'];
                    if (in_array($upperLine, $mainHeadings) || preg_match('/^РОЗДІЛ\s+\d+/iu', $lineText)) {
                        if ($lineText !== $upperLine) {
                            $actionableErrors[] = ['type' => 'Оформлення (Заголовки)', 'message' => 'Треба ВЕЛИКИМИ літерами', 'location' => $currentSection, 'context' => $lineText];
                        }
                        if (!preg_match('/<w:jc[^>]*w:val="center"/i', $rawP)) {
                            $actionableErrors[] = ['type' => 'Форматування (Вирівнювання)', 'message' => 'Заголовок має бути по центру', 'location' => $currentSection, 'context' => $lineText];
                        }
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
                    if ($isMainContent && !$isHeadingLine && mb_strlen($lineText) > 30) {
                        if (preg_match('/<w:sz w:val="(\d+)"/i', $rawP, $szMatch) && (int)$szMatch[1] !== 28) {
                            $actionableErrors[] = ['type' => 'Форматування (Шрифт)', 'message' => 'Розмір не 14 пт', 'location' => $currentSection, 'context' => mb_substr($lineText, 0, 50)];
                        }
                        if (preg_match('/<w:ind[^>]*w:firstLine="(\d+)"/i', $rawP, $indMatch)) {
                            $ind = (int)$indMatch[1];
                            if ($ind < 680 || $ind > 740) $actionableErrors[] = ['type' => 'Форматування (Абзац)', 'message' => 'Відступ не 1.25 см', 'location' => $currentSection, 'context' => mb_substr($lineText, 0, 50)];
                        }
                        if (preg_match('/<w:spacing[^>]*w:line="(\d+)"/i', $rawP, $spcMatch) && (int)$spcMatch[1] < 350) {
                            $actionableErrors[] = ['type' => 'Форматування (Інтервал)', 'message' => 'Інтервал не 1.5', 'location' => $currentSection, 'context' => mb_substr($lineText, 0, 50)];
                        }
                        if (preg_match('/<w:jc[^>]*w:val="([^"]+)"/i', $rawP, $jcMatch) && !in_array($jcMatch[1], ['both', 'distribute'])) {
                            $actionableErrors[] = ['type' => 'Форматування (Вирівнювання)', 'message' => 'Не по ширині', 'location' => $currentSection, 'context' => mb_substr($lineText, 0, 50)];
                        }
                    }
                }
            }

            // 4. Фінальні перевірки
            if (!preg_match('/<w:instrText[^>]*>\s*TOC/i', $xmlContent)) {
                $actionableErrors[] = ['type' => 'Оформлення', 'message' => 'Зміст не автоматичний', 'location' => 'ЗМІСТ', 'context' => 'Вкладка Посилання'];
            }
            if ($physicalTablesCount > $foundTableCaptions) {
                $actionableErrors[] = ['type' => 'Відсутні підписи', 'message' => "Є непідписані таблиці ($physicalTablesCount vs $foundTableCaptions)", 'location' => 'Глобально', 'context' => 'Перевірте назви таблиць'];
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
                if ($structure['conclusionsIndex'] === -1 && str_contains($line, 'ВИСНОВКИ')) $structure['conclusionsIndex'] = $idx;
                if ($structure['referencesIndex'] === -1 && str_contains($line, 'СПИСОК ВИКОРИСТАНИХ ДЖЕРЕЛ')) $structure['referencesIndex'] = $idx;
                if ($structure['appendicesIndex'] === -1 && str_contains($line, 'ДОДАТОК')) $structure['appendicesIndex'] = $idx;
            }
            if ($structure['taskIndex'] !== -1 && $structure['tocIndex'] > $structure['taskIndex'] && $structure['introIndex'] > $structure['tocIndex']) $structure['isOrderValid'] = true;

            return view('norm-control.results', [
                'errorsCount' => count($actionableErrors),
                'fileName' => $fileName,
                'paragraphsCount' => $paragraphsCount,
                'charsCount' => $charsCount,
                'fonts' => $fonts,
                'structure' => $structure,
                'actionableErrors' => $actionableErrors,
                'tablesCount' => $physicalTablesCount,
                'imagesCount' => $physicalImagesCount,
                'studentName' => $studentName,
                'supervisorName' => $supervisorName,
                'checkDate' => $checkDate
            ]);
        }
        return back()->withErrors(['document' => 'Помилка архіву.']);
    }
    public function downloadPdf(Request $request)
    {
        // Отримуємо дані з сесії або запиту (для спрощення передамо через сесію)
        $data = $request->all();

        // Додаємо дату, якщо її немає
        $data['checkDate'] = date('d.m.Y H:i');
        $data['developer'] = 'Gemini AI Collaboration System';

        $pdf = \PDF::loadView('norm-control.pdf_report', $data);

        return $pdf->download('Звіт_нормоконтроль_' . date('d_m_Y') . '.pdf');
    }
}
