<?php

namespace App\Services\NormControl;

/**
 * Формули (ДСТУ 3008:2015): винесені в окремий рядок формули нумерують у межах розділу
 * арабськими цифрами в круглих дужках біля правого поля — «(2.1)»; на номери посилаються в тексті.
 */
class FormulaChecks
{
    /** @var list<array{number: ?string, location: string}> */
    private array $formulas = [];

    /** @var array<string, true> */
    private array $mentions = [];

    private array $errors = [];

    /**
     * Чи абзац — винесена формула: Word Equation (m:oMath) або об'єкт Equation/MathType,
     * а звичайного тексту поруч майже немає (не враховуючи номера).
     */
    public static function isDisplayFormula(string $paragraphXml, string $text): bool
    {
        $hasMath = str_contains($paragraphXml, '<m:oMath') || (bool) preg_match('/ProgID="Equation/', $paragraphXml);
        if (! $hasMath) {
            return false;
        }
        $withoutNumber = trim(preg_replace('/\(?\s*\d+(?:\.\d+)*\s*\)?\s*$/u', '', $text));

        return str_contains($paragraphXml, '<m:oMathPara') || mb_strlen($withoutNumber) <= 3;
    }

    /** Номер формули з тексту абзацу або математичного тексту: «(2.1)»; без дужок — як є. */
    public static function numberIn(string $paragraphXml, string $text): ?string
    {
        // Номер — у звичайному тексті рядка (після табуляції) або всередині формули («…#(2.1)»)
        preg_match_all('/<m:t(?:\s[^>]*)?>(.*?)<\/m:t>/s', $paragraphXml, $m);
        foreach ([$text, html_entity_decode(implode('', $m[1]))] as $candidate) {
            if (preg_match('/\(\s*(\d+(?:\.\d+)*)\s*\)\s*$/u', trim($candidate), $n)) {
                return '('.$n[1].')';
            }
        }
        if (preg_match('/(?<![\d.])(\d+\.\d+)\s*$/u', $text, $n)) {
            return $n[1]; // номер без дужок
        }

        return null;
    }

    public function formula(?string $number, string $location): void
    {
        $this->formulas[] = ['number' => $number, 'location' => $location];

        if ($number !== null && ! str_starts_with($number, '(')) {
            $this->errors[] = ['type' => 'Формули', 'message' => "Номер формули беруть у круглі дужки: ($number)", 'location' => $location, 'context' => 'Номер ставлять біля правого поля: «(2.1)»'];
        }
    }

    /** Посилання на формули: «(2.1)», «формулою 2.1», «у формулах (2.1)–(2.3)» */
    public function addText(string $text): void
    {
        // Клітинка з самим номером формули — не посилання
        if (preg_match('/^\(\s*\d+(?:\.\d+)*\s*\)$/u', trim($text))) {
            return;
        }
        if (preg_match_all('/\((\d+\.\d+)\)/u', $text, $m)) {
            foreach ($m[1] as $n) {
                $this->mentions[$n] = true;
            }
        }
        if (preg_match_all('/(?<!\p{L})формул\p{L}*\s+\(?(\d+\.\d+)/iu', $text, $m)) {
            foreach ($m[1] as $n) {
                $this->mentions[$n] = true;
            }
        }
    }

    public function count(): int
    {
        return count($this->formulas);
    }

    /** @return list<array{type: string, message: string, location: string, context: string}> */
    public function errors(): array
    {
        $errors = $this->errors;

        $unnumbered = array_values(array_filter($this->formulas, fn ($f) => $f['number'] === null));
        if ($unnumbered) {
            $errors[] = [
                'type' => 'Формули',
                'message' => 'Формули без номера: '.count($unnumbered).' з '.count($this->formulas),
                'location' => $unnumbered[0]['location'],
                'context' => 'Винесені формули нумерують у межах розділу: «(2.1)» біля правого поля',
            ];
        }

        $numbers = array_values(array_filter(array_map(fn ($f) => $f['number'] ? trim($f['number'], '()') : null, $this->formulas)));

        // Нумерація в межах розділу
        $duplicates = array_keys(array_filter(array_count_values($numbers), fn ($c) => $c > 1));
        if ($duplicates) {
            $errors[] = ['type' => 'Формули', 'message' => 'Номери формул повторюються: '.implode(', ', $duplicates), 'location' => 'Формули', 'context' => 'Нумерація в межах розділу: (2.1), (2.2)…'];
        }
        $deep = array_filter($numbers, fn ($n) => substr_count($n, '.') > 1);
        if ($deep) {
            $errors[] = ['type' => 'Формули', 'message' => 'Номер формули — номер розділу й порядковий номер (2.1), а не '.implode(', ', array_slice($deep, 0, 5)), 'location' => 'Формули', 'context' => 'Нумерація в межах розділу'];
        }

        $unreferenced = array_values(array_unique(array_filter($numbers, fn ($n) => ! isset($this->mentions[$n]))));
        if ($unreferenced) {
            $errors[] = [
                'type' => 'Формули',
                'message' => 'Немає посилань у тексті на формули: '.implode(', ', array_map(fn ($n) => "($n)", array_slice($unreferenced, 0, 15))),
                'location' => 'Основний текст',
                'context' => 'На номер формули посилаються в тексті: «за формулою (2.1)»',
            ];
        }

        return $errors;
    }
}
