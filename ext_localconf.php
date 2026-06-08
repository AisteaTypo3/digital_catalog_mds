<?php

declare(strict_types=1);

use Medartis\DigitalCatalog\Controller\CatalogController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

if (!defined('TYPO3')) {
    die('Access denied.');
}

(static function () {
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
