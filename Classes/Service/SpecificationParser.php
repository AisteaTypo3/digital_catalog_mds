<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

/**
 * Reads the ordering tables of Medartis ordering catalogs / surgical techniques from `pdftotext -layout` output and
 * returns the technical data per article number.
 *
 * The tables have a three-language header (EN / DE / FR, the English line contains "Art. No."), one row per variant
 * and several article numbers per row (single, sterile, package), each followed by its "Pieces / Pkg" column:
 *
 *     Length   Art. No.     STERILE        Pieces / Pkg   Art. No.    Pieces / Pkg
 *      4 mm    A-5100.04/1  A-5100.04/1S   1              A-5100.04   5
 *
 * All article numbers of a row share the other columns (Length, Holes, Description …), except the columns of one
 * variant (the thread length of a screw with short / long thread, see variantColumns()); a diameter label in front of
 * the rows ("Ø 1.2 mm", extracted as "A 1.2 mm") applies to the whole table when there is only one. The section head
 * above the table gives the title and attributes such as "Material / Matériau : cpTi" or "Plate thickness / … : 0.6 mm"
 * (see findSection()); every new title starts a new section, also one without attributes (instruments).
 * Measurements drawn into the pictures are not read: they cannot be assigned to an article reliably.
 */
final class SpecificationParser
{
    /** raise when the parsing changes: every PDF is read again on the next run */
    public const VERSION = 2;

    private const ARTICLE_PATTERN = '/^[A-Z][A-Z0-9]{0,2}-\d{3,5}(?:\.\d{1,3})?(?:\/\d+)?S?$/';
    private const DIAMETER_LABEL = '/^\s{0,4}(?:A|Ø)\s?(\d+(?:\.\d+)?)\s?mm(?=\s|$)/u';
    private const COLUMN_TOLERANCE = 3;
    /** a chapter line like "Implants and Instruments" or "Implants, Instruments and" + "Containers" */
    private const CHAPTER_WORDS = '/^(?:(?:Implants?|Instruments?|Containers?|Cases?|and),?\s*)+$/u';

    /** German column names (one line of the three-language header) → English label */
    private const GERMAN_LABELS = [
        'länge' => 'Length',
        'löcher' => 'Holes',
        'löcher im schaft' => 'Holes in Shaft',
        'beschreibung' => 'Description',
        'gewindelänge' => 'Thread Length',
        'distale gewindelänge' => 'Distal Thread Length',
        'kopfhöhe' => 'Head Height',
        'systemgrösse' => 'System Size',
        'systemgröße' => 'System Size',
        'stopp' => 'Stop',
        'schaftende' => 'Shaft End',
        'breite' => 'Width',
        'plattenbreite' => 'Plate width',
        'steg' => 'Bar',
        'durchmesser' => 'Diameter',
        'grösse' => 'Size',
        'größe' => 'Size',
        'schablone' => 'Template',
        'seite' => 'Page',
    ];

    /** columns that are no measurements */
    private const SKIPPED_COLUMNS = ['Art. No.', 'Sterile', 'Pieces/Pkg', 'Template', 'Page'];

    /**
     * @return array<int, array{article: string, page: int, section: string, attributes: array<int, array{0: string, 1: string}>}>
     */
    public function parse(string $text): array
    {
        $records = [];
        $section = ['title' => '', 'attributes' => [], 'right' => null];
        // control characters some PDFs put at the start of lines (backspace); keep the page breaks (\f)
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0E-\x1F]/', ' ', $text);
        // the Ø sign of a symbol font (PentaLock surgical technique)
        $text = str_replace("\u{E042}", 'Ø', $text);

        foreach (explode("\f", $text) as $pageIndex => $pageText) {
            $lines = explode("\n", $pageText);
            $count = count($lines);
            $previousEnd = -1;
            for ($i = 0; $i < $count; $i++) {
                if (!$this->isHeaderLine($lines[$i])) {
                    continue;
                }
                [$blockStart, $blockEnd] = $this->headerBlock($lines, $i);
                $columns = $this->headerColumns($lines, $blockStart, $blockEnd);
                if ($columns === [] || !$this->isOrderingTable($columns)) {
                    $i = $blockEnd;
                    continue;
                }
                // a new title above the table starts a new section; without one the table continues the previous
                $found = $this->findSection($lines, $blockStart, $previousEnd);
                if ($found !== null) {
                    $section = $found;
                }

                [$rows, $diameters, $end] = $this->readRows($lines, $blockEnd + 1, $columns);
                // a diameter label between the section head and the table applies to the whole table
                for ($j = $blockStart - 1; $j >= 0 && $j >= $blockStart - 4; $j--) {
                    if (preg_match(self::DIAMETER_LABEL, $lines[$j], $match) === 1) {
                        $diameters[] = $match[1];
                        break;
                    }
                }
                $diameters = array_values(array_unique($diameters));
                $diameter = count($diameters) === 1 ? $diameters[0] . ' mm' : '';

                // two tables side by side under two titles: each side has its own columns and title
                $sides = [$columns];
                $rightTitle = $section['right']['title'] ?? '';
                if ($section['right'] !== null) {
                    $border = $section['right']['start'] - self::COLUMN_TOLERANCE;
                    $sides = [
                        array_filter($columns, static fn (array $column): bool => $column['start'] < $border),
                        array_filter($columns, static fn (array $column): bool => $column['start'] >= $border),
                    ];
                }
                foreach ($rows as $row) {
                    foreach ($sides as $side => $sideColumns) {
                        foreach ($this->rowRecords($row, $sideColumns) as $article => $attributes) {
                            $right = $side === 1;
                            $all = $right ? [] : $this->sectionAttributes($section['attributes'], $article, $attributes);
                            // the diameter of the table, unless the row has its own
                            if ($diameter !== '' && !in_array('Diameter', array_column($attributes, 0), true)) {
                                $all[] = ['Diameter', $diameter];
                            }
                            $records[] = [
                                'article' => $article,
                                'page' => $pageIndex + 1,
                                'section' => $right ? $rightTitle : $section['title'],
                                'attributes' => array_merge($all, $attributes),
                            ];
                        }
                    }
                }
                $i = $previousEnd = $end;
            }
        }

        // an article read twice (e.g. a number in a picture taken for a row): keep the record with the most data
        $best = [];
        foreach ($records as $index => $record) {
            $article = $record['article'];
            if (!isset($best[$article]) || count($record['attributes']) > count($records[$best[$article]]['attributes'])) {
                $best[$article] = $index;
            }
        }

        return array_values(array_intersect_key($records, array_flip($best)));
    }

    private function isHeaderLine(string $line): bool
    {
        // tables of sterile articles only have no "Art. No." column ("Length  Thread Length  Ø  STERILE  Pieces / Pkg")
        return str_contains($line, 'Art. No.') || (str_contains($line, 'STERILE') && str_contains($line, 'Pieces'));
    }

    /**
     * The lines of a table header: the line with "Art. No." and the header lines above and below it (headers wrap,
     * e.g. "Head / Height" or "Pieces / / Pkg", so the "Art. No." line is not always the first one).
     *
     * @param array<int, string> $lines
     * @return array{0: int, 1: int}
     */
    private function headerBlock(array $lines, int $index): array
    {
        $start = $index;
        while ($start > 0 && $index - $start < 8 && trim($lines[$start - 1]) !== ''
            && !str_contains($lines[$start - 1], ' : ') && preg_match(self::DIAMETER_LABEL, $lines[$start - 1]) !== 1
            && !$this->hasArticleNumber($lines[$start - 1])) {
            $start--;
        }
        $end = $index;
        while (isset($lines[$end + 1]) && $end - $index < 8 && trim($lines[$end + 1]) !== ''
            && !$this->hasArticleNumber($lines[$end + 1])
            && !$this->isSimpleHeaderEnd($lines, $end)) {
            $end++;
        }

        return [$start, $end];
    }

    /**
     * The last line of a plain header ("Art. No." / "Art. Nr." / "Référence" at the start of three lines): below it
     * starts the text of the first row, not another header line.
     *
     * @param array<int, string> $lines
     */
    private function isSimpleHeaderEnd(array $lines, int $index): bool
    {
        return $index >= 2
            && str_starts_with(ltrim($lines[$index]), 'Référence')
            && str_starts_with(ltrim($lines[$index - 1]), 'Art. Nr.')
            && str_starts_with(ltrim($lines[$index - 2]), 'Art. No.');
    }

    private function hasArticleNumber(string $line): bool
    {
        foreach ($this->tokens($line) as [, $text]) {
            if (preg_match(self::ARTICLE_PATTERN, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns of a table: the header words grouped by position; each group is named after the article / sterile /
     * pieces columns, a known German name (mapped to English) or else its topmost (English) word.
     *
     * @param array<int, string> $lines
     * @return array<int, array{start: int, label: string}>
     */
    private function headerColumns(array $lines, int $start, int $end): array
    {
        $tokens = [];
        for ($i = $start; $i <= $end; $i++) {
            foreach ($this->tokens($lines[$i]) as [$position, $text]) {
                $tokens[] = ['start' => $position, 'text' => $text, 'line' => $i];
            }
        }
        usort($tokens, static fn (array $a, array $b): int => [$a['start'], $a['line']] <=> [$b['start'], $b['line']]);

        $groups = [];
        foreach ($tokens as $token) {
            $last = array_key_last($groups);
            if ($last !== null && $token['start'] - $groups[$last]['start'] <= self::COLUMN_TOLERANCE) {
                $groups[$last]['tokens'][] = $token;
                continue;
            }
            $groups[] = ['start' => $token['start'], 'tokens' => [$token]];
        }

        $columns = [];
        foreach ($groups as $group) {
            usort($group['tokens'], static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
            $columns[] = ['start' => $group['start'], 'label' => $this->groupLabel(array_column($group['tokens'], 'text'))];
        }

        return $columns;
    }

    /**
     * @param array<int, string> $words header words of one column, top to bottom
     */
    private function groupLabel(array $words): string
    {
        foreach ($words as $word) {
            if (in_array($word, ['Art. No.', 'Art. Nr.', 'Référence'], true)) {
                return 'Art. No.';
            }
        }
        foreach ($words as $word) {
            if (in_array(strtoupper($word), ['STERILE', 'STERIL'], true)) {
                return 'Sterile';
            }
        }
        foreach ($words as $word) {
            if (preg_match('/^(Pieces|Stk\.|Pièce)/u', $word) === 1) {
                return 'Pieces/Pkg';
            }
        }
        foreach ($words as $word) {
            $key = mb_strtolower(trim((string)preg_replace('/^(A|Ø)\s+/u', '', $word)));
            if (isset(self::GERMAN_LABELS[$key])) {
                return self::GERMAN_LABELS[$key];
            }
        }

        return $this->normalizeLabel($words[0] ?? '');
    }

    /**
     * @param array<int, array{start: int, label: string}> $columns
     */
    private function isOrderingTable(array $columns): bool
    {
        $labels = array_column($columns, 'label');
        // index tables (article number → page) are no ordering tables
        return (in_array('Art. No.', $labels, true) || in_array('Sterile', $labels, true)) && !in_array('Page', $labels, true);
    }

    /**
     * The section head above a table: the first block of title lines (EN / DE / FR, or English only) between the
     * previous table (or the page head) and this one, with the attributes it names, like "Material / Matériau : cpTi".
     * Sections of instruments have a title without attributes. Labels of the pictures below the title ("left",
     * "Compatible with screws …") and notes or sub-headings between two tables of a section are no new section.
     *
     * @param array<int, string> $lines
     * @return array{title: string, attributes: array<int, array{0: string, 1: string, 2?: string}>, right: array{title: string, start: int}|null}|null
     */
    private function findSection(array $lines, int $headerIndex, int $lowerBound): ?array
    {
        $chapter = $candidate = null;
        $start = null;
        for ($i = max(0, $lowerBound); $i <= $headerIndex; $i++) {
            if ($i < $headerIndex && $this->isTitleLine($lines[$i])) {
                $start ??= $i;
                continue;
            }
            if ($start !== null) {
                $block = array_slice($lines, $start, $i - $start);
                // attributes can continue below the title lines ("(always to be used in pairs)   Superior: cpTi")
                $more = [];
                for ($next = $i; $next < $headerIndex && trim($lines[$next]) !== '' && !$this->hasArticleNumber($lines[$next]); $next++) {
                    $more[] = $lines[$next];
                }
                if (!$this->isSectionTitle($block) && $candidate !== null) {
                    // the attributes may stand next to "Compatible with screws …" below the title
                    $attributes = $this->section($block, $more)['attributes'];
                    if ($attributes !== []) {
                        return ['attributes' => $attributes] + $candidate;
                    }
                }
                if ($this->isSectionTitle($block)) {
                    $section = $this->section($block, $more);
                    // a chapter head above the section title: take the section title
                    if ($section['attributes'] === [] && $this->isChapter($block)) {
                        $chapter ??= $section;
                    } elseif ($section['attributes'] === []) {
                        // a title without attributes above one with ("Midface Plates t = 0.5 mm" above "Straight
                        // Plates … Material : cpTi"): the one with attributes, else this one
                        $candidate ??= $section;
                    } else {
                        // attributes in their own block below the title ("Material:" + one line per part)
                        if ($section['title'] === '' && $candidate !== null) {
                            $section['title'] = $candidate['title'];
                        }
                        return $section;
                    }
                }
            }
            $start = null;
        }

        return $candidate ?? $chapter;
    }

    /**
     * The attributes of the section head for one article: an exception for the article ("… M2-4692 : 1.5 mm")
     * replaces the general value, and the row's own value wins over both.
     *
     * @param array<int, array{0: string, 1: string, 2?: string}> $sectionAttributes
     * @param array<int, array{0: string, 1: string}> $rowAttributes
     * @return array<int, array{0: string, 1: string}>
     */
    private function sectionAttributes(array $sectionAttributes, string $article, array $rowAttributes): array
    {
        $base = (string)preg_replace('/(?:\/\d+)?S?$/', '', $article);
        $exceptions = [];
        foreach ($sectionAttributes as $pair) {
            if (isset($pair[2]) && $pair[2] === $base) {
                $exceptions[$pair[0]] = $pair[1];
            }
        }
        $own = array_column($rowAttributes, 0);
        $attributes = [];
        foreach ($sectionAttributes as $pair) {
            if (isset($pair[2]) || in_array($pair[0], $own, true)) {
                continue;
            }
            $attributes[] = [$pair[0], $exceptions[$pair[0]] ?? $pair[1]];
        }

        return $attributes;
    }

    /**
     * A chapter head: "Hand System 1.2 – 2.3" (in three languages), "MODUS 2 Orthognathics" (three times the same),
     * "Implants, Instruments and Containers".
     *
     * @param array<int, string> $block consecutive title lines
     */
    private function isChapter(array $block): bool
    {
        $lines = array_map('trim', $block);
        if (preg_match('/\bSystem\b/u', implode(' ', $lines)) === 1 || (count($lines) > 1 && count(array_unique($lines)) === 1)) {
            return true;
        }
        foreach ($lines as $line) {
            if (preg_match(self::CHAPTER_WORDS, $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $block consecutive title lines
     */
    private function isSectionTitle(array $block): bool
    {
        $first = trim($this->tokens($block[0])[0][1] ?? '');
        if (preg_match('/^(Compatible|Kompatibel|Compatibles|Compatibili|Example)\b/u', $first) === 1) {
            return false;
        }
        if (count($block) > 1) {
            return true;
        }
        // one line: no note ("Additional configurations available on request.") and no sub-heading in three languages
        // ("Long Thread / Langes Gewinde/ Filetage long")
        return !str_ends_with($first, '.') && substr_count($first, '/') < 2;
    }

    /**
     * @param array<int, string> $block title lines
     * @param array<int, string> $more lines right below them that can hold attributes
     * @return array{title: string, attributes: array<int, array{0: string, 1: string, 2?: string}>, right: array{title: string, start: int}|null}
     */
    private function section(array $block, array $more = []): array
    {
        // a chapter head right above the section title, without a gap: "Hand System 1.2 – 2.3" in three languages,
        // "MODUS 2 Orthognathics" three times, "Implants and Instruments"
        if (count($block) >= 6 && preg_match('/\bSystem\b/u', $block[0]) === 1) {
            $block = array_slice($block, 3);
        }
        if (count($block) > 2 && trim($block[0]) === trim($block[1])) {
            $chapter = trim($block[0]);
            $rest = array_values(array_filter($block, static fn (string $line): bool => trim($line) !== $chapter));
            $block = $rest !== [] ? $rest : [$block[0]];
        }
        // "Implants and Instruments", "Implants, Instruments and" + "Containers" (not "Instrument Cases")
        if (preg_match(self::CHAPTER_WORDS, trim($block[0])) === 1 && preg_match('/,| and\b/u', $block[0]) === 1) {
            while (count($block) > 1 && preg_match(self::CHAPTER_WORDS, trim($block[0])) === 1) {
                array_shift($block);
            }
        }
        $attributes = [];
        $materialParts = false;
        foreach (array_merge($block, $more) as $line) {
            // the attribute follows the title on the same line: only the segment after the last wide gap
            $segments = preg_split('/\s{3,}/', trim($line)) ?: [];
            $segment = (string)end($segments);
            if (preg_match('/^Material\s*:$/u', $segment) === 1) {
                // "Material:" followed by one line per part ("Superior: cpTi", "Anterior: Ti6Al4V")
                $materialParts = true;
                continue;
            }
            if (preg_match('/^([A-Z][^:\/]*?)\s*(?:\/[^:]*)?:\s*(.+)$/u', $segment, $match) === 1
                || preg_match('/^(Plate thickness)\s+(\d.*mm)$/iu', $segment, $match) === 1) {
                // an exception for one article: "Plate thickness / Plattendicke / Epaisseur M2-4692 : 1.5 mm"
                if (preg_match('/\s([A-Z][A-Z0-9]{0,2}-\d{3,5}(?:\.\d{1,3})?)\s*:/u', $segment, $only) === 1) {
                    $attributes[] = [$this->normalizeLabel($match[1]), trim($match[2]), $only[1]];
                    continue;
                }
                $label = $this->normalizeLabel($match[1]);
                if ($materialParts && !in_array(strtolower($label), ['plate thickness', 'material'], true)) {
                    $label = 'Material (' . strtolower($label) . ')';
                } else {
                    $materialParts = false;
                }
                $attributes[] = [$label, trim($match[2])];
            }
        }
        $tokens = $this->tokens($block[0]);
        $title = $tokens[0][1] ?? '';
        if (str_contains($title, ':')) {
            $title = '';
        }
        // a second title on the same line, far right and no attribute: the title of a table on the right
        $right = null;
        if (count($tokens) === 2 && !str_contains($tokens[1][1], ':') && $tokens[1][0] >= 40) {
            $right = ['title' => $tokens[1][1], 'start' => $tokens[1][0]];
        }

        return ['title' => $title, 'attributes' => $attributes, 'right' => $right];
    }

    /**
     * A line of a section title: one or two segments (the title, maybe an attribute) starting with a capital, a
     * number or a name like "headedCCS". Not a measurement or article number of a picture, no label in lower case ("left", "pour guide-foret"),
     * no row of labels ("left   right   left   right"), no footnote ("* Not available …") and no page head
     * ("22 l Hand 1.2 – 2.3", "Hand 1.2 – 2.3 l 21").
     */
    private function isTitleLine(string $line): bool
    {
        $tokens = $this->tokens($line);
        $first = $tokens[0][1] ?? '';
        if (count($tokens) > 2 || str_starts_with($first, 'Scale ') || mb_strlen($first) < 4 || preg_match('/\p{L}{3,}/u', $first) !== 1 || preg_match('/^[(*]|^\p{Ll}+(?!\p{Lu})\b/u', $first) === 1) {
            return false;
        }
        if (preg_match('/^[A-Z][A-Z0-9]{0,2}-\d{3,5}/', $first) === 1 || preg_match('/^\d+(?:[.,]\d+)?\s?(?:mm|°)(?:\s*\(.*)?$/u', $first) === 1) {
            return false;
        }

        return preg_match('/^\d+\s+l\s|\sl\s+\d+$/u', trim($line)) !== 1;
    }

    /**
     * Rows below the header until the table ends (three empty lines, another header or the page end).
     *
     * @param array<int, string> $lines
     * @param array<int, array{start: int, label: string}> $columns
     * @return array{0: array<int, array<int, string>>, 1: array<int, string>, 2: int}
     */
    private function readRows(array $lines, int $from, array $columns): array
    {
        $rows = [];
        $diameters = [];
        $above = null;
        $empty = 0;
        $i = $from;
        $count = count($lines);
        for (; $i < $count; $i++) {
            $line = $lines[$i];
            if ($this->isHeaderLine($line)) {
                $i--;
                break;
            }
            if (trim($line) === '') {
                if (++$empty >= 3 && $rows !== []) {
                    break;
                }
                continue;
            }
            $empty = 0;
            if (preg_match(self::DIAMETER_LABEL, $line, $match) === 1) {
                $diameters[] = $match[1];
                $line = (string)preg_replace(self::DIAMETER_LABEL, str_repeat(' ', strlen($match[0])), $line, 1);
            }
            $cells = $this->cells($line, $columns);
            // a description in three lines (EN / DE / FR) with the article number on the middle one
            $textOnly = $cells !== [] && array_diff(array_map(static fn (int $index): string => $columns[$index]['label'], array_keys($cells)), ['Description']) === [];
            if ($textOnly) {
                $above = $cells;
                continue;
            }
            // no row yet and the title of the next section (at the left margin): the article numbers below it belong to
            // a picture
            if ($rows === [] && strlen($line) - strlen(ltrim($line)) <= 4 && $this->isTitleLine($line) && $this->isSectionTitle([$line])) {
                break;
            }
            $hasArticle = false;
            foreach ($cells as $index => $value) {
                if (in_array($columns[$index]['label'], ['Art. No.', 'Sterile'], true) && preg_match(self::ARTICLE_PATTERN, $value) === 1) {
                    $hasArticle = true;
                    break;
                }
            }
            if ($hasArticle) {
                // the English line above the row replaces the German one of the row
                if ($above !== null && $lines[$i - 1] !== '' && $this->cells($lines[$i - 1], $columns) === $above) {
                    $cells = array_replace($cells, $above);
                }
                $rows[] = $cells;
                $above = null;
            } elseif ($rows !== [] && !preg_match('/^\s*(Art\. Nr\.|Référence|Länge|Longueur|Stk\.|Pièce)/u', $line)) {
                // a line without article number after the rows (footer, next title): the table has ended
                if (!preg_match(self::DIAMETER_LABEL, $lines[$i])) {
                    break;
                }
            }
        }

        return [$rows, $diameters, $i];
    }

    /**
     * Values of a row, assigned to the column whose start is nearest (values can be right-aligned).
     *
     * @param array<int, array{start: int, label: string}> $columns
     * @return array<int, string>
     */
    private function cells(string $line, array $columns): array
    {
        $cells = [];
        foreach ($this->tokens($line) as [$start, $text]) {
            $best = null;
            $bestDistance = PHP_INT_MAX;
            foreach ($columns as $index => $column) {
                $next = $columns[$index + 1]['start'] ?? PHP_INT_MAX;
                if ($start >= $column['start'] - self::COLUMN_TOLERANCE && $start < $next - self::COLUMN_TOLERANCE) {
                    $best = $index;
                    break;
                }
                $distance = abs($column['start'] - $start);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $index;
                }
            }
            if ($best !== null) {
                $cells[$best] = isset($cells[$best]) ? $cells[$best] . ' ' . $text : $text;
            }
        }

        return $cells;
    }

    /**
     * One entry per article number of the row: the shared columns plus the pieces per package of its own group.
     *
     * @param array<int, string> $cells
     * @param array<int, array{start: int, label: string}> $columns
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private function rowRecords(array $cells, array $columns): array
    {
        $variantOf = $this->variantColumns($columns);

        $shared = [];
        foreach ($columns as $index => $column) {
            $value = trim($cells[$index] ?? '');
            if ($value === '' || in_array($column['label'], self::SKIPPED_COLUMNS, true) || isset($variantOf[$index])) {
                continue;
            }
            if ($column['label'] === 'Description') {
                // "rectangular / rechteckig / rectangulaire" → English part
                $value = trim(explode(' / ', $value)[0]);
            }
            // a label twice in one row comes from a shifted header: keep the first value only
            if (in_array($column['label'], array_column($shared, 0), true)) {
                continue;
            }
            $shared[] = [$column['label'], $value];
        }

        $records = [];
        foreach ($columns as $index => $column) {
            $value = trim($cells[$index] ?? '');
            if (!in_array($column['label'], ['Art. No.', 'Sterile'], true) || preg_match(self::ARTICLE_PATTERN, $value) !== 1) {
                continue;
            }
            $attributes = $shared;
            // the article number column of this variant (the sterile one is right of it)
            $group = $index;
            while ($columns[$group]['label'] !== 'Art. No.' && isset($columns[$group - 1])) {
                $group--;
            }
            foreach ($variantOf as $variantIndex => $owner) {
                if ($owner === $group && trim($cells[$variantIndex] ?? '') !== '') {
                    // the thread length of the variant, its header often only reads "Short Thread" or "Length"
                    $label = in_array($columns[$variantIndex]['label'], ['Short Thread', 'Long Thread', 'Length'], true) ? 'Thread Length' : $columns[$variantIndex]['label'];
                    $attributes[] = [$label, trim($cells[$variantIndex])];
                }
            }
            if ($column['label'] === 'Sterile') {
                $attributes[] = ['Sterile', 'yes'];
            }
            for ($next = $index + 1; isset($columns[$next]); $next++) {
                if ($columns[$next]['label'] === 'Art. No.') {
                    break;
                }
                if ($columns[$next]['label'] === 'Pieces/Pkg' && trim($cells[$next] ?? '') !== '') {
                    $attributes[] = ['Pieces/Pkg', trim($cells[$next])];
                    break;
                }
            }
            $records[$value] = $attributes;
        }

        return $records;
    }

    /**
     * Columns of one variant of a row, e.g. the thread length of screws with short / long / full thread:
     *
     *     Length  Head Height  Thread Length  Art. No.  STERILE  Thread Length  Art. No.  STERILE  Art. No.  STERILE
     *
     * A column between two article number groups belongs to the group on its right; a column of the same name right
     * before the first group belongs to the first group.
     *
     * @param array<int, array{start: int, label: string}> $columns
     * @return array<int, int> column index → index of its "Art. No." column
     */
    private function variantColumns(array $columns): array
    {
        $groups = array_keys(array_filter($columns, static fn (array $column): bool => $column['label'] === 'Art. No.'));
        if (count($groups) < 2) {
            return [];
        }
        $variantOf = [];
        $labels = [];
        foreach (array_slice($groups, 1) as $position => $group) {
            for ($index = $group - 1; $index > $groups[$position]; $index--) {
                if (in_array($columns[$index]['label'], ['Art. No.', 'Sterile', 'Pieces/Pkg'], true)) {
                    break;
                }
                $variantOf[$index] = $group;
                $labels[] = $columns[$index]['label'];
            }
        }
        // before the first group: the columns named like those between the groups, or a thread length
        $labels = array_merge($labels, ['Short Thread', 'Long Thread', 'Thread Length']);
        for ($index = $groups[0] - 1; $index >= 0 && isset($columns[$index]); $index--) {
            if (!in_array($columns[$index]['label'], $labels, true)) {
                break;
            }
            $variantOf[$index] = $groups[0];
        }

        return $variantOf;
    }

    /**
     * Text segments separated by two or more spaces, with their character position.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function tokens(string $line): array
    {
        $tokens = [];
        if (preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        foreach ($matches[0] as [$text, $byteOffset]) {
            $tokens[] = [mb_strlen(substr($line, 0, $byteOffset)), $text];
        }

        return $tokens;
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        // pdftotext reads the Ø sign as "A"
        $label = (string)preg_replace('/^A\s+(?=[A-Z])/u', 'Ø ', $label);
        $label = (string)preg_replace('/\s*\/\s*/', '/', $label);
        // a wrapped neighbour column can stick to the label ("Head Hight Pieces/")
        $label = trim((string)preg_replace('/\s+Pieces\/?$/u', '', $label));
        $label = str_replace('Hight', 'Height', $label);
        // one spelling per label: "Plate Thickness", "Dimension (W x L)" …
        $label = (string)preg_replace('/^Dimensions? \((\w) [x×] (\w)\)$/u', 'Dimensions ($1 × $2)', $label);
        if (strcasecmp($label, 'Plate thickness') === 0) {
            $label = 'Plate thickness';
        }
        return match (mb_strtolower($label)) {
            'a', 'ø' => 'Diameter',
            'pieces/pkg', 'pieces/pkg.', 'piece/pkg' => 'Pieces/Pkg',
            'art. no.' => 'Art. No.',
            'sterile' => 'Sterile',
            default => $label,
        };
    }
}
