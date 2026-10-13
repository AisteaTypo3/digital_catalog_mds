<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

/**
 * Finds the implants a case report is about, as the section titles of the ordering catalogs (the families the
 * technical data of the articles belong to, tx_digitalcatalog_specification.section).
 *
 * Plates: a report matches a plate family when all words that tell the family apart stand right before "plate" in
 * the text: "2.8 TriLock Clavicle Plates, Superior Lateral" ← "… with a superior lateral clavicle plate". Sizes and
 * "TriLock" may be left out, distinguishing tokens ("II", "XL", "FPL") not; families named by general words only
 * ("Fixation Plates", "Z Plates") never match; of several families matching one mention only the most specific
 * counts. A plate there before ("pre-existing", "previous") does not count; mentions of an option discussed or of a
 * plate taken out ("can be addressed by …", "removed") are skipped.
 *
 * CCS: "5.0 mm CCS", "CCS 3.0", "headed 4.0 … CCS" → the CCS / headedCCS families of that size. A report about CCS
 * (area "CCS") also matches all CCS families when it names no size; elsewhere a CCS without size does not count
 * ("… fixed with a cannulated compression screw (CCS)" in a report about a plate).
 *
 * In every case the body area of the report (file metadata "System of Interest") has to be one of the areas of the
 * family's articles, when the report has one.
 */
final class CaseReportMatcher
{
    /** raise when the matching changes */
    public const VERSION = 2;

    private const GENERIC = ['trilock', 'aptus', 'plates', 'plate', 'the', 'and', 'with', 'for', 'of', 'incl', 'pl'];
    /**
     * Words a report may leave out ("2.8 fibula plate" for "2.8 TriLock Distal Fibula Plates"): position, variant and
     * area. They do not tell a family apart alone, but the family naming more of them in the report wins.
     */
    private const WEAK = [
        'fixation', 'lateral', 'medial', 'dorsal', 'volar', 'straight', 'anatomical', 'case', 'screw', 'grid',
        'superior', 'anterior', 'posterior', 'small', 'large', 'standard', 'extended', 'bridging', 'compression',
        'shaft', 'correction', 'fracture', 'frame', 'long', 'short', 'condyle', 'ii', 'xs', 'xl', 'distal',
        'proximal', 'locking', 'titanium', 'hand', 'wrist', 'foot', 'ankle', 'elbow', 'shoulder',
    ];
    /** words of the reports → the word of the family names */
    private const SYNONYMS = [
        'ulnar' => ['ulna'],
        'radial' => ['radius'],
        'clavicular' => ['clavicle'],
        'metatarsophalangeal' => ['mtp'],
        'tarsometatarsal' => ['tmt'],
        // the Lapidus arthrodesis is the fusion of the first TMT joint
        'lapidus' => ['tmt', 'fusion'],
    ];
    /**
     * Where an implant sits: a family named by these alone ("2.5 TriLock Distal Radius Plates, Dorsal", "Distal Ulna
     * Plates") needs all words of its name in the report (but "distal" / "proximal"), else every "radius … plate" would
     * be one of them.
     */
    private const LOCATIONS = [
        'radius', 'ulna', 'clavicle', 'tibia', 'fibula', 'humerus', 'calcaneus', 'mc', 'mandible', 'midface',
        'olecranon', 'scaphoid', 'phalanx', 'metacarpal', 'metatarsal',
    ];
    /** the words a family name is written before in the reports */
    private const ANCHORS = ['plate', 'plates', 'system', 'systems', 'splint', 'splints'];
    /** report areas that are products: a report about it is about all its families */
    private const PRODUCT_AREAS = ['tts'];
    /** short tokens that do tell families apart */
    private const SHORT_KEEP = ['ii', 'xs', 'xl'];
    /** a plate mentioned with these was there before: it does not count anywhere in the report */
    private const EARLIER = ['pre-existing', 'preexisting', 'previous', 'previously', 'prior'];
    /** a mention with these is an option discussed or a plate taken out ("can be addressed by a hook plate"): it does not count */
    private const NOT_USED = [
        'removed', 'removal', 'can', 'could', 'would', 'alternative', 'alternatives', 'alternatively', 'instead',
        'conventional', 'competitor',
    ];
    private const WINDOW = 6;
    /** how far before a mention the words of NOT_USED are looked for */
    private const NOT_USED_WINDOW = 12;

    /** @var array<string, array{0: list<string>, 1: list<string>}> key → [words, sections] */
    private array $families = [];
    /** @var array<string, list<string>> section → areas (lower case) */
    private array $areas = [];
    /** @var array<string, array{headed: bool, size: string}> CCS sections */
    private array $ccs = [];

    /**
     * @param array<string, list<string>> $sectionAreas section title → body areas of its articles
     */
    public function __construct(array $sectionAreas)
    {
        foreach ($sectionAreas as $section => $areas) {
            $section = (string)$section;
            $this->areas[$section] = array_values(array_map('mb_strtolower', $areas));
            if (preg_match('/^(headed)?CCS (\d\.\d)\b/i', $section, $match) === 1) {
                $this->ccs[$section] = ['headed' => $match[1] !== '', 'size' => $match[2]];
                continue;
            }
            if (preg_match('/^(\d\.\d) Cannulated Compression Screws\b/i', $section, $match) === 1) {
                $this->ccs[$section] = ['headed' => false, 'size' => $match[1]];
                continue;
            }
            if (preg_match('/\bplates?\b/i', $section) !== 1) {
                continue;
            }
            $words = [];
            foreach ($this->words($section) as $word) {
                if (!in_array($word, self::GENERIC, true) && (mb_strlen($word) > 2 || in_array($word, self::SHORT_KEEP, true))) {
                    $words[$word] = true;
                }
            }
            $words = array_keys($words);
            if ($words === [] || array_diff($words, self::WEAK) === []) {
                continue;
            }
            sort($words);
            $key = implode(' ', $words);
            $this->families[$key][0] = $words;
            $this->families[$key][1][] = $section;
        }
    }

    /**
     * @param list<string> $reportAreas body areas of the report (file metadata), may be empty
     * @return list<string> the sections the report is about
     */
    public function match(string $text, array $reportAreas): array
    {
        $reportAreas = array_values(array_filter(array_map(static fn (string $a): string => mb_strtolower(trim($a)), $reportAreas)));
        $text = mb_strtolower(str_replace(["\u{2013}", "\u{2011}"], '-', $text));
        $tokens = $this->words($text);
        $sections = [];
        $notUsed = [];

        foreach ($tokens as $index => $token) {
            if (!in_array($token, self::ANCHORS, true)) {
                continue;
            }
            $window = array_slice($tokens, max(0, $index - self::WINDOW), min($index, self::WINDOW));
            $best = 0;
            $hits = [];
            foreach ($this->families as [$words, $familySections]) {
                if (!$this->named($words, $window)) {
                    continue;
                }
                // the most specific family wins: most of its words in the report
                $score = count(array_intersect($words, $window));
                if ($score > $best) {
                    $best = $score;
                    $hits = [];
                }
                if ($score === $best) {
                    array_push($hits, ...$familySections);
                }
            }
            $before = array_slice($tokens, max(0, $index - self::NOT_USED_WINDOW), min($index, self::NOT_USED_WINDOW));
            // a plate that was there before does not count anywhere in the report
            if (array_intersect($window, self::EARLIER) !== []) {
                foreach ($hits as $section) {
                    $notUsed[$section] = true;
                }
                continue;
            }
            // an option discussed or a plate taken out: not this mention
            if (array_intersect($before, self::NOT_USED) !== []) {
                continue;
            }
            // then the area: the winner of another area is not replaced by the next best
            $hits = array_values(array_filter($hits, fn (string $section): bool => $this->areaFits($section, $reportAreas)));
            foreach ($hits as $section) {
                $sections[$section] = true;
            }
        }

        $sections = array_diff_key($sections, $notUsed);
        foreach ($this->ccsSections($text, $reportAreas) as $section) {
            $sections[$section] = true;
        }
        foreach (array_intersect($reportAreas, self::PRODUCT_AREAS) as $product) {
            foreach (array_keys($this->areas) as $section) {
                if (preg_match('/(?<![a-z])' . preg_quote($product, '/') . '(?![a-z])/i', $section) === 1) {
                    $sections[$section] = true;
                }
            }
        }

        $sections = array_keys($sections);
        sort($sections);

        return $sections;
    }

    /**
     * @param list<string> $reportAreas
     * @return list<string>
     */
    private function ccsSections(string $text, array $reportAreas): array
    {
        if ($this->ccs === [] || preg_match_all('/(?<![a-z])(headed)?ccs(?![a-z])/', $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }
        $wanted = [];
        $anySize = false;
        foreach ($matches[0] as $index => [, $offset]) {
            $before = substr($text, max(0, $offset - 60), min($offset, 60));
            $after = substr($text, $offset, 20);
            $headed = $matches[1][$index][0] !== '' || (str_contains($before, 'headed') && !str_contains($before, 'headless'));
            // the size nearest to the mention: "5.0 mm … ccs" or "ccs 3.0"
            if (preg_match('/^(?:headed)?ccs\s*(\d\.\d)/', $after, $size) === 1
                || preg_match('/(\d\.\d)\s*(?:mm)?[^.\d]{0,40}$/', $before, $size) === 1) {
                $wanted[($headed ? 'h' : 'c') . $size[1]] = true;
            } else {
                $anySize = true;
            }
        }
        $aboutCcs = in_array('ccs', $reportAreas, true);
        if ($wanted === [] && !($anySize && $aboutCcs)) {
            return [];
        }

        $sections = [];
        foreach ($this->ccs as $section => $family) {
            $fits = $wanted === []
                || isset($wanted[($family['headed'] ? 'h' : 'c') . $family['size']]);
            // the CCS articles carry the areas they are used in, a report about CCS the area "CCS"
            if ($fits && ($aboutCcs || $this->areaFits($section, $reportAreas))) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * Whether the words before an anchor name the family: those telling it apart have to be there, the location may
     * be left out ("step plate": only the calcaneus has one, "superior midshaft plate": only the clavicle). A family
     * named by its location only needs all but "distal" / "proximal", right before the anchor ("2.8 fibula plate",
     * not "… ulnar corner fracture. The plate").
     *
     * @param list<string> $words
     * @param list<string> $window
     */
    private function named(array $words, array $window): bool
    {
        $distinguishing = array_diff($words, self::WEAK, self::LOCATIONS);
        if ($distinguishing !== []) {
            return array_diff($distinguishing, $window) === [];
        }
        $required = array_values(array_diff($words, ['distal', 'proximal']));

        return array_diff($required, array_slice($window, -max(2, count($required)))) === [];
    }

    /**
     * @param list<string> $reportAreas
     */
    private function areaFits(string $section, array $reportAreas): bool
    {
        return $reportAreas === [] || array_intersect($reportAreas, $this->areas[$section] ?? []) !== [];
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        // "Clavicle Pl. Superior" in the abbreviated product names
        preg_match_all('/[a-z][a-z0-9\-]*/', (string)preg_replace('/\bpl\./', 'plate ', mb_strtolower($text)), $matches);
        $words = [];
        foreach ($matches[0] as $word) {
            // "MTP-1", "TMT-1" → "mtp", "tmt"
            $word = (string)preg_replace('/-\d+$/', '', $word);
            array_push($words, ...(self::SYNONYMS[$word] ?? [$word]));
        }

        return $words;
    }
}
