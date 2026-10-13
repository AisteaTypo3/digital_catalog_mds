<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Technical data per article (tx_digitalcatalog_specification) and what was read from each PDF
 * (tx_digitalcatalog_specification_source), both written by digitalcatalog:extract-specifications.
 */
final class SpecificationRepository
{
    private const TABLE = 'tx_digitalcatalog_specification';
    private const SOURCE_TABLE = 'tx_digitalcatalog_specification_source';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {
    }

    /**
     * @return array{section: string, attributes: array<int, array{label: string, value: string}>, sourceUrl: string, sourceTitle: string, page: int}|null
     */
    public function findByArticleNumber(string $articleNumber): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('article_number', $queryBuilder->createNamedParameter($articleNumber)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }

        $attributes = [];
        foreach ((array)json_decode((string)$row['attributes'], true) as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1])) {
                $attributes[] = ['label' => (string)$pair[0], 'value' => (string)$pair[1]];
            }
        }

        $sourceUrl = '';
        $sourceTitle = '';
        try {
            $file = $this->resourceFactory->getFileObject((int)$row['source_file']);
            $sourceUrl = (string)$file->getPublicUrl();
            $sourceTitle = trim((string)($file->getProperty('title') ?? '')) ?: $file->getName();
        } catch (\Throwable) {
            // file removed meanwhile: show the data without a source link
        }

        return [
            'section' => (string)$row['section'],
            'attributes' => $attributes,
            'sourceUrl' => $sourceUrl,
            'sourceTitle' => $sourceTitle,
            'page' => (int)$row['source_page'],
        ];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // What was read from each PDF

    /**
     * @return array<int, array{hash: string, version: int, records: array<int, array<string, mixed>>}> file uid → cache
     */
    public function findSources(): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::SOURCE_TABLE)
            ->select(['file_uid', 'file_hash', 'parser_version', 'records'], self::SOURCE_TABLE)
            ->fetchAllAssociative();
        $sources = [];
        foreach ($rows as $row) {
            $sources[(int)$row['file_uid']] = [
                'hash' => (string)$row['file_hash'],
                'version' => (int)$row['parser_version'],
                'records' => (array)json_decode((string)$row['records'], true),
            ];
        }

        return $sources;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function saveSource(int $fileUid, string $hash, int $version, array $records): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::SOURCE_TABLE);
        $data = [
            'file_hash' => $hash,
            'parser_version' => $version,
            'records' => (string)json_encode($records, JSON_UNESCAPED_UNICODE),
            'row_count' => count($records),
            'tstamp' => time(),
        ];
        $updated = $connection->update(self::SOURCE_TABLE, $data, ['file_uid' => $fileUid]);
        if ($updated === 0) {
            $connection->insert(self::SOURCE_TABLE, $data + ['file_uid' => $fileUid]);
        }
    }

    /**
     * Forgets the PDFs that are no longer in the file list (deleted, archived, other type).
     *
     * @param array<int, int> $keepFileUids
     */
    public function removeSourcesExcept(array $keepFileUids): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SOURCE_TABLE);
        $queryBuilder->delete(self::SOURCE_TABLE);
        if ($keepFileUids !== []) {
            $queryBuilder->where($queryBuilder->expr()->notIn(
                'file_uid',
                $queryBuilder->createNamedParameter(array_values($keepFileUids), \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ));
        }

        return (int)$queryBuilder->executeStatement();
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Overview for the backend module

    /**
     * The articles of the doc_manager by article number, with their systems (combined titles and "N/A" are import
     * errors of the doc_manager and left out).
     *
     * @return array<string, array{name: string, systems: array<string, true>}>
     */
    public function catalogArticles(): array
    {
        $articleBuilder = $this->connectionPool->getQueryBuilderForTable('tx_docmanager_domain_model_article');
        $articleBuilder->getRestrictions()->removeAll();
        $articles = [];
        foreach ($articleBuilder
            ->select('a.article_number', 'a.product_name', 's.title AS system')
            ->from('tx_docmanager_domain_model_article', 'a')
            ->leftJoin('a', 'tx_docmanager_system_article_mm', 'mm', 'mm.uid_local = a.uid')
            ->leftJoin('mm', 'tx_docmanager_domain_model_system', 's', 's.uid = mm.uid_foreign AND s.deleted = 0')
            ->where(
                $articleBuilder->expr()->eq('a.deleted', 0),
                $articleBuilder->expr()->in('a.sys_language_uid', [0, -1]),
                $articleBuilder->expr()->neq('a.article_number', $articleBuilder->createNamedParameter(''))
            )
            ->executeQuery()
            ->fetchAllAssociative() as $row) {
            $number = trim((string)$row['article_number']);
            $articles[$number] ??= ['name' => (string)$row['product_name'], 'systems' => []];
            $system = trim((string)($row['system'] ?? ''));
            if ($system !== '' && $system !== 'N/A' && !str_contains($system, ', ')) {
                $articles[$number]['systems'][$system] = true;
            }
        }

        return $articles;
    }

    /**
     * Coverage per system and the articles without, for the given article numbers that have something.
     *
     * @param array<string, array{name: string, systems: array<string, true>}> $articles
     * @param array<string, true> $has article numbers having it
     * @return array{total: int, with: int, without: int, percent: int, systems: list<array<string, mixed>>, missing: list<array<string, string>>}
     */
    public static function coverage(array $articles, array $has, string $systemFilter = ''): array
    {
        $systems = [];
        $missing = [];
        $with = 0;
        foreach ($articles as $number => $article) {
            $number = (string)$number;
            $hasIt = isset($has[$number]);
            $with += $hasIt ? 1 : 0;
            $titles = $article['systems'] !== [] ? array_keys($article['systems']) : ['—'];
            foreach ($titles as $title) {
                $systems[$title] ??= ['title' => $title, 'total' => 0, 'with' => 0];
                $systems[$title]['total']++;
                if ($hasIt) {
                    $systems[$title]['with']++;
                }
            }
            if (!$hasIt && ($systemFilter === '' || in_array($systemFilter, $titles, true))) {
                $missing[] = ['number' => $number, 'name' => $article['name'], 'systems' => implode(', ', $titles)];
            }
        }
        foreach ($systems as &$system) {
            $system['without'] = $system['total'] - $system['with'];
            // every system listed has at least one article
            $system['percent'] = (int)round($system['with'] * 100 / $system['total']);
            $system['barClass'] = $system['percent'] < 50 ? 'bg-danger' : ($system['percent'] < 90 ? 'bg-warning' : 'bg-success');
        }
        unset($system);
        $systems = array_values($systems);
        usort($systems, static fn (array $a, array $b): int => [$b['without'], $a['title']] <=> [$a['without'], $b['title']]);
        usort($missing, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return [
            'total' => count($articles),
            'with' => $with,
            'without' => count($articles) - $with,
            'percent' => count($articles) > 0 ? (int)round($with * 100 / count($articles)) : 0,
            'systems' => $systems,
            'missing' => $missing,
        ];
    }

    /**
     * Articles of the doc_manager with and without technical data, per system, and the PDFs that supply them.
     *
     * @return array{total: int, withData: int, withoutData: int, lastRun: int, systems: array<int, array<string, mixed>>, missing: array<int, array<string, string>>, sources: array<int, array<string, mixed>>}
     */
    public function statistics(string $systemFilter = ''): array
    {
        $articles = $this->catalogArticles();

        $withData = array_fill_keys(
            array_map('strval', $this->connectionPool->getConnectionForTable(self::TABLE)
                ->select(['article_number'], self::TABLE)->fetchFirstColumn()),
            true
        );

        $coverage = self::coverage($articles, $withData, $systemFilter);

        // PDFs: rows read, articles they supply, last read
        $supplied = [];
        foreach ($this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['source_file'], self::TABLE)->fetchFirstColumn() as $fileUid) {
            $supplied[(int)$fileUid] = ($supplied[(int)$fileUid] ?? 0) + 1;
        }
        $sources = [];
        $lastRun = 0;
        foreach ($this->connectionPool->getConnectionForTable(self::SOURCE_TABLE)
            ->select(['file_uid', 'row_count', 'tstamp'], self::SOURCE_TABLE)->fetchAllAssociative() as $row) {
            $lastRun = max($lastRun, (int)$row['tstamp']);
            $uid = (int)$row['file_uid'];
            $identifier = '';
            $url = '';
            try {
                $file = $this->resourceFactory->getFileObject($uid);
                $identifier = $file->getIdentifier();
                $url = (string)$file->getPublicUrl();
            } catch (\Throwable) {
                $identifier = '#' . $uid;
            }
            $sources[] = [
                'identifier' => $identifier,
                'url' => $url,
                'rows' => (int)$row['row_count'],
                'supplied' => $supplied[$uid] ?? 0,
                'read' => (int)$row['tstamp'],
            ];
        }
        usort($sources, static fn (array $a, array $b): int => [$b['supplied'], $b['rows']] <=> [$a['supplied'], $a['rows']]);

        return [
            'total' => $coverage['total'],
            'withData' => $coverage['with'],
            'withoutData' => $coverage['without'],
            'lastRun' => $lastRun,
            'systems' => $coverage['systems'],
            'missing' => $coverage['missing'],
            'sources' => $sources,
        ];
    }

    // -----------------------------------------------------------------------------------------------------------------
    // Technical data per article: only the differences are written

    /**
     * @return array<string, array{uid: int, signature: string}> article number → stored row
     */
    public function findAllSignatures(): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['uid', 'article_number', 'section', 'attributes', 'source_file', 'source_page'], self::TABLE)
            ->fetchAllAssociative();
        $stored = [];
        foreach ($rows as $row) {
            $stored[(string)$row['article_number']] = [
                'uid' => (int)$row['uid'],
                'signature' => self::signature(
                    (string)$row['section'],
                    (string)$row['attributes'],
                    (int)$row['source_file'],
                    (int)$row['source_page']
                ),
            ];
        }

        return $stored;
    }

    public static function signature(string $section, string $attributesJson, int $file, int $page): string
    {
        return sha1($section . "\0" . $attributesJson . "\0" . $file . "\0" . $page);
    }

    /**
     * @param array<int, array{0: string, 1: string}|mixed> $attributes label/value pairs
     */
    public static function encodeAttributes(array $attributes): string
    {
        return (string)json_encode($attributes, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, array{section: string, attributes: string, file: int, page: int}> $inserts article number → data
     * @param array<int, array{section: string, attributes: string, file: int, page: int}> $updates uid → data
     * @param array<int, int> $deletes uids
     */
    public function applyChanges(array $inserts, array $updates, array $deletes): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->beginTransaction();
        try {
            $now = time();
            foreach ($inserts as $articleNumber => $data) {
                $connection->insert(self::TABLE, [
                    'article_number' => $articleNumber,
                    'section' => mb_substr($data['section'], 0, 255),
                    'attributes' => $data['attributes'],
                    'source_file' => $data['file'],
                    'source_page' => $data['page'],
                    'tstamp' => $now,
                ]);
            }
            foreach ($updates as $uid => $data) {
                $connection->update(self::TABLE, [
                    'section' => mb_substr($data['section'], 0, 255),
                    'attributes' => $data['attributes'],
                    'source_file' => $data['file'],
                    'source_page' => $data['page'],
                    'tstamp' => $now,
                ], ['uid' => $uid]);
            }
            foreach (array_chunk($deletes, 500) as $chunk) {
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder->delete(self::TABLE)
                    ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, \Doctrine\DBAL\ArrayParameterType::INTEGER)))
                    ->executeStatement();
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}
