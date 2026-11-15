<?php

namespace App\Services\NormControl;

/**
 * Додатки (ДСТУ 3008:2015): «ДОДАТОК А», «ДОДАТОК Б»… великими українськими літерами
 * без Ґ, Є, З, І, Ї, Й, О, Ч, Ь; кожен має назву; на кожен є посилання в тексті.
 */
class AppendixChecks
{
    /** Допустимі літери в порядку використання */
    public const LETTERS = ['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж', 'И', 'К', 'Л', 'М', 'Н', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ш', 'Щ', 'Ю', 'Я'];

    private const FORBIDDEN = ['Ґ', 'Є', 'З', 'І', 'Ї', 'Й', 'О', 'Ч', 'Ь'];

    /** @var list<array{letter: string, heading: string}> */
    private array $appendices = [];

    /** @var array<string, true> */
    private array $mentions = [];

    /** Додаток, для якого ще чекаємо рядок з назвою */
    private ?string $awaitingTitle = null;

    private array $errors = [];

    /** Заголовок «ДОДАТОК …» (рядок великими літерами). $rest — те, що після слова ДОДАТОК. */
    public function heading(string $line, string $rest): void
    {
        $this->awaitingTitle = null;
        $rest = trim($rest);
        $rawToken = preg_split('/\s+/u', $rest)[0] ?? '';
        $hasTitle = trim(mb_substr($rest, mb_strlen($rawToken))) !== '';
        // «ДОДАТОК А.», «ДОДАТОК А:» — розділовий знак після літери не є частиною позначення
        $token = rtrim($rawToken, '.:,;–—-');
        $location = mb_substr($line, 0, 60);

        if ($token === '') {
            $this->error('Додаток не позначено літерою: «ДОДАТОК А»', $location);

            return;
        }
        if (preg_match('/^\d+$/', $token)) {
            $this->error('Додатки позначають великими літерами (А, Б, В…), а не цифрами', $location);
        } elseif (preg_match('/^[A-Z]$/', $token)) {
            $this->error('Літера «'.$token.'» латинська — використовуйте українську', $location);
        } elseif (in_array($token, self::FORBIDDEN, true)) {
            $this->error('Літеру «'.$token.'» для позначення додатків не використовують (не можна: '.implode(', ', self::FORBIDDEN).')', $location);
        } elseif (! in_array($token, self::LETTERS, true)) {
            $this->error('Додаток має позначатися однією великою літерою: «ДОДАТОК А»', $location);
        }

        $expected = self::LETTERS[count($this->appendices)] ?? null;
        if (in_array($token, self::LETTERS, true) && $expected !== null && $token !== $expected) {
            $this->error("Порушено послідовність: очікується «ДОДАТОК {$expected}», а не «ДОДАТОК {$token}»", $location);
        }

        $this->appendices[] = ['letter' => $token, 'heading' => $location];
        if (! $hasTitle) {
            $this->awaitingTitle = $location;
        }
    }

    /** Наступний непорожній рядок після заголовка має бути назвою додатка. */
    public function line(string $line, bool $isHeadingOrCaption): void
    {
        if ($this->awaitingTitle === null) {
            return;
        }
        if ($isHeadingOrCaption) {
            $this->error('Додаток не має назви (наступний рядок після «ДОДАТОК …»)', $this->awaitingTitle);
        }
        $this->awaitingTitle = null;
    }

    /** Посилання в тексті: «додаток А», «(див. додаток Б)», «у додатках А–В» */
    public function addText(string $text): void
    {
        $l = 'А-ЯҐЄІЇ';
        if (! preg_match_all("/(?<!\p{L})(?i:додат\p{L}*)\s+([$l])(?!\p{L})((?:\s*(?:,|і|та|й|–|—|-)\s*[$l](?!\p{L}))*)/u", $text, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as [, $first, $tail]) {
            $this->mentions[$first] = true;
            preg_match_all("/([–—-])?\s*([$l])(?!\p{L})/u", $tail, $parts, PREG_SET_ORDER);
            $prev = $first;
            foreach ($parts as [, $dash, $letter]) {
                if ($dash !== '' && ($a = array_search($prev, self::LETTERS, true)) !== false && ($b = array_search($letter, self::LETTERS, true)) !== false) {
                    foreach (array_slice(self::LETTERS, $a, $b - $a + 1) as $x) {
                        $this->mentions[$x] = true;
                    }
                }
                $this->mentions[$letter] = true;
                $prev = $letter;
            }
        }
    }

    public function count(): int
    {
        return count($this->appendices);
    }

    /** @return list<array{type: string, message: string, location: string, context: string}> */
    public function errors(): array
    {
        $errors = $this->errors;
        if ($this->awaitingTitle !== null) {
            $errors[] = ['type' => 'Додатки', 'message' => 'Додаток не має назви (наступний рядок після «ДОДАТОК …»)', 'location' => $this->awaitingTitle, 'context' => 'Під заголовком «ДОДАТОК А» з нового рядка пишуть назву додатка'];
        }

        $unreferenced = array_values(array_filter(array_column($this->appendices, 'letter'), fn ($letter) => ! isset($this->mentions[$letter])));
        if ($unreferenced) {
            $errors[] = [
                'type' => 'Додатки',
                'message' => 'Немає посилань у тексті на '.(count($unreferenced) === 1 ? 'додаток ' : 'додатки ').implode(', ', $unreferenced),
                'location' => 'Основний текст',
                'context' => 'На кожен додаток посилаються в тексті: «(див. додаток А)»',
            ];
        }

        return $errors;
    }

    private function error(string $message, string $location): void
    {
        $this->errors[] = ['type' => 'Додатки', 'message' => $message, 'location' => $location, 'context' => 'Позначення: «ДОДАТОК А», «ДОДАТОК Б»… (ДСТУ 3008:2015)'];
    }
}
