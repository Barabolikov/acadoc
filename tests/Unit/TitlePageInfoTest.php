<?php

namespace Tests\Unit;

use App\Services\NormControl\TitlePageInfo;
use PHPUnit\Framework\TestCase;

class TitlePageInfoTest extends TestCase
{
    public function test_extracts_typical_college_title_page(): void
    {
        $info = TitlePageInfo::fromLines([
            'МІНІСТЕРСТВО ОСВІТИ І НАУКИ УКРАЇНИ',
            'Відокремлений структурний підрозділ',
            '«Тестовий фаховий коледж СумДУ»',
            'Циклова комісія комп’ютерних та математичних дисциплін',
            'КУРСОВА РОБОТА',
            'з предмету: Об’єктно-орієнтоване програмування',
            '(назва дисципліни)',
            'на тему: «Розробка інформаційної системи бібліотеки»',
            'Виконав студент ІV курсу, 741 групи',
            'спеціальності 121 «Інженерія програмного забезпечення»',
            'Петренко Іван Олегович',
            '(прізвище та ініціали)',
            'Керівник: Коваленко О. М.',
            '(посада, вчене звання, науковий ступінь, прізвище та ініціали)',
            'Національна шкала ________________',
            'м. Конотоп, 2026 рік',
            // Сторінка завдання після титулки не повинна нічого перезаписати
            'Завдання на курсову роботу',
            'Виконав: Сидоренко А. А.',
        ]);

        $this->assertSame([
            'institution' => 'Тестовий фаховий коледж СумДУ',
            'workType' => 'Курсова робота',
            'discipline' => 'Об’єктно-орієнтоване програмування',
            'topic' => 'Розробка інформаційної системи бібліотеки',
            'student' => 'Петренко Іван Олегович',
            'course' => 'ІV',
            'group' => '741',
            'specialty' => '121 Інженерія програмного забезпечення',
            'supervisor' => 'Коваленко О. М.',
            'city' => 'Конотоп',
            'year' => '2026',
        ], $info);
    }

    public function test_topic_on_next_line_and_author_after_colon(): void
    {
        $info = TitlePageInfo::fromLines([
            'ДИПЛОМНА РОБОТА',
            'на тему:',
            '"Веб-застосунок для обліку оцінок"',
            'Виконала: Іваненко М. С.',
            'група КН-21',
            'Керівник: доцент Шевченко Т. В.',
            'Київ – 2025',
            'Київ, 2025 р.',
        ]);

        $this->assertSame('Дипломна робота', $info['workType']);
        $this->assertSame('Веб-застосунок для обліку оцінок', $info['topic']);
        $this->assertSame('Іваненко М. С.', $info['student']);
        $this->assertSame('КН-21', $info['group']);
        $this->assertSame('Шевченко Т. В.', $info['supervisor']);
        $this->assertSame('2025', $info['year']);
    }

    public function test_missing_data_stays_null(): void
    {
        $info = TitlePageInfo::fromLines(['Просто текст без титульної сторінки']);

        $this->assertNull($info['student']);
        $this->assertNull($info['year']);
        $this->assertNull($info['topic']);
    }
}
