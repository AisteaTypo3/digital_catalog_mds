<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The article numbers named in each product information / product overview PDF
 * (tx_digitalcatalog_document_articles), written by digitalcatalog:extract-specifications.
 *
 * Numbers are kept without the pieces per package and the sterile mark ("A-4650.91/1S" → "A-4650.91"): the same
 * implant, listed once in the ordering information.
 */
final class DocumentArticleRepository
{
    private const TABLE = 'tx_digitalcatalog_document_articles';
    private const PATTERN = '/(?<![A-Z0-9])[A-Z][A-Z0-9]{0,2}-\d{3,5}(?:\.\d{1,3})?(?:\/\d+)?S?(?![\d.]\d)/';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    public static function product(string $articleNumber): string
    {
        return (string)preg_replace('/(?:\/\d+)?S?$/', '', trim($articleNumber));
    }

    /**
     * @return list<string> the products named in a text
     */
    public static function productsIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);
        $products = array_values(array_unique(array_map([self::class, 'product'], $matches[0])));
        sort($products);

        return $products;
    }

    /**
     * Of the given files, those naming the article.
     *
     * @param list<int> $fileUids
     * @return list<int>
     */
    public function filesNaming(string $articleNumber, array $fileUids): array
    {
        $fileUids = array_values(array_filter($fileUids));
        if ($fileUids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return array_map('intval', $queryBuilder
            ->select('file_uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('file_uid', $queryBuilder->createNamedParameter($fileUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->like(
                    'articles',
                    $queryBuilder->createNamedParameter('% ' . $queryBuilder->escapeLikeWildcards(self::product($articleNumber)) . ' %')
                )
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @return array<int, string> file uid → hash of the file read
     */
    public function findHashes(): array
    {
        $hashes = [];
        foreach ($this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['file_uid', 'file_hash'], self::TABLE)->fetchAllAssociative() as $row) {
            $hashes[(int)$row['file_uid']] = (string)$row['file_hash'];
        }

        return $hashes;
    }

    /**
     * @param list<string> $products
     */
    public function save(int $fileUid, string $hash, array $products): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        // padded with spaces, so filesNaming() finds whole numbers only
        $data = ['file_hash' => $hash, 'articles' => ' ' . implode(' ', $products) . ' ', 'tstamp' => time()];
        if ($connection->update(self::TABLE, $data, ['file_uid' => $fileUid]) === 0) {
            $connection->insert(self::TABLE, $data + ['file_uid' => $fileUid]);
        }
    }

    /**
     * @param list<int> $keepFileUids
     */
    public function removeExcept(array $keepFileUids): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->delete(self::TABLE);
        if ($keepFileUids !== []) {
            $queryBuilder->where($queryBuilder->expr()->notIn(
                'file_uid',
                $queryBuilder->createNamedParameter($keepFileUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ));
        }

        return (int)$queryBuilder->executeStatement();
    }
}
