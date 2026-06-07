<?php

if (!defined('TYPO3')) {
    die('Access denied.');
}

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'DigitalCatalog',
    'Catalog',
    'Digital Catalog',
    'digital-catalog-plugin'
);

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'DigitalCatalog',
    'Wishlist',
    'Digital Catalog: Wishlist',
    'digital-catalog-plugin'
);
