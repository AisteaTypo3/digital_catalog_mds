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
 * All article numbers of a row share the other columns (Length, Holes, Description …); a diameter label in front of
 * the rows ("Ø 1.2 mm", extracted as "A 1.2 mm") applies to the whole table when there is only one. The section head
 * above the table gives the title and attributes such as "Material / Matériau : cpTi" or "Plate thickness / … : 0.6 mm".
 * Measurements drawn into the pictures are not read: they cannot be assigned to an article reliably.
 */
final class SpecificationParser
{
    /** raise when the parsing changes: every PDF is read again on the next run */
    public const VERSION = 1;

    private const ARTICLE_PATTERN = '/^[A-Z][A-Z0-9]{0,2}-\d{3,5}(?:\.\d{1,3})?(?:\/\d+)?S?$/';
    private const DIAMETER_LABEL = '/^\s{0,4}(?:A|Ø)\s?(\d+(?:\.\d+)?)\s?mm(?=\s|$)/u';
    private const COLUMN_TOLERANCE = 3;

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
        $section = ['title' => '', 'attributes' => []];

        foreach (explode("\f", $text) as $pageIndex => $pageText) {
            $lines = explode("\n", $pageText);
            $count = count($lines);
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
                $found = $this->findSection($lines, $blockStart);
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

                foreach ($rows as $row) {
                    foreach ($this->rowRecords($row, $columns) as $article => $attributes) {
                        $all = $section['attributes'];
                        if ($diameter !== '') {
                            $all[] = ['Diameter', $diameter];
                        }
                        $records[] = [
                            'article' => $article,
                            'page' => $pageIndex + 1,
                            'section' => $section['title'],
                            'attributes' => array_merge($all, $attributes),
                        ];
                    }
                }
                $i = $end;
            }
        }

        return $records;
    }

    private function isHeaderLine(string $line): bool
    {
        return str_contains($line, 'Art. No.');
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
            && !$this->hasArticleNumber($lines[$end + 1])) {
            $end++;
        }

        return [$start, $end];
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
        return in_array('Art. No.', $labels, true) && !in_array('Page', $labels, true);
    }

    /**
     * The section head above a table: the three-language title block that names attributes like the material.
     *
     * @param array<int, string> $lines
     * @return array{title: string, attributes: array<int, array{0: string, 1: string}>}|null
     */
    private function findSection(array $lines, int $headerIndex): ?array
    {
        for ($i = $headerIndex - 1; $i >= 0 && $i >= $headerIndex - 80; $i--) {
            if ($this->isHeaderLine($lines[$i])) {
                return null;
            }
            if (!preg_match('/\S\s*:\s*\S/', $lines[$i]) || !preg_match('/(Material|thickness)/i', $lines[$i])) {
                continue;
            }
            // the block of consecutive lines around the attribute line
            $start = $i;
            while ($start > 0 && trim($lines[$start - 1]) !== '') {
                $start--;
            }
            $end = $i;
            while (isset($lines[$end + 1]) && trim($lines[$end + 1]) !== '') {
                $end++;
            }

            $attributes = [];
            for ($j = $start; $j <= $end; $j++) {
                // the attribute may follow the title on the same line: only the segment after the last wide gap
                $segments = preg_split('/\s{3,}/', trim($lines[$j])) ?: [];
                $segment = (string)end($segments);
                if (preg_match('/^([A-Z][^:\/]*?)\s*(?:\/[^:]*)?:\s*(.+)$/u', $segment, $match) === 1) {
                    $attributes[] = [trim($match[1]), trim($match[2])];
                }
            }
            $titleTokens = $this->tokens($lines[$start]);
            $title = $titleTokens[0][1] ?? '';
            if (str_contains($title, ':')) {
                $title = '';
            }

            return ['title' => $title, 'attributes' => $attributes];
        }

        return null;
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
            $hasArticle = false;
            foreach ($cells as $index => $value) {
                if (in_array($columns[$index]['label'], ['Art. No.', 'Sterile'], true) && preg_match(self::ARTICLE_PATTERN, $value) === 1) {
                    $hasArticle = true;
                    break;
                }
            }
            if ($hasArticle) {
                $rows[] = $cells;
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
        $shared = [];
        foreach ($columns as $index => $column) {
            $value = trim($cells[$index] ?? '');
            if ($value === '' || in_array($column['label'], self::SKIPPED_COLUMNS, true)) {
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
        return match (strtolower($label)) {
            'a', 'ø' => 'Diameter',
            'pieces/pkg', 'pieces/pkg.', 'piece/pkg' => 'Pieces/Pkg',
            'art. no.' => 'Art. No.',
            'sterile' => 'Sterile',
            default => $label,
        };
    }
}
