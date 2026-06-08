<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\ViewHelpers;

use Medartis\DigitalCatalog\Enum\ProductType;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns the detected ProductType value (string) for a given product name.
 *
 * Usage in template:
 *   {dc:productType(name: article.productName)}
 *
 *   <f:variable name="type">{dc:productType(name: article.productName)}</f:variable>
 *   <f:if condition="{type} == 'screw'">...</f:if>
 *   <span class="dc-badge dc-type--{dc:productType(name: article.productName)}">
 *     {dc:productType(name: article.productName, output: 'label')}
 *   </span>
 */
class ProductTypeViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('name', 'string', 'Product name to detect type from', true);
        $this->registerArgument(
            'output',
            'string',
            'What to return: "value" (default, e.g. "screw"), "label" (e.g. "Screw"), "css" (e.g. "dc-type--screw")',
            false,
            'value'
        );
    }

    public function render(): string
    {
        $type = ProductType::fromProductName((string)$this->arguments['name']);

        return match((string)$this->arguments['output']) {
            'label' => $type->label(),
            'css'   => $type->cssClass(),
            default => $type->value,
        };
    }
}
