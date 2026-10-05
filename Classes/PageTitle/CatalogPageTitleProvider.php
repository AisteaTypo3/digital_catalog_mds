<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\PageTitle;

use TYPO3\CMS\Core\PageTitle\AbstractPageTitleProvider;

/**
 * Page title of the catalog detail pages (product name and article number), set by CatalogController::showAction;
 * registered in config.pageTitleProviders (Configuration/TypoScript/setup.typoscript).
 */
final class CatalogPageTitleProvider extends AbstractPageTitleProvider
{
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
