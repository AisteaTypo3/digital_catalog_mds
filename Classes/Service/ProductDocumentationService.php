<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

use Doctrine\DBAL\ParameterType;
use SimpleXMLElement;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileCollectionRepository;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ProductDocumentationService
{
    private const LANGUAGE_MAP = [
        'cs' => 'czech',
        'da' => 'danish',
        'nl' => 'dutch',
        'en' => 'english',
        'fr' => 'french',
        'de' => 'german',
        'el' => 'greek',
        'hu' => 'hungarian',
        'it' => 'italian',
        'no' => 'norwegian',
        'fi' => 'finnish',
        'pl' => 'polish',
        'pt' => 'portuguese (pt)',
        'pt-br' => 'portuguese (br)',
        'sl' => 'slovenian',
        'es' => 'spanish',
        'es-mx' => 'spanish (mx)',
        'sv' => 'swedish',
        'tr' => 'turkish',
    ];

    private const ALLOWED_FILE_TYPES = [
        'product information',
        'product overview',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FileCollectionRepository $fileCollectionRepository,
        private readonly Context $context,
    ) {}

    /**
     * @param array<int, string> $systemTitles
     * @return array<int, array<string, int|string>>
     */
    public function findBySystems(array $systemTitles, string $pageLocale): array
    {
        $normalizedSystems = $this->normalizeSystemValues($systemTitles);
        if ($normalizedSystems === []) {
            return [];
        }

        $collectionIds = $this->resolveCollectionIdsForSystems($normalizedSystems);
        if ($collectionIds === []) {
            return [];
        }

        $documents = [];
        foreach ($collectionIds as $collectionId) {
            $collection = $this->fileCollectionRepository->findByUid($collectionId);
            if ($collection === null) {
                continue;
            }

            try {
                $collection->loadContents();
            } catch (FolderDoesNotExistException) {
                continue;
            }

            foreach ($collection->getItems() as $file) {
                if (!$file instanceof FileInterface) {
                    continue;
                }

                $document = $this->buildDocumentData($file, $normalizedSystems, $pageLocale);
                if ($document === null) {
                    continue;
                }

                $documents[$document['identifier']] = $document;
            }
        }

        $documents = array_values($documents);
        usort(
            $documents,
            static fn(array $left, array $right): int => [$left['fileType'], $left['title']] <=> [$right['fileType'], $right['title']]
        );

        return $documents;
    }

    /**
     * @param array<int, string> $normalizedSystems
     * @return array<int, int>
     */
    private function resolveCollectionIdsForSystems(array $normalizedSystems): array
    {
        $collectionIds = [];

        foreach ($this->findMatchingDownloadManagerSettings($normalizedSystems) as $settings) {
            foreach ($this->parseIntegerList($settings['lbpid'] ?? '') as $collectionId) {
                $collectionIds[$collectionId] = $collectionId;
            }

            foreach ($this->getCollectionIdsFromPages($this->parseIntegerList($settings['dfolder'] ?? '')) as $collectionId) {
                $collectionIds[$collectionId] = $collectionId;
            }
        }

        return array_values($collectionIds);
    }

    /**
     * @param array<int, string> $normalizedSystems
     * @return array<int, array{lbpid?: string, dfolder?: string, systems?: array<int, string>, englishType?: string, usPageTree?: string}>
     */
    private function findMatchingDownloadManagerSettings(array $normalizedSystems): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $rows = $queryBuilder
            ->select('pi_flexform')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('reintdownloadmanager_dmlist')),
                $queryBuilder->expr()->eq('hidden', 0),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $matches = [];
        foreach ($rows as $row) {
            $settings = $this->parseDownloadManagerSettings((string)($row['pi_flexform'] ?? ''));
            if (($settings['systems'] ?? []) === []) {
                continue;
            }

            $englishType = $this->normalizeValue($settings['englishType'] ?? '');
            $usPageTree = trim((string)($settings['usPageTree'] ?? ''));
            if ($englishType === 'usa' || $usPageTree === '1') {
                continue;
            }

            if (array_intersect($normalizedSystems, $settings['systems']) === []) {
                continue;
            }

            $matches[] = $settings;
        }

        return $matches;
    }

    /**
     * @return array{lbpid?: string, dfolder?: string, systems?: array<int, string>, englishType?: string, usPageTree?: string}
     */
    private function parseDownloadManagerSettings(string $flexFormXml): array
    {
        if ($flexFormXml === '') {
            return [];
        }

        $xml = @simplexml_load_string($flexFormXml);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->data)) {
            return [];
        }

        $settings = [];
        foreach ($xml->data->sheet as $sheet) {
            foreach ($sheet->language as $language) {
                foreach ($language->field as $field) {
                    $fieldName = (string)($field['index'] ?? '');
                    $value = trim((string)($field->value ?? ''));
                    if ($fieldName === '') {
                        continue;
                    }

                    switch ($fieldName) {
                        case 'settings.lbpid':
                            $settings['lbpid'] = $value;
                            break;
                        case 'settings.dfolder':
                            $settings['dfolder'] = $value;
                            break;
                        case 'settings.systems':
                            $settings['systems'] = $this->normalizeSystemValues(GeneralUtility::trimExplode(',', $value, true));
                            break;
                        case 'settings.englishType':
                            $settings['englishType'] = $value;
                            break;
                        case 'settings.usPageTree':
                            $settings['usPageTree'] = $value;
                            break;
                    }
                }
            }
        }

        return $settings;
    }

    /**
     * @param array<int, int> $pageIds
     * @return array<int, int>
     */
    private function getCollectionIdsFromPages(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $languageId = $this->context->getAspect('language')->getId();
        $collectionIds = [];

        foreach ($pageIds as $pageId) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_collection');
            $rows = $queryBuilder
                ->select('uid', 'l10n_parent')
                ->from('sys_file_collection')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('hidden', 0),
                    $queryBuilder->expr()->eq('deleted', 0),
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', 0)
                    )
                )
                ->orderBy('sorting')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $uid = (int)($row['uid'] ?? 0);
                $parentUid = (int)($row['l10n_parent'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }

                if ($parentUid > 0 && isset($collectionIds[$parentUid])) {
                    unset($collectionIds[$parentUid]);
                }

                $collectionIds[$uid] = $uid;
            }
        }

        return array_values($collectionIds);
    }

    /**
     * @param array<int, string> $normalizedSystems
     * @return array<string, int|string>|null
     */
    private function buildDocumentData(FileInterface $file, array $normalizedSystems, string $pageLocale): ?array
    {
        $properties = $file->getProperties();
        if ((int)($properties['archive'] ?? 0) !== 0) {
            return null;
        }

        $fileTypeRaw = trim((string)($properties['filetype'] ?? ''));
        $fileType = $this->normalizeValue($fileTypeRaw);
        if (!in_array($fileType, self::ALLOWED_FILE_TYPES, true)) {
            return null;
        }

        $language = trim((string)($properties['sprache'] ?? ''));
        if (!$this->languageMatches($language, $pageLocale)) {
            return null;
        }

        $publicUrl = $file->getPublicUrl();
        if ($publicUrl === null || $publicUrl === '') {
            return null;
        }

        $title = trim((string)($properties['title'] ?? ''));
        if ($title === '') {
            $title = $file->getName();
        }

        return [
            'identifier' => $file->getStorage()->getUid() . ':' . $file->getIdentifier(),
            'title' => $title,
            'url' => $publicUrl,
            'fileType' => $fileTypeRaw,
            'language' => $language,
            'size' => (int)$file->getSize(),
        ];
    }

    private function languageMatches(string $fileLanguage, string $pageLocale): bool
    {
        $fileLanguage = $this->normalizeValue($fileLanguage);
        $pageLocale = $this->normalizeValue($pageLocale);
        $languageName = self::LANGUAGE_MAP[$pageLocale] ?? $pageLocale;

        if ($fileLanguage === '') {
            return true;
        }

        return $fileLanguage === $pageLocale
            || $fileLanguage === $languageName
            || str_starts_with($fileLanguage, $pageLocale . '-')
            || str_starts_with($fileLanguage, $pageLocale . '_');
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function normalizeValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = $this->normalizeValue($value);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeCsvValues(string $value): array
    {
        return $this->normalizeValues(GeneralUtility::trimExplode(',', $value, true));
    }

    private function normalizeValue(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function normalizeSystemValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = $this->normalizeSystemValue($value);
            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function normalizeSystemValue(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['°', ',', '-', '–', '—'], ' ', $value);
        $value = (string)preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * @return array<int, int>
     */
    private function parseIntegerList(string $value): array
    {
        $items = [];
        foreach (GeneralUtility::intExplode(',', $value, true) as $item) {
            if ($item <= 0) {
                continue;
            }

            $items[$item] = $item;
        }

        return array_values($items);
    }
}
