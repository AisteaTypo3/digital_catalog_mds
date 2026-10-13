<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Case reports and the implant families they are about (tx_digitalcatalog_casereport), written by
 * digitalcatalog:extract-specifications.
 */
final class CaseReportRepository
{
    private const TABLE = 'tx_digitalcatalog_casereport';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {
    }

    /**
     * The case reports about the family (section) of an article.
     *
     * @return list<array{title: string, url: string, size: int, areas: string}>
     */
    public function findBySection(string $section): array
    {
        $section = trim($section);
        if ($section === '') {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $like = $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($section) . '%');
        $rows = $queryBuilder
            ->select('file_uid', 'sections', 'manual_sections', 'excluded_sections')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->or(
                $queryBuilder->expr()->like('sections', $like),
                $queryBuilder->expr()->like('manual_sections', $like)
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        $reports = [];
        foreach ($rows as $row) {
            if (!in_array($section, self::effective($row), true)) {
                continue;
            }
            try {
                $file = $this->resourceFactory->getFileObject((int)$row['file_uid']);
                if ($file->isMissing()) {
                    continue;
                }
                $reports[] = [
                    'title' => trim((string)($file->getProperty('title') ?? '')) ?: $file->getNameWithoutExtension(),
                    'url' => (string)$file->getPublicUrl(),
                    'size' => (int)$file->getSize(),
                    'areas' => trim((string)($file->getProperty('system') ?? '')),
                ];
            } catch (\Throwable) {
                // file removed meanwhile
            }
        }
        usort($reports, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));

        return $reports;
    }

    /**
     * The families a report is shown for: found by the matching, without those taken out, with those added in the
     * backend module.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function effective(array $row): array
    {
        $found = self::decode($row['sections'] ?? '');
        $excluded = self::decode($row['excluded_sections'] ?? '');
        $manual = self::decode($row['manual_sections'] ?? '');
        $sections = array_values(array_unique(array_merge(array_diff($found, $excluded), $manual)));
        sort($sections);

        return $sections;
    }

    /**
     * @return array{found: list<string>, manual: list<string>, excluded: list<string>}|null
     */
    public function findAssignment(int $fileUid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['sections', 'manual_sections', 'excluded_sections'], self::TABLE, ['file_uid' => $fileUid])
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }

        return [
            'found' => self::decode($row['sections']),
            'manual' => self::decode($row['manual_sections']),
            'excluded' => self::decode($row['excluded_sections']),
        ];
    }

    /**
     * The families added and taken out by hand (kept by the daily matching).
     *
     * @param list<string> $manual
     * @param list<string> $excluded
     */
    public function saveAssignment(int $fileUid, array $manual, array $excluded): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, [
            'manual_sections' => self::encode($manual),
            'excluded_sections' => self::encode($excluded),
        ], ['file_uid' => $fileUid]);
    }

    /**
     * @return array<int, array{hash: string, content: string}> file uid → stored text
     */
    public function findTexts(): array
    {
        $texts = [];
        foreach ($this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['file_uid', 'file_hash', 'content'], self::TABLE)->fetchAllAssociative() as $row) {
            $texts[(int)$row['file_uid']] = ['hash' => (string)$row['file_hash'], 'content' => (string)$row['content']];
        }

        return $texts;
    }

    /**
     * @param list<string> $sections
     */
    public function save(int $fileUid, string $hash, string $content, array $sections): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = [
            'file_hash' => $hash,
            'content' => $content,
            'sections' => self::encode($sections),
            'tstamp' => time(),
        ];
        if ($connection->update(self::TABLE, $data, ['file_uid' => $fileUid]) === 0) {
            $connection->insert(self::TABLE, $data + ['file_uid' => $fileUid]);
        }
    }

    /**
     * @param list<int> $keepFileUids
     */
    public function removeExcept(array $keepFileUids): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->delete(self::TABLE);
        if ($keepFileUids !== []) {
            $queryBuilder->where($queryBuilder->expr()->notIn(
                'file_uid',
                $queryBuilder->createNamedParameter($keepFileUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ));
        }

        return (int)$queryBuilder->executeStatement();
    }

    /**
     * @param list<string> $sections
     */
    private static function encode(array $sections): string
    {
        $sections = array_values(array_unique(array_filter(array_map('trim', $sections))));
        sort($sections);

        // unescaped, so findBySection() can search for the plain title ("Head/Neck")
        return (string)json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private static function decode(mixed $json): array
    {
        return array_values(array_map('strval', (array)json_decode((string)$json, true)));
    }

    /**
     * Section title → body areas of its articles, for the matcher.
     *
     * @return array<string, list<string>>
     */
    public function sectionAreas(): array
    {
        // two queries joined here: the tables may differ in collation
        $sectionByArticle = [];
        foreach ($this->connectionPool->getConnectionForTable('tx_digitalcatalog_specification')
            ->select(['article_number', 'section'], 'tx_digitalcatalog_specification')->fetchAllAssociative() as $row) {
            if ((string)$row['section'] !== '') {
                $sectionByArticle[(string)$row['article_number']] = (string)$row['section'];
            }
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_docmanager_domain_model_article');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('a.article_number', 'b.title')
            ->from('tx_docmanager_domain_model_article', 'a')
            ->join('a', 'tx_docmanager_bodysubregion_article_mm', 'm', 'm.uid_local = a.uid')
            ->join('m', 'tx_docmanager_domain_model_bodysubregion', 'b', 'b.uid = m.uid_foreign AND b.deleted = 0')
            ->where($queryBuilder->expr()->eq('a.deleted', 0))
            ->executeQuery()
            ->fetchAllAssociative();

        $areas = [];
        foreach ($rows as $row) {
            $section = $sectionByArticle[trim((string)$row['article_number'])] ?? null;
            if ($section !== null) {
                $areas[$section][(string)$row['title']] = true;
            }
        }
        $areas = array_map('array_keys', $areas);

        return $areas;
    }
}
