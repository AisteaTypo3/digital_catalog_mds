<?php

use Medartis\DigitalCatalog\Controller\Backend\SpecificationModuleController;

return [
    'digitalcatalog_specifications' => [
        'parent' => 'web',
        'position' => ['after' => 'docmanager'],
        'access' => 'user',
        'labels' => 'LLL:EXT:digital_catalog/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'DigitalCatalog',
        'iconIdentifier' => 'digital-catalog-plugin',
        'inheritNavigationComponentFromMainModule' => false,
        'controllerActions' => [
            SpecificationModuleController::class => [
                'overview',
                'productInformation',
                'caseReports',
                'editCaseReport',
                'updateCaseReport',
                'exportMissing',
            ],
        ],
    ],
];
