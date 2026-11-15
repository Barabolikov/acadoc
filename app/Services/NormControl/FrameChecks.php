<?php

namespace App\Services\NormControl;

/**
 * Рамка й основний напис (ГОСТ 2.104, форма 2) на першому аркуші пояснювальної записки (ЗМІСТ).
 *
 * Рамку в Word роблять групою текстових полів (у тексті або в колонтитулі) чи таблицею в колонтитулі,
 * тож розбираємо написи по порядку: «Розроб.» → ПІБ, «Перевір.» → ПІБ, «Н. контр.» → ПІБ,
 * «Затверд.» → назва роботи, «Аркушів» → кількість, плюс позначення документа (шифр).
 */
class FrameChecks
{
    /** Мітки основного напису (без пробілів і крапок, у нижньому регістрі) => назва для повідомлень */
    private const LABELS = [
        'змн' => 'Змн.', 'арк' => 'Арк.', '№докум' => '№ докум.', 'підпис' => 'Підпис', 'дата' => 'Дата',
        'розроб' => 'Розроб.', 'перевір' => 'Перевір.', 'реценз' => 'Реценз.', 'нконтр' => 'Н. контр.',
        'затверд' => 'Затверд.', 'затв' => 'Затв.', 'літ' => 'Літ.', 'аркушів' => 'Аркушів', 'масштаб' => 'Масштаб', 'маса' => 'Маса',
    ];

    /** Шифр документа: «КР-121.23.741.26», «KP.ОК26.741.24.ПЗ», «КП.121.000 ПЗ» */
    private const CODE = '/^(?=.*\d)(?=.*[.\-])[A-ZА-ЯІЇЄҐ]{2,}[A-ZА-ЯІЇЄҐ0-9.\-\s]{4,}$/u';

    /**
     * @param  array<string, string>  $headerFooterParts  XML колонтитулів
     * @param  array{student: ?string, topic: ?string, workType: ?string}  $titlePage
     * @return list<array{type: string, message: string, location: string, context: string}>
     */
    public static function check(string $documentXml, array $headerFooterParts, array $titlePage): array
    {
        $items = self::frameItems($documentXml, $headerFooterParts);
        $keys = array_map([self::class, 'key'], $items);

        $isThesis = (bool) preg_match('/курсов|диплом|кваліфікац/iu', $titlePage['workType'] ?? '');
        if (! in_array('розроб', $keys, true)) {
            return $isThesis
                ? [self::error('Не знайдено рамку з основним написом (форма 2) на аркуші ЗМІСТ', 'ЗМІСТ', 'Основний напис містить: шифр, Розроб., Перевір., Н. контр., Затверд., Літ., Арк., Аркушів')]
                : [];
        }

        $errors = [];
        $valueAfter = function (string $label) use ($items, $keys): ?string {
            $i = array_search($label, $keys, true);
            $next = $items[$i + 1] ?? null;

            return $next !== null && ! isset(self::LABELS[self::key($next)]) && ! preg_match(self::CODE, $next) ? $next : null;
        };

        // Обов'язкові підписанти
        foreach (['розроб' => 'Розроб.', 'перевір' => 'Перевір.', 'нконтр' => 'Н. контр.'] as $key => $label) {
            if (! in_array($key, $keys, true)) {
                $errors[] = self::error("В основному написі немає рядка «{$label}»", 'Основний напис', 'Форма 2 за ГОСТ 2.104');
            } elseif ($valueAfter($key) === null) {
                $errors[] = self::error("В основному написі не заповнено «{$label}»", 'Основний напис', 'Вкажіть прізвище та ініціали');
            }
        }

        // Розроб. = студент з титульного аркуша
        $developer = $valueAfter('розроб');
        if ($developer && $titlePage['student'] && ! self::sameShortName($developer, $titlePage['student'])) {
            $errors[] = self::error("Розробник в основному написі («{$developer}») не відповідає студенту на титулці («{$titlePage['student']}»)", 'Основний напис', 'Перевірте прізвище та порядок ініціалів (Ім\'я, По батькові)');
        }

        // Назва роботи = тема з титульного аркуша
        $title = $valueAfter('затверд') ?? $valueAfter('затв');
        if ($title && $titlePage['topic'] && self::norm($title) !== self::norm($titlePage['topic'])) {
            $errors[] = self::error('Назва роботи в основному написі не збігається з темою на титулці', 'Основний напис', '«'.mb_substr(trim($title, ' «»"'), 0, 70).'» ≠ «'.mb_substr($titlePage['topic'], 0, 70).'»');
        }

        // Аркушів — кількість аркушів
        if (in_array('аркушів', $keys, true)) {
            $sheets = $valueAfter('аркушів');
            if ($sheets === null || ! preg_match('/^\d[\d\s]*$/u', $sheets)) {
                $errors[] = self::error('В основному написі не вказано кількість аркушів («Аркушів»)', 'Основний напис', 'Загальна кількість аркушів документа');
            }
        }

        // Позначення (шифр) документа
        $codes = array_values(array_unique(array_map(fn ($c) => preg_replace('/\s+/u', '', $c), array_filter($items, fn ($t) => preg_match(self::CODE, $t)))));
        if (! $codes) {
            $errors[] = self::error('В основному написі немає позначення (шифру) документа', 'Основний напис', 'Наприклад: «КР.121.741.24.ПЗ»');
        } elseif (count($codes) > 1) {
            $errors[] = self::error('Позначення документа в рамках різні: '.implode(', ', $codes), 'Основний напис', 'Шифр має бути однаковим на всіх аркушах');
        }

        return $errors;
    }

    /**
     * Написи рамки по порядку: текстові поля в тексті документа (без дублікату mc:Fallback)
     * і текст колонтитулів, що містять мітки основного напису.
     *
     * @return list<string>
     */
    private static function frameItems(string $documentXml, array $headerFooterParts): array
    {
        // Групи, у яких може бути рамка: кожна фігура/група фігур у тексті документа
        // (написи діаграм — окремі групи, тож вони не змішаються з основним написом)
        // і кожен колонтитул цілком (там рамку роблять і таблицею)
        $groups = [];
        $documentXml = preg_replace('/<mc:Fallback>.*?<\/mc:Fallback>/s', '', $documentXml);
        preg_match_all('/<w:drawing>.*?<\/w:drawing>|<w:pict>.*?<\/w:pict>/s', $documentXml, $shapes);
        array_push($groups, ...$shapes[0]);
        foreach ($headerFooterParts as $xml) {
            $groups[] = preg_replace('/<mc:Fallback>.*?<\/mc:Fallback>/s', '', $xml);
        }

        $items = [];
        foreach ($groups as $group) {
            preg_match_all('/<w:p[ >].*?<\/w:p>/s', $group, $paragraphs);
            $part = [];
            foreach ($paragraphs[0] as $p) {
                preg_match_all('/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $p, $t);
                $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(implode('', $t[1]))));
                if ($text !== '') {
                    $part[] = $text;
                }
            }
            if (array_intersect(array_map([self::class, 'key'], $part), ['розроб', 'змн', 'арк'])) {
                array_push($items, ...$part);
            }
        }

        return $items;
    }

    /** «Розро б .» → «розроб», «Н. Контр.» → «нконтр» */
    private static function key(string $text): string
    {
        return mb_strtolower(preg_replace('/[\s.]+/u', '', $text));
    }

    private static function norm(string $text): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }

    /** «Пишний В.О.» відповідає «Пишний Володимир Олексійович»; «Пишний О.В.» — ні */
    private static function sameShortName(string $short, string $full): bool
    {
        $words = preg_split('/[\s.]+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY);
        $shortWords = preg_split('/[\s.]+/u', trim($short), -1, PREG_SPLIT_NO_EMPTY);
        if (! $words || ! $shortWords) {
            return true;
        }

        // Прізвище — найдовше слово короткої форми (може стояти на початку або в кінці),
        // ініціали — решта слів у записаному порядку
        $lengths = array_map('mb_strlen', $shortWords);
        $surnameAt = array_search(max($lengths), $lengths, true);
        if (mb_strtolower($shortWords[$surnameAt]) !== mb_strtolower($words[0])) {
            return false;
        }
        unset($shortWords[$surnameAt]);
        $initials = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $shortWords));
        $expected = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 1)));

        // Порядок ініціалів у короткій формі: Ім'я, По батькові
        return $initials === '' || $expected === '' || mb_strtoupper($initials) === mb_strtoupper(mb_substr($expected, 0, mb_strlen($initials)));
    }

    private static function error(string $message, string $location, string $context): array
    {
        return ['type' => 'Оформлення (Рамка)', 'message' => $message, 'location' => $location, 'context' => $context];
    }
}
