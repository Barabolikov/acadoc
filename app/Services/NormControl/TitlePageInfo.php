<?php

namespace App\Services\NormControl;

/**
 * Дані з титульного аркуша: вид роботи, дисципліна, тема, студент, група, керівник, рік тощо.
 * Розрахований на типові титулки коледжів/університетів («Виконав студент 4 курсу, 741 групи…»).
 */
class TitlePageInfo
{
    private const NAME = '[А-ЯҐЄІЇ][а-яґєії’\'\-]+';

    private const INITIALS = '[А-ЯҐЄІЇ]\.\s?(?:[А-ЯҐЄІЇ]\.)?';

    /**
     * @param  list<string>  $lines  непорожні рядки документа до ЗМІСТУ (в оригінальному регістрі)
     * @return array{institution: ?string, workType: ?string, discipline: ?string, topic: ?string, student: ?string, course: ?string, group: ?string, specialty: ?string, supervisor: ?string, city: ?string, year: ?string}
     */
    public static function fromLines(array $lines): array
    {
        $info = array_fill_keys(['institution', 'workType', 'discipline', 'topic', 'student', 'course', 'group', 'specialty', 'supervisor', 'city', 'year'], null);

        $afterAuthorLabel = false;
        foreach (array_values($lines) as $i => $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line));
            $next = trim($lines[$i + 1] ?? '');

            // Рядок «м. Конотоп, 2026 рік» завершує титульний аркуш
            if (mb_strlen($line) < 50 && preg_match('/^(?:м\.\s*)?('.self::NAME.')?,?\s*((?:19|20)\d{2})\s*(?:р\.?|рік|року)?\s*$/u', $line, $m)) {
                $info['city'] = $m[1] ?: null;
                $info['year'] = $m[2];
                break;
            }

            if (! $info['institution'] && preg_match('/«?([^«»"]*(?:коледж|університет|інститут|академі)[^«»"]*)»?/iu', $line, $m)) {
                $info['institution'] = trim($m[1], ' "«»');
            }
            if (! $info['workType'] && preg_match('/^(КУРСОВ(?:А РОБОТА|ИЙ ПРОЄКТ|ИЙ ПРОЕКТ)|ДИПЛОМН(?:А РОБОТА|ИЙ ПРОЄКТ|ИЙ ПРОЕКТ)|КВАЛІФІКАЦІЙНА РОБОТА|ЗВІТ(?!\p{L}).*)$/u', mb_strtoupper($line), $m)) {
                $info['workType'] = mb_strtoupper(mb_substr($m[1], 0, 1)).mb_strtolower(mb_substr($m[1], 1));
            }
            if (! $info['discipline'] && preg_match('/^з\s+(?:предмет[ау]|дисциплін[иі])\s*:?\s*(.+)$/iu', $line, $m)) {
                $info['discipline'] = self::unquote($m[1]);
            }
            if (! $info['topic'] && preg_match('/^на\s+тему\s*:?\s*(.*)$/iu', $line, $m)) {
                // Тема може бути на наступному рядку
                $info['topic'] = self::unquote($m[1] !== '' ? $m[1] : $next) ?: null;
            }
            if (preg_match('/^Викона(?:в|ла|ли)(?!\p{L})/iu', $line)) {
                $afterAuthorLabel = true;
                // «Виконав: Іваненко І. І.»
                if (preg_match('/^Викона(?:в|ла|ли)\s*:\s*(.+)$/iu', $line, $m) && self::looksLikeName($m[1])) {
                    $info['student'] = trim($m[1]);
                }
            }
            if (! $info['course'] && preg_match('/(?<!\S)([1-6IVІ]{1,3})\s*-?\s*(?:го\s+)?курсу/u', $line, $m)) {
                $info['course'] = $m[1];
            }
            if (! $info['group'] && (preg_match('/([\p{L}\d\-]+)\s+груп[иі]/u', $line, $m) || preg_match('/груп[аи]\s*:?\s*([\p{L}\d\-]+)/iu', $line, $m))) {
                $info['group'] = $m[1];
            }
            if (! $info['specialty'] && preg_match('/спеціальн\S*\s*«?\s*(\d{3})\s*«?\s*([^»"]+)/iu', $line, $m)) {
                $info['specialty'] = $m[1].' '.trim($m[2], ' »"');
            }
            if (! $info['supervisor'] && preg_match('/^Керівник\S*\s*:?\s*(.+)$/iu', $line, $m)) {
                $info['supervisor'] = self::extractName($m[1]) ?? trim($m[1]);
            }

            // ПІБ студента: рядок над підказкою «(прізвище та ініціали)» або схожий на ПІБ рядок після «Виконав»
            if ($afterAuthorLabel && ! $info['student'] && ! $info['supervisor']) {
                if (preg_match('/^\(прізвище/iu', $next) && self::looksLikeName($line)) {
                    $info['student'] = $line;
                } elseif (self::looksLikeName($line) && ! preg_match('/курсу|груп|спеціальн|напрям|галуз/iu', $line)) {
                    $info['student'] = $line;
                }
            }
        }

        return $info;
    }

    /** «Пишний Володимир Олексійович», «Мілорадов Н. Л.», «І. І. Іваненко» */
    private static function looksLikeName(string $text): bool
    {
        $text = trim($text);
        $n = self::NAME;
        $i = self::INITIALS;

        return mb_strlen($text) <= 60 && (bool) preg_match("/^(?:$n(?:\s+$n){1,2}|$n\s+$i|$i\s*$n)$/u", $text);
    }

    private static function extractName(string $text): ?string
    {
        $n = self::NAME;
        $i = self::INITIALS;

        return preg_match("/($n\s+$i|$i\s*$n|$n\s+$n(?:\s+$n)?)\s*$/u", trim($text), $m) ? trim($m[1]) : null;
    }

    private static function unquote(string $text): string
    {
        return trim($text, " \t\"'«»“”„");
    }
}
