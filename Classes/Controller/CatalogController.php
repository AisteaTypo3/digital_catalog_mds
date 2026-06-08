<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Controller;

use GeorgRinger\NumberedPagination\NumberedPagination;
use Medartis\DigitalCatalog\Service\ProductDocumentationService;
use Medartis\DigitalCatalog\Service\WishlistService;
use Medartis\DocManager\Domain\Model\Article;
use Medartis\DocManager\Domain\Repository\ArticleRepository;
use Medartis\DocManager\Domain\Repository\SystemRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Pagination\QueryResultPaginator;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

class CatalogController extends ActionController
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly SystemRepository $systemRepository,
        private readonly ProductDocumentationService $productDocumentationService,
        private readonly WishlistService $wishlistService,
    ) {}

    public function listAction(): ResponseInterface
    {
        $search = trim($this->resolveStringArgument('search'));
        $systemFilter = $this->resolveIntArgument('system', 0);
        $currentPage = $this->resolveIntArgument('currentPage', 1, 1);
        $storagePid = (int)($this->settings['storagePid'] ?? 0);
        $itemsPerPage = (int)($this->settings['itemsPerPage'] ?? 24);

        $query = $this->articleRepository->createQuery();
        $query->getQuerySettings()->setRespectStoragePage($storagePid > 0);
        if ($storagePid > 0) {
            $query->getQuerySettings()->setStoragePageIds([$storagePid]);
        }

        $constraints = [];

        if ($search !== '') {
            $constraints[] = $query->logicalOr(
                $query->like('productName', '%' . $search . '%'),
                $query->like('articleNumber', '%' . $search . '%')
            );
        }

        if ($systemFilter > 0) {
            $system = $this->systemRepository->findByUid($systemFilter);
            if ($system !== null) {
                $constraints[] = $query->contains('systems', $system);
            }
        }

        if (!empty($constraints)) {
            $query->matching(count($constraints) > 1 ? $query->logicalAnd(...$constraints) : $constraints[0]);
        }

        $query->setOrderings(['productName' => QueryInterface::ORDER_ASCENDING]);

        $articles = $query->execute();

        $paginator = new QueryResultPaginator($articles, $currentPage, $itemsPerPage);
        $pagination = new NumberedPagination($paginator, 7);

        $systems = $this->findSystemsWithArticles($storagePid);
        $wishlistUids = $this->wishlistService->getWishlist();

        $this->view->assignMultiple([
            'paginator' => $paginator,
            'pagination' => $pagination,
            'articles' => $paginator->getPaginatedItems(),
            'totalCount' => $articles->count(),
            'systems' => $systems,
            'search' => $search,
            'systemFilter' => $systemFilter,
            'currentPage' => $currentPage,
            'wishlistUids' => $wishlistUids,
            'wishlistCount' => count($wishlistUids),
        ]);

        return $this->htmlResponse();
    }

    public function suggestAction(): ResponseInterface
    {
        $term = trim($this->resolveStringArgument('term'));
        $systemFilter = $this->resolveIntArgument('system', 0);
        $storagePid = (int)($this->settings['storagePid'] ?? 0);

        if (mb_strlen($term) < 2) {
            throw new PropagateResponseException(
                $this->jsonResponse((string)json_encode([
                    'items' => [],
                ])),
                1
            );
        }

        $query = $this->articleRepository->createQuery();
        $query->getQuerySettings()->setRespectStoragePage($storagePid > 0);
        if ($storagePid > 0) {
            $query->getQuerySettings()->setStoragePageIds([$storagePid]);
        }

        $constraints = [
            $query->logicalOr(
                $query->like('productName', '%' . $term . '%'),
                $query->like('articleNumber', '%' . $term . '%')
            ),
        ];

        if ($systemFilter > 0) {
            $system = $this->systemRepository->findByUid($systemFilter);
            if ($system !== null) {
                $constraints[] = $query->contains('systems', $system);
            }
        }

        $query->matching(count($constraints) > 1 ? $query->logicalAnd(...$constraints) : $constraints[0]);
        $query->setOrderings([
            'productName' => QueryInterface::ORDER_ASCENDING,
            'articleNumber' => QueryInterface::ORDER_ASCENDING,
        ]);
        $query->setLimit(8);

        $items = [];
        foreach ($query->execute() as $article) {
            $items[] = [
                'uid' => $article->getUid(),
                'title' => $article->getProductName(),
                'articleNumber' => $article->getArticleNumber(),
                'url' => $this->buildCatalogDetailUri($article),
            ];
        }

        throw new PropagateResponseException(
            $this->jsonResponse((string)json_encode([
                'items' => $items,
            ])),
            1
        );
    }

    public function showAction(Article $article): ResponseInterface
    {
        $wishlistUids = $this->wishlistService->getWishlist();

        $siteLanguage = $this->request->getAttribute('language');
        $pageLocale = strtolower($siteLanguage?->getLocale()->getLanguageCode() ?? 'en');

        $documents = [];
        foreach ($article->getDocuments() as $document) {
            if ($document->getArchived()) {
                continue;
            }
            $docLang = strtolower($document->getLanguage() ?: '');
            if ($docLang === $pageLocale || str_starts_with($docLang, $pageLocale . '-')) {
                $documents[] = $document;
            }
        }

        $productDocuments = $this->productDocumentationService->findBySystems(
            array_map(
                static fn($system): string => $system->getTitle(),
                iterator_to_array($article->getSystems())
            ),
            $pageLocale
        );

        $this->view->assignMultiple([
            'article' => $article,
            'documents' => $documents,
            'productDocuments' => $productDocuments,
            'pageLocale' => $pageLocale,
            'isInWishlist' => in_array($article->getUid(), $wishlistUids, true),
            'eifuPortalBaseUrl' => rtrim($this->settings['eifuPortalBaseUrl'] ?? 'https://medartis.com', '/'),
            'eifuPageUid' => (int)($this->settings['eifuPageUid'] ?? 0),
        ]);

        return $this->htmlResponse();
    }

    public function wishlistAction(): ResponseInterface
    {
        $quantities   = $this->wishlistService->getWishlistWithQuantities();
        $wishlistUids = array_keys($quantities);
        $articles     = !empty($wishlistUids) ? $this->articleRepository->findByUids($wishlistUids) : [];

        $articleItems = [];
        foreach ($articles as $article) {
            $articleItems[] = [
                'article' => $article,
                'qty'     => $quantities[$article->getUid()] ?? 1,
            ];
        }

        $this->view->assignMultiple([
            'articleItems'  => $articleItems,
            'wishlistUids'  => $wishlistUids,
            'wishlistCount' => count($wishlistUids),
            'submitted'     => $this->request->hasArgument('submitted') ? (bool)$this->request->getArgument('submitted') : false,
        ]);

        return $this->htmlResponse();
    }

    public function toggleWishlistAction(): ResponseInterface
    {
        $uid        = $this->request->hasArgument('articleUid') ? (int)$this->request->getArgument('articleUid') : 0;
        $inWishlist = false;

        if ($uid > 0) {
            if (in_array($uid, $this->wishlistService->getWishlist(), true)) {
                $this->wishlistService->removeFromWishlist($uid);
                $inWishlist = false;
            } else {
                $this->wishlistService->addToWishlist($uid);
                $inWishlist = true;
            }
        }

        if ($this->isAjaxRequest()) {
            throw new PropagateResponseException(
                $this->jsonResponse((string)json_encode([
                    'success'    => true,
                    'inWishlist' => $inWishlist,
                    'count'      => count($this->wishlistService->getWishlist()),
                ])),
                1
            );
        }

        $referer = $this->request->getHeaderLine('referer');
        return $referer ? $this->redirectToUri($referer) : $this->redirect('list');
    }

    public function updateQuantityAction(): ResponseInterface
    {
        $uid = $this->request->hasArgument('articleUid') ? (int)$this->request->getArgument('articleUid') : 0;
        $qty = $this->request->hasArgument('quantity') ? (int)$this->request->getArgument('quantity') : 1;
        if ($uid > 0) {
            $this->wishlistService->setQuantity($uid, max(1, $qty));
        }

        if ($this->isAjaxRequest()) {
            $quantities = $this->wishlistService->getWishlistWithQuantities();
            throw new PropagateResponseException(
                $this->jsonResponse((string)json_encode([
                    'success' => true,
                    'qty'     => $quantities[$uid] ?? 1,
                    'count'   => count($quantities),
                ])),
                1
            );
        }

        return $this->redirect('wishlist');
    }

    public function addToWishlistAction(): ResponseInterface
    {
        $uid = $this->request->hasArgument('articleUid') ? (int)$this->request->getArgument('articleUid') : 0;
        if ($uid > 0) {
            $this->wishlistService->addToWishlist($uid);
        }
        $referer = $this->request->getHeaderLine('referer');
        return $referer ? $this->redirectToUri($referer) : $this->redirect('list');
    }

    public function removeFromWishlistAction(): ResponseInterface
    {
        $uid = $this->request->hasArgument('articleUid') ? (int)$this->request->getArgument('articleUid') : 0;
        if ($uid > 0) {
            $this->wishlistService->removeFromWishlist($uid);
        }
        $referer = $this->request->getHeaderLine('referer');
        return $referer ? $this->redirectToUri($referer) : $this->redirect('wishlist');
    }

    private function isAjaxRequest(): bool
    {
        return $this->request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    public function submitInquiryAction(): ResponseInterface
    {
        $name = trim((string)($this->request->hasArgument('name') ? $this->request->getArgument('name') : ''));
        $email = trim((string)($this->request->hasArgument('email') ? $this->request->getArgument('email') : ''));
        $company = trim((string)($this->request->hasArgument('company') ? $this->request->getArgument('company') : ''));
        $message = trim((string)($this->request->hasArgument('message') ? $this->request->getArgument('message') : ''));

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlashMessage('Bitte fülle alle Pflichtfelder korrekt aus.', 'Fehler', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('wishlist');
        }

        $wishlistUids = $this->wishlistService->getWishlist();
        $articles = !empty($wishlistUids) ? $this->articleRepository->findByUids($wishlistUids) : [];

        $this->wishlistService->sendInquiryMail($name, $email, $company, $message, $articles);
        $this->wishlistService->clearWishlist();

        return $this->redirect('wishlist', null, null, ['submitted' => 1]);
    }

    private function resolveStringArgument(string $name, string $default = ''): string
    {
        if ($this->request->hasArgument($name)) {
            return (string)$this->request->getArgument($name);
        }

        $pluginArguments = $this->getPluginQueryArguments();
        if (isset($pluginArguments[$name])) {
            return (string)$pluginArguments[$name];
        }

        $routeArguments = $this->getRouteArguments();
        if (isset($routeArguments[$name])) {
            return (string)$routeArguments[$name];
        }

        $queryParams = $this->request->getQueryParams();
        if (isset($queryParams[$name])) {
            return (string)$queryParams[$name];
        }

        $pathArguments = $this->getArgumentsFromRequestPath();
        if (isset($pathArguments[$name])) {
            return (string)$pathArguments[$name];
        }

        return $default;
    }

    private function resolveIntArgument(string $name, int $default = 0, int $minimum = 0): int
    {
        $value = $this->resolveStringArgument($name, (string)$default);
        return max($minimum, (int)$value);
    }

    private function getPluginQueryArguments(): array
    {
        $queryParams = $this->request->getQueryParams();
        $pluginArguments = $queryParams['tx_digitalcatalog_catalog'] ?? [];
        return is_array($pluginArguments) ? $pluginArguments : [];
    }

    private function getRouteArguments(): array
    {
        $routing = $this->request->getAttribute('routing');
        if ($routing instanceof PageArguments) {
            $pluginArguments = $routing->get('tx_digitalcatalog_catalog');
            if (is_array($pluginArguments)) {
                return $pluginArguments;
            }

            $arguments = $routing->getArguments();
            return is_array($arguments) ? $arguments : [];
        }

        return [];
    }

    private function getArgumentsFromRequestPath(): array
    {
        $path = $this->request->getUri()->getPath();
        $arguments = [];

        if (preg_match('#/page-(\d+)$#', $path, $matches) === 1) {
            $arguments['currentPage'] = $matches[1];
        }

        if (preg_match('#/system/(\d+)#', $path, $matches) === 1) {
            $arguments['system'] = $matches[1];
        }

        return $arguments;
    }

    private function buildCatalogDetailUri(Article $article): string
    {
        $catalogPageUid = (int)($this->settings['catalogPageUid'] ?? 0);
        $uriBuilder = $this->uriBuilder
            ->reset()
            ->setCreateAbsoluteUri(false);

        if ($catalogPageUid > 0) {
            $uriBuilder->setTargetPageUid($catalogPageUid);
        }

        return $uriBuilder->uriFor(
            'show',
            ['article' => $article],
            'Catalog',
            'DigitalCatalog',
            'Catalog'
        );
    }

    /**
     * Returns only systems that have at least one article in the given storage PID.
     * Joins tx_docmanager_system_article_mm → article table filtered by pid.
     */
    private function findSystemsWithArticles(int $storagePid): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_docmanager_domain_model_system');

        $qb->getRestrictions()->removeAll();

        $query = $qb
            ->select('s.uid', 's.title')
            ->from('tx_docmanager_domain_model_system', 's')
            ->join('s', 'tx_docmanager_system_article_mm', 'mm', 'mm.uid_foreign = s.uid')
            ->join('mm', 'tx_docmanager_domain_model_article', 'a', 'a.uid = mm.uid_local')
            ->andWhere(
                $qb->expr()->eq('s.deleted', 0),
                $qb->expr()->eq('s.hidden', 0),
                $qb->expr()->eq('a.deleted', 0),
                $qb->expr()->eq('a.hidden', 0)
            )
            ->groupBy('s.uid', 's.title')
            ->orderBy('s.title', 'ASC');

        if ($storagePid > 0) {
            $query->andWhere($qb->expr()->eq('a.pid', $storagePid));
        }

        $rows = $query->executeQuery()->fetchAllAssociative();

        $systems = [];
        foreach ($rows as $row) {
            $system = $this->systemRepository->findByUid((int)$row['uid']);
            if ($system !== null) {
                $systems[] = $system;
            }
        }
        return $systems;
    }
}
