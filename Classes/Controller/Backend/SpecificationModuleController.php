<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Controller\Backend;

use Medartis\DigitalCatalog\Service\CaseReportRepository;
use Medartis\DigitalCatalog\Service\CatalogDataStatistics;
use Medartis\DigitalCatalog\Service\DemandRepository;
use Medartis\DigitalCatalog\Service\SpecificationRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend module "Digital Catalog – Data": what digitalcatalog:extract-specifications collected for the article
 * pages, in three tabs:
 * - technical data from the catalog PDFs: coverage per system, articles without (also as CSV), source PDFs
 * - product information: which articles are named in a product information PDF, per system and per PDF
 * - case reports: which reports are about which implant families, and which are not matched; the families of a
 *   report can be set by hand (whole families, not article by article)
 * - demand: articles asked for, put on the wishlist and searches without result (counters without personal data)
 */
final class SpecificationModuleController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SpecificationRepository $specificationRepository,
        private readonly CatalogDataStatistics $catalogDataStatistics,
        private readonly CaseReportRepository $caseReportRepository,
        private readonly DemandRepository $demandRepository,
    ) {
    }

    public function overviewAction(string $system = ''): ResponseInterface
    {
        $statistics = $this->specificationRepository->statistics($system);

        return $this->render('Overview', 'technical', [
            'statistics' => $statistics,
            'percent' => $statistics['total'] > 0 ? (int)round($statistics['withData'] * 100 / $statistics['total']) : 0,
            'system' => $system,
            'missing' => array_slice($statistics['missing'], 0, 500),
            'missingTotal' => count($statistics['missing']),
        ], $statistics);
    }

    public function productInformationAction(string $system = ''): ResponseInterface
    {
        $productInformation = $this->catalogDataStatistics->productInformation($system);

        return $this->render('ProductInformation', 'productInformation', [
            'productInformation' => $productInformation,
            'system' => $system,
            'missing' => array_slice($productInformation['coverage']['missing'], 0, 500),
            'missingTotal' => count($productInformation['coverage']['missing']),
        ], null, $productInformation);
    }

    public function caseReportsAction(): ResponseInterface
    {
        $caseReports = $this->catalogDataStatistics->caseReports();

        return $this->render('CaseReports', 'caseReports', [
            'caseReports' => $caseReports,
            'unmatched' => count($caseReports['reports']) - $caseReports['matched'],
        ], null, null, $caseReports);
    }

    /**
     * @param int $months the last months (0: all kept)
     */
    public function demandAction(int $months = 12): ResponseInterface
    {
        $demand = $this->demandRepository->statistics(
            $months > 0 ? DemandRepository::monthsAgo($months) : 0,
            $this->specificationRepository->catalogArticles()
        );

        return $this->render('Demand', 'demand', [
            'demand' => $demand,
            'months' => $months,
            'inquired' => array_slice($demand['inquired'], 0, 50),
            'wishlisted' => array_slice($demand['wishlisted'], 0, 50),
            'terms' => array_slice($demand['terms'], 0, 50),
            'periods' => [3 => 'Last 3 months', 12 => 'Last 12 months', 0 => 'All (up to 24 months)'],
            'termGroups' => $this->demandRepository->termGroups(),
        ]);
    }

    public function saveTermGroupsAction(string $termGroups = '', int $months = 12): ResponseInterface
    {
        $this->demandRepository->saveTermGroups($termGroups);
        $this->addFlashMessage('The searches are grouped by the new term groups, also those counted before.', 'Term groups saved', ContextualFeedbackSeverity::OK);

        return $this->redirect('demand', null, null, ['months' => $months]);
    }

    public function exportDemandAction(int $months = 12): ResponseInterface
    {
        $demand = $this->demandRepository->statistics(
            $months > 0 ? DemandRepository::monthsAgo($months) : 0,
            $this->specificationRepository->catalogArticles()
        );
        $wishlist = array_column($demand['wishlisted'], 'hits', 'number');
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the CSV', 1791900002);
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Article number', 'Product name', 'Systems', 'Inquiries', 'Quantity asked for', 'Countries', 'Put on the wishlist'], ';');
        foreach ($demand['inquired'] as $row) {
            fputcsv($handle, [$row['number'], $row['name'], $row['systems'], $row['hits'], $row['quantity'], $row['countries'], $wishlist[$row['number']] ?? 0], ';');
            unset($wishlist[$row['number']]);
        }
        foreach ($demand['wishlisted'] as $row) {
            if (isset($wishlist[$row['number']])) {
                fputcsv($handle, [$row['number'], $row['name'], $row['systems'], 0, 0, '', $row['hits']], ';');
            }
        }
        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="catalog-demand-' . ($months > 0 ? $months . '-months' : 'all') . '.csv"')
            ->withBody($this->streamFactory->createStream($csv));
    }

    /**
     * The families of one case report: all families by area, ticked those the report is shown for now.
     */
    public function editCaseReportAction(int $file): ResponseInterface
    {
        $assignment = $this->caseReportRepository->findAssignment($file);
        if ($assignment === null) {
            return $this->redirect('caseReports');
        }
        $current = CaseReportRepository::effective([
            'sections' => json_encode($assignment['found']),
            'manual_sections' => json_encode($assignment['manual']),
            'excluded_sections' => json_encode($assignment['excluded']),
        ]);
        $groups = [];
        foreach ($this->catalogDataStatistics->familiesByArea() as $area => $families) {
            $checked = 0;
            foreach ($families as &$family) {
                $family['checked'] = in_array($family['title'], $current, true);
                $family['found'] = in_array($family['title'], $assignment['found'], true);
                $checked += $family['checked'] ? 1 : 0;
            }
            unset($family);
            $groups[] = ['area' => $area, 'families' => $families, 'checked' => $checked];
        }

        return $this->render('EditCaseReport', 'caseReports', [
            'file' => $file,
            'report' => $this->catalogDataStatistics->file($file),
            'assignment' => $assignment,
            'current' => $current,
            'groups' => $groups,
        ]);
    }

    /**
     * @param list<string> $families the families ticked
     */
    public function updateCaseReportAction(int $file, array $families = []): ResponseInterface
    {
        $assignment = $this->caseReportRepository->findAssignment($file);
        if ($assignment !== null) {
            $families = array_values(array_unique(array_map('strval', $families)));
            // stored as the difference to what the matching finds, so a later run does not undo it
            $this->caseReportRepository->saveAssignment(
                $file,
                array_values(array_diff($families, $assignment['found'])),
                array_values(array_diff($assignment['found'], $families))
            );
            $this->addFlashMessage(
                sprintf('%s: %d implant famil%s.', $this->catalogDataStatistics->file($file)['title'], count($families), count($families) === 1 ? 'y' : 'ies'),
                'Case report saved',
                ContextualFeedbackSeverity::OK
            );
        }

        return $this->redirect('caseReports');
    }

    /**
     * @param string $kind "technical" or "productInformation"
     */
    public function exportMissingAction(string $system = '', string $kind = 'technical'): ResponseInterface
    {
        $rows = $kind === 'productInformation'
            ? $this->catalogDataStatistics->productInformation($system)['coverage']['missing']
            : $this->specificationRepository->statistics($system)['missing'];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the CSV', 1791900001);
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Article number', 'Product name', 'Systems'], ';');
        foreach ($rows as $row) {
            fputcsv($handle, [$row['number'], $row['name'], $row['systems']], ';');
        }
        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        $filename = ($kind === 'productInformation' ? 'articles-without-product-information' : 'articles-without-technical-data')
            . ($system !== '' ? '-' . preg_replace('/[^a-z0-9]+/i', '-', $system) : '') . '.csv';

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withBody($this->streamFactory->createStream($csv));
    }

    /**
     * The tab with the figures of all three on top; the figures a tab has computed already are passed in.
     *
     * @param array<string, mixed> $variables
     * @param array<string, mixed>|null $technical
     * @param array<string, mixed>|null $productInformation
     * @param array<string, mixed>|null $caseReports
     */
    private function render(string $template, string $tab, array $variables, ?array $technical = null, ?array $productInformation = null, ?array $caseReports = null): ResponseInterface
    {
        $technical ??= $this->specificationRepository->statistics();
        $productInformation ??= $this->catalogDataStatistics->productInformation();
        $caseReports ??= $this->catalogDataStatistics->caseReports();

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Digital Catalog – Data');
        $moduleTemplate->assignMultiple($variables + [
            'tab' => $tab,
            'summary' => [
                'technical' => [
                    'with' => $technical['withData'],
                    'total' => $technical['total'],
                    'percent' => $technical['total'] > 0 ? (int)round($technical['withData'] * 100 / $technical['total']) : 0,
                ],
                'productInformation' => [
                    'with' => $productInformation['coverage']['with'],
                    'total' => $productInformation['coverage']['total'],
                    'percent' => $productInformation['coverage']['percent'],
                ],
                'caseReports' => [
                    'matched' => $caseReports['matched'],
                    'total' => count($caseReports['reports']),
                    'articles' => $caseReports['articlesWithReport'],
                ],
            ],
        ]);

        return $moduleTemplate->renderResponse('SpecificationModule/' . $template);
    }
}
