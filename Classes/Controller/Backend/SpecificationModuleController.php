<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Controller\Backend;

use Medartis\DigitalCatalog\Service\SpecificationRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend module "Digital Catalog – technical data": how many articles have technical data from the catalog PDFs
 * (digitalcatalog:extract-specifications), per system, which ones are missing (also as CSV) and which PDFs supply them.
 */
final class SpecificationModuleController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SpecificationRepository $specificationRepository,
    ) {
    }

    public function overviewAction(string $system = ''): ResponseInterface
    {
        $statistics = $this->specificationRepository->statistics($system);
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Digital Catalog – Technical data');
        $moduleTemplate->assignMultiple([
            'statistics' => $statistics,
            'percent' => $statistics['total'] > 0 ? (int)round($statistics['withData'] * 100 / $statistics['total']) : 0,
            'system' => $system,
            'missing' => array_slice($statistics['missing'], 0, 500),
            'missingTotal' => count($statistics['missing']),
        ]);

        return $moduleTemplate->renderResponse('SpecificationModule/Overview');
    }

    public function exportMissingAction(string $system = ''): ResponseInterface
    {
        $rows = $this->specificationRepository->statistics($system)['missing'];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Article number', 'Product name', 'Systems'], ';');
        foreach ($rows as $row) {
            fputcsv($handle, [$row['number'], $row['name'], $row['systems']], ';');
        }
        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        $filename = 'articles-without-technical-data' . ($system !== '' ? '-' . preg_replace('/[^a-z0-9]+/i', '-', $system) : '') . '.csv';

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withBody($this->streamFactory->createStream($csv));
    }
}
