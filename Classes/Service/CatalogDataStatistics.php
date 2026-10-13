<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Figures for the backend module about the data digitalcatalog:extract-specifications collects besides the
 * technical data: which articles are named in a product information PDF, which case reports are about which
 * implant families.
 */
final class CatalogDataStatistics
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly SpecificationRepository $specificationRepository,
        private readonly CaseReportRepository $caseReportRepository,
    ) {
    }

    /**
     * @return array{coverage: array<string, mixed>, documents: list<array<string, mixed>>, lastRun: int}
     */
    public function productInformation(string $systemFilter = ''): array
    {
        $articles = $this->specificationRepository->catalogArticles();
        $named = [];
        $documents = [];
        $lastRun = 0;
        foreach ($this->connectionPool->getConnectionForTable('tx_digitalcatalog_document_articles')
            ->select(['file_uid', 'articles', 'tstamp'], 'tx_digitalcatalog_document_articles')->fetchAllAssociative() as $row) {
            $lastRun = max($lastRun, (int)$row['tstamp']);
            $products = array_filter(explode(' ', trim((string)$row['articles'])));
            foreach ($products as $product) {
                $named[$product] = true;
            }
            $documents[] = $this->file((int)$row['file_uid']) + ['products' => array_values($products), 'read' => (int)$row['tstamp']];
        }

        // per document: the catalog articles it names (incl. the sterile / package variants)
        $byProduct = [];
        foreach (array_keys($articles) as $number) {
            $byProduct[DocumentArticleRepository::product((string)$number)][] = (string)$number;
        }
        $has = [];
        foreach ($articles as $number => $article) {
            if (isset($named[DocumentArticleRepository::product((string)$number)])) {
                $has[(string)$number] = true;
            }
        }
        foreach ($documents as &$document) {
            $count = 0;
            foreach ($document['products'] as $product) {
                $count += count($byProduct[$product] ?? []);
            }
            $document['articles'] = $count;
            unset($document['products']);
        }
        unset($document);
        usort($documents, static fn (array $a, array $b): int => [$b['articles'], $a['title']] <=> [$a['articles'], $b['title']]);

        return [
            'coverage' => SpecificationRepository::coverage($articles, $has, $systemFilter),
            'documents' => $documents,
            'lastRun' => $lastRun,
        ];
    }

    /**
     * @return array{reports: list<array<string, mixed>>, matched: int, articlesWithReport: int, lastRun: int}
     */
    public function caseReports(): array
    {
        $articles = $this->specificationRepository->catalogArticles();
        $articlesBySection = [];
        foreach ($this->connectionPool->getConnectionForTable('tx_digitalcatalog_specification')
            ->select(['article_number', 'section'], 'tx_digitalcatalog_specification')->fetchAllAssociative() as $row) {
            if (isset($articles[(string)$row['article_number']])) {
                $articlesBySection[(string)$row['section']][] = (string)$row['article_number'];
            }
        }

        $reports = [];
        $withReport = [];
        $matched = 0;
        $lastRun = 0;
        foreach ($this->connectionPool->getConnectionForTable('tx_digitalcatalog_casereport')
            ->select(['file_uid', 'sections', 'manual_sections', 'excluded_sections', 'tstamp'], 'tx_digitalcatalog_casereport')->fetchAllAssociative() as $row) {
            $lastRun = max($lastRun, (int)$row['tstamp']);
            $sections = CaseReportRepository::effective($row);
            $edited = trim((string)$row['manual_sections'], '[] ') !== '' || trim((string)$row['excluded_sections'], '[] ') !== '';
            $count = 0;
            foreach ($sections as $section) {
                foreach ($articlesBySection[$section] ?? [] as $number) {
                    $withReport[$number] = true;
                    $count++;
                }
            }
            $matched += $sections !== [] ? 1 : 0;
            $reports[] = $this->file((int)$row['file_uid']) + ['uid' => (int)$row['file_uid'], 'sections' => $sections, 'articles' => $count, 'edited' => $edited];
        }
        usort($reports, static fn (array $a, array $b): int => [$a['sections'] === [], $a['title']] <=> [$b['sections'] === [], $b['title']]);

        return [
            'reports' => $reports,
            'matched' => $matched,
            'articlesWithReport' => count($withReport),
            'lastRun' => $lastRun,
        ];
    }

    /**
     * The implant families (sections of the technical data) by body area, with their number of catalog articles, for
     * assigning case reports by hand.
     *
     * @return array<string, list<array{title: string, articles: int}>> area → families
     */
    public function familiesByArea(): array
    {
        $articles = $this->specificationRepository->catalogArticles();
        $count = [];
        foreach ($this->connectionPool->getConnectionForTable('tx_digitalcatalog_specification')
            ->select(['article_number', 'section'], 'tx_digitalcatalog_specification')->fetchAllAssociative() as $row) {
            if ((string)$row['section'] !== '' && isset($articles[(string)$row['article_number']])) {
                $count[(string)$row['section']] = ($count[(string)$row['section']] ?? 0) + 1;
            }
        }
        $areas = $this->caseReportRepository->sectionAreas();
        $byArea = [];
        foreach ($count as $section => $articleCount) {
            $sectionAreas = $areas[$section] ?? [];
            sort($sectionAreas);
            // one group per family: its first area, families used in many areas under "Several areas"
            $area = match (count($sectionAreas)) {
                0 => 'Other',
                1, 2 => $sectionAreas[0],
                default => 'Several areas',
            };
            $byArea[$area][] = ['title' => $section, 'articles' => $articleCount];
        }
        ksort($byArea);
        foreach ($byArea as &$families) {
            usort($families, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
        }
        unset($families);

        return $byArea;
    }

    /**
     * @return array{title: string, url: string, type: string, language: string, areas: string, identifier: string}
     */
    public function file(int $uid): array
    {
        try {
            $file = $this->resourceFactory->getFileObject($uid);
            $title = trim((string)($file->getProperty('title') ?? ''));

            return [
                'title' => $title !== '' ? $title : $file->getName(),
                'url' => (string)$file->getPublicUrl(),
                'type' => trim((string)($file->getProperty('filetype') ?? '')),
                'language' => trim((string)($file->getProperty('sprache') ?? '')),
                'areas' => trim((string)($file->getProperty('system') ?? '')),
                'identifier' => $file->getIdentifier(),
            ];
        } catch (\Throwable) {
            return ['title' => '#' . $uid, 'url' => '', 'type' => '', 'language' => '', 'areas' => '', 'identifier' => ''];
        }
    }
}
