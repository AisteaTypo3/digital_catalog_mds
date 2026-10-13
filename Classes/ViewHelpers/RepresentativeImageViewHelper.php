<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\ViewHelpers;

use Medartis\DocManager\Domain\Model\Article;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Whether the picture of an article only stands for it (another length, the product family, a placeholder) rather
 * than showing the article itself. The pictures are named after the article number they show:
 *
 *   A-4340.32 → A-4340.32.jpg, A-4340.32.2.jpg   the article itself
 *   A-4099.01/1, A-4099.01S → A-4099.01.jpg      the same implant, only packed differently (pieces, sterile)
 *   A-5200.18/1S → A-5200.10.jpg                 another length: representative
 *   A-4090.01S → A-4090.jpg, A-5150.07 → A-5150.xx.jpg   family picture or placeholder: representative
 *
 * Usage: <f:if condition="{dc:representativeImage(article: article)}">…</f:if>
 */
final class RepresentativeImageViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('article', Article::class, 'The article', true);
    }

    public function render(): bool
    {
        /** @var Article $article */
        $article = $this->arguments['article'];
        $image = $article->getImage();
        if ($image === null) {
            return false;
        }

        return !self::showsArticle((string)$image->getOriginalResource()->getName(), (string)$article->getArticleNumber());
    }

    public static function showsArticle(string $fileName, string $articleNumber): bool
    {
        $stem = strtoupper((string)preg_replace('/\.[a-z0-9]+$/i', '', trim($fileName)));
        // the pieces per package ("/1") and the sterile packing ("S") do not change the implant
        $product = strtoupper((string)preg_replace('/(?:\/\d+)?S?$/', '', trim($articleNumber)));
        if ($product === '') {
            return false;
        }

        // a further picture of the same article: "A-4340.32.2"
        return $stem === $product || $stem === strtoupper(trim($articleNumber))
            || preg_match('/^' . preg_quote($product, '/') . '\.\d$/', $stem) === 1;
    }
}
