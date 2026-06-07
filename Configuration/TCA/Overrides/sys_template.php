<?php

if (!defined('TYPO3')) {
    die('Access denied.');
}

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'digital_catalog',
    'Configuration/TypoScript/',
    'Digital Catalog'
);
