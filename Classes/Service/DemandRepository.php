<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

use TYPO3\CMS\Core\Country\CountryProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;

/**
 * Demand for the catalog articles, counted per month (tx_digitalcatalog_demand):
 * - inquiry: an article asked for in the inquiry form, with the quantity and the country of the inquiry
 * - wishlist: an article put on the wishlist
 * - search: a search without result, with the search term
 *
 * Counters only, without personal data: nothing about the visitor (no name, e-mail, IP address, session, cookie,
 * time of day), a month as the finest period. Search terms that may hold personal data (an e-mail address, a phone
 * number, long numbers) are not kept.
 *
 * Search terms are kept normalised (lower case, no punctuation, article numbers written one way: "a4650,91" →
 * "A-4650.91") and grouped for the figures by the term groups maintained in the backend module ("lcp: synthes lcp,
 * lcp plate"), also for the terms counted before.
 */
final class DemandRepository
{
    public const INQUIRY = 'inquiry';
    public const WISHLIST = 'wishlist';
    public const SEARCH = 'search';

    private const TABLE = 'tx_digitalcatalog_demand';

    private const REGISTRY_NAMESPACE = 'digital_catalog';
    private const REGISTRY_TERM_GROUPS = 'demandTermGroups';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CountryProvider $countryProvider,
        private readonly Registry $registry,
    ) {
    }

    /**
     * @param array<string, int> $quantities article number → quantity
     */
    public function recordInquiry(array $quantities, string $country): void
    {
        $country = $this->countryProvider->getByAlpha2IsoCode($country)?->getAlpha2IsoCode() ?? '';
        foreach ($quantities as $articleNumber => $quantity) {
            $this->count(self::INQUIRY, (string)$articleNumber, $country, '', max(1, $quantity));
        }
    }

    public function recordWishlist(string $articleNumber): void
    {
        $this->count(self::WISHLIST, $articleNumber);
    }

    public function recordSearchWithoutResult(string $term): void
    {
        $term = self::cleanTerm($term);
        if ($term !== '') {
            $this->count(self::SEARCH, '', '', $term);
        }
    }

    /**
     * The search term as it is kept: lower case, single spaces, at most 60 characters; empty when it may hold
     * personal data.
     */
    public static function cleanTerm(string $term): string
    {
        $term = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $term)));
        if ($term === '' || mb_strlen($term) < 2
            || str_contains($term, '@')
            // a phone number or other long number: 8 digits or more in a row (sizes and article numbers have fewer)
            || preg_match('/(?:\d[\s\/().+-]*){8,}/u', $term) === 1
            || preg_match('/https?:|www\./u', $term) === 1) {
            return '';
        }
        // article numbers written one way: "a4650,91", "a 4650.91" → "A-4650.91"
        $term = (string)preg_replace_callback(
            '/(?<![\p{L}\d])([a-z]{1,2}2?)[\s-]?(\d{3,5})(?:\s?[.,]\s?(\d{1,3}))?(?![\d])/u',
            static fn (array $m): string => strtoupper($m[1]) . '-' . $m[2] . (($m[3] ?? '') !== '' ? '.' . $m[3] : ''),
            $term
        );
        // no punctuation but in article numbers and sizes ("2.8", "1.2/1.5")
        $term = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\p{N}\s.\/-]+/u', ' ', $term)));
        $term = trim($term, ' .-/');

        return mb_strlen($term) < 2 ? '' : mb_substr($term, 0, 60);
    }

    /**
     * The term groups as maintained in the backend module, one per line: "group: term, term".
     */
    public function termGroups(): string
    {
        return (string)$this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_TERM_GROUPS, '');
    }

    public function saveTermGroups(string $groups): void
    {
        $lines = [];
        foreach (preg_split('/\R/u', $groups) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$group, $terms] = explode(':', $line, 2);
                $terms = array_filter(array_map(static fn (string $t): string => self::cleanTerm($t), explode(',', $terms)));
                if (trim($group) !== '' && $terms !== []) {
                    $lines[] = trim($group) . ': ' . implode(', ', $terms);
                }
            }
        }
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_TERM_GROUPS, implode("\n", $lines));
    }

    /**
     * @return array<string, string> term → group
     */
    private function termGroupMap(): array
    {
        $map = [];
        foreach (preg_split('/\R/u', $this->termGroups()) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$group, $terms] = explode(':', $line, 2);
            foreach (explode(',', $terms) as $term) {
                $term = self::cleanTerm($term);
                if ($term !== '') {
                    $map[$term] = trim($group);
                }
            }
        }

        return $map;
    }

    /**
     * Figures since a month (YYYYMM, 0 for all).
     *
     * @param array<string, array{name: string, systems: array<string, true>}> $articles catalog articles
     * @return array<string, mixed>
     */
    public function statistics(int $since, array $articles): array
    {
        $rows = $this->rows($since);
        $groups = $this->termGroupMap();
        $inquired = $wishlisted = $countries = $systems = $searches = [];
        $inquiries = $wishlist = 0;
        foreach ($rows as $row) {
            $number = (string)$row['article_number'];
            $hits = (int)$row['hits'];
            switch ($row['type']) {
                case self::INQUIRY:
                    $inquired[$number] ??= ['number' => $number, 'hits' => 0, 'quantity' => 0, 'countries' => []];
                    $inquired[$number]['hits'] += $hits;
                    $inquired[$number]['quantity'] += (int)$row['quantity'];
                    if ($row['country'] !== '') {
                        $inquired[$number]['countries'][$row['country']] = true;
                        $countries[$row['country']] = ($countries[$row['country']] ?? 0) + $hits;
                    }
                    foreach (array_keys($articles[$number]['systems'] ?? ['—' => true]) as $system) {
                        $systems[$system] = ($systems[$system] ?? 0) + $hits;
                    }
                    $inquiries += $hits;
                    break;
                case self::WISHLIST:
                    $wishlisted[$number] ??= ['number' => $number, 'hits' => 0];
                    $wishlisted[$number]['hits'] += $hits;
                    $wishlist += $hits;
                    break;
                case self::SEARCH:
                    $term = $groups[(string)$row['term']] ?? (string)$row['term'];
                    $searches[$term] = ($searches[$term] ?? 0) + $hits;
                    break;
            }
        }

        $describe = static function (array $entry) use ($articles): array {
            $article = $articles[$entry['number']] ?? null;
            $entry['name'] = $article['name'] ?? '';
            $entry['systems'] = $article !== null ? implode(', ', array_keys($article['systems'])) : '';
            return $entry;
        };
        usort($inquired, static fn (array $a, array $b): int => [$b['hits'], $b['quantity']] <=> [$a['hits'], $a['quantity']]);
        usort($wishlisted, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
        $inquired = array_map(static function (array $entry) use ($describe): array {
            $entry['countries'] = implode(', ', array_keys($entry['countries']));
            return $describe($entry);
        }, $inquired);
        $wishlisted = array_map($describe, $wishlisted);

        arsort($countries);
        arsort($systems);
        arsort($searches);
        $countryRows = [];
        foreach ($countries as $code => $hits) {
            $countryRows[] = ['code' => $code, 'name' => $this->countryProvider->getByAlpha2IsoCode((string)$code)?->getName() ?? (string)$code, 'hits' => $hits];
        }

        return [
            'inquiries' => $inquiries,
            'wishlist' => $wishlist,
            'searches' => array_sum($searches),
            'inquired' => $inquired,
            'wishlisted' => $wishlisted,
            'countries' => $countryRows,
            'systems' => array_map(static fn (string $title, int $hits): array => ['title' => $title, 'hits' => $hits], array_keys($systems), $systems),
            'terms' => array_map(static fn (string $term, int $hits): array => ['term' => $term, 'hits' => $hits], array_map('strval', array_keys($searches)), $searches),
        ];
    }

    /**
     * Counters older than the given number of months are deleted.
     */
    public function purge(int $keepMonths): int
    {
        $since = (int)(new \DateTimeImmutable('first day of this month'))->modify('-' . max(1, $keepMonths) . ' months')->format('Ym');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('period', $queryBuilder->createNamedParameter($since, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeStatement();
    }

    public static function monthsAgo(int $months): int
    {
        return (int)(new \DateTimeImmutable('first day of this month'))->modify('-' . max(0, $months - 1) . ' months')->format('Ym');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $since): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->select('type', 'article_number', 'country', 'term', 'hits', 'quantity')->from(self::TABLE);
        if ($since > 0) {
            $queryBuilder->where($queryBuilder->expr()->gte('period', $queryBuilder->createNamedParameter($since, \Doctrine\DBAL\ParameterType::INTEGER)));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    private function count(string $type, string $articleNumber = '', string $country = '', string $term = '', int $quantity = 0): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $key = [
            'period' => (int)date('Ym'),
            'type' => $type,
            'article_number' => mb_substr(trim($articleNumber), 0, 64),
            'country' => strtoupper(mb_substr($country, 0, 2)),
            'term' => $term,
        ];
        // one row per month and entry, counted up
        $updated = $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(self::TABLE) . ' SET hits = hits + 1, quantity = quantity + ?'
            . ' WHERE period = ? AND type = ? AND article_number = ? AND country = ? AND term = ?',
            [$quantity, $key['period'], $key['type'], $key['article_number'], $key['country'], $key['term']]
        );
        if ($updated === 0) {
            try {
                $connection->insert(self::TABLE, $key + ['hits' => 1, 'quantity' => $quantity]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // inserted by a parallel request meanwhile: count up that row
                $connection->executeStatement(
                    'UPDATE ' . $connection->quoteIdentifier(self::TABLE) . ' SET hits = hits + 1, quantity = quantity + ?'
                    . ' WHERE period = ? AND type = ? AND article_number = ? AND country = ? AND term = ?',
                    [$quantity, $key['period'], $key['type'], $key['article_number'], $key['country'], $key['term']]
                );
            }
        }
    }
}
