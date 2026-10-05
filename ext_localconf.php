<?php

declare(strict_types=1);

use Medartis\DigitalCatalog\Controller\CatalogController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

if (!defined('TYPO3')) {
    die('Access denied.');
}

(static function () {
    // Product documentation per system and language (ProductDocumentationService): reading the download-center
    // collections takes seconds, so the result is cached; flushed with the page caches
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['digitalcatalog_documents'] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
        'groups' => ['pages'],
        'options' => ['defaultLifetime' => 86400],
    ];

    ExtensionUtility::configurePlugin(
        'DigitalCatalog',
        'Catalog',
        [CatalogController::class => 'list, show, suggest, addToWishlist, removeFromWishlist, toggleWishlist'],
        [CatalogController::class => 'list, suggest, addToWishlist, removeFromWishlist, toggleWishlist'],
        null
    );

    ExtensionUtility::configurePlugin(
        'DigitalCatalog',
        'Wishlist',
        [CatalogController::class => 'wishlist, addToWishlist, removeFromWishlist, updateQuantity, submitInquiry'],
        [CatalogController::class => 'wishlist, addToWishlist, removeFromWishlist, updateQuantity, submitInquiry'],
        null
    );
})();
