<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Command;

use Medartis\DigitalCatalog\Service\CaseReportMatcher;
use Medartis\DigitalCatalog\Service\CaseReportRepository;
use Medartis\DigitalCatalog\Service\DemandRepository;
use Medartis\DigitalCatalog\Service\DocumentArticleRepository;
use Medartis\DigitalCatalog\Service\SpecificationParser;
use Medartis\DigitalCatalog\Service\SpecificationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\CommandUtility;

/**
 * Reads the technical data (length, diameter, holes, material …) of every article from the ordering tables of the
 * ordering catalogs and surgical techniques in the file list and stores it per article number
 * (tx_digitalcatalog_specification), for the article detail page.
 *
 * Needs `pdftotext` (poppler-utils). Only English documents are read by default (--language, multilingual ones
 * included), ordering catalogs first, then surgical techniques; when an article is in several PDFs, the first one
 * wins. Only article numbers that exist in the doc_manager are stored. PDFs in archive folders are skipped unless --include-archive is set.
 *
 * Incremental, so it can run daily: what was read from each PDF is kept with the file's hash (sys_file.sha1) and the
 * parser version; unchanged PDFs are not read again. Only differences are written to the technical data (new
 * articles inserted, changed ones updated, articles no longer in any PDF removed). --full reads every PDF again.
 */
#[AsCommand(
    name: 'digitalcatalog:extract-specifications',
    description: 'Read the technical data of the articles from the ordering tables of the catalog PDFs',
)]
final class ExtractSpecificationsCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly SpecificationParser $parser,
        private readonly SpecificationRepository $repository,
        private readonly CaseReportRepository $caseReportRepository,
        private readonly DocumentArticleRepository $documentArticleRepository,
        private readonly DemandRepository $demandRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('types', null, InputOption::VALUE_REQUIRED, 'Document types (file metadata "filetype") in order of priority, comma separated', 'Ordering Catalog,Surgical Technique')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Only documents in this language (file metadata "sprache"; multilingual ones included), empty for all', 'English')
            ->addOption('pdftotext', null, InputOption::VALUE_REQUIRED, 'Path to pdftotext', 'pdftotext')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Read every PDF again, also unchanged ones')
            ->addOption('include-archive', null, InputOption::VALUE_NONE, 'Also read PDFs in archive folders (e.g. /MDD_Archiv/), which may be outdated')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and report, do not store')
            ->addOption('article', null, InputOption::VALUE_REQUIRED, 'Print what was found for this article number')
            ->addOption('skip-case-reports', null, InputOption::VALUE_NONE, 'Do not match the case reports to the implant families')
            ->addOption('keep-demand-months', null, InputOption::VALUE_REQUIRED, 'Delete the demand counters (inquiries, wishlist, searches) older than this many months', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdftotext = (string)$input->getOption('pdftotext');
        if (!$this->toolAvailable($pdftotext)) {
            $output->writeln(sprintf('<error>"%s" was not found. Install poppler-utils or pass --pdftotext=/path/to/pdftotext.</error>', $pdftotext));
            return Command::FAILURE;
        }

        $full = (bool)$input->getOption('full');
        $dryRun = (bool)$input->getOption('dry-run');
        $knownArticles = $this->knownArticleNumbers();
        $types = array_values(array_filter(array_map('trim', explode(',', (string)$input->getOption('types')))));
        $language = trim((string)$input->getOption('language'));
        $files = $this->findFiles($types, (bool)$input->getOption('include-archive'), $language);
        $output->writeln(sprintf('%d PDF(s) of type %s%s, %d known article numbers', count($files), implode(', ', $types), $language !== '' ? ' in ' . $language : '', count($knownArticles)));

        // 1. records per PDF: from the cache when the PDF and the parser are unchanged, otherwise read the PDF
        $sources = $this->repository->findSources();
        $recordsByFile = [];
        $read = $skipped = $failed = 0;
        foreach ($files as $index => $file) {
            $uid = $file['uid'];
            $cached = $sources[$uid] ?? null;
            $hash = $file['sha1'];
            if ($hash === '') {
                $path = $this->localPath($uid);
                $hash = $path !== null ? (string)sha1_file($path) : '';
            }
            if (!$full && $cached !== null && $hash !== '' && $cached['hash'] === $hash
                && $cached['version'] === SpecificationParser::VERSION) {
                $recordsByFile[$uid] = $cached['records'];
                $skipped++;
                continue;
            }

            $path = $this->localPath($uid);
            $text = $path !== null ? $this->pdfText($pdftotext, $path) : null;
            if ($text === null) {
                // keep what was read before rather than losing the data of a PDF that cannot be read right now
                $output->writeln(sprintf('<comment>Could not read %s</comment>', $file['identifier']));
                if ($cached !== null) {
                    $recordsByFile[$uid] = $cached['records'];
                }
                $failed++;
                continue;
            }
            $records = $this->parser->parse($text);
            $recordsByFile[$uid] = $records;
            $read++;
            if (!$dryRun) {
                $this->repository->saveSource($uid, $hash, SpecificationParser::VERSION, $records);
            }
            $output->writeln(sprintf('[%d/%d] read %s: %d row(s)', $index + 1, count($files), $file['identifier'], count($records)), OutputInterface::VERBOSITY_VERBOSE);
        }
        $forgotten = $dryRun ? 0 : $this->repository->removeSourcesExcept(array_column($files, 'uid'));

        // 2. merge in priority order: the first PDF that lists an article wins
        $wanted = [];
        foreach ($files as $file) {
            foreach ($recordsByFile[$file['uid']] ?? [] as $record) {
                $article = (string)($record['article'] ?? '');
                if ($article === '' || isset($wanted[$article]) || !isset($knownArticles[$article])) {
                    continue;
                }
                $wanted[$article] = [
                    'section' => mb_substr((string)$record['section'], 0, 255),
                    'attributes' => SpecificationRepository::encodeAttributes((array)$record['attributes']),
                    'file' => $file['uid'],
                    'page' => (int)$record['page'],
                ];
            }
        }

        // 3. only the differences
        $stored = $this->repository->findAllSignatures();
        $inserts = $updates = [];
        foreach ($wanted as $article => $data) {
            if (!isset($stored[$article])) {
                $inserts[$article] = $data;
                continue;
            }
            $signature = SpecificationRepository::signature($data['section'], $data['attributes'], $data['file'], $data['page']);
            if ($signature !== $stored[$article]['signature']) {
                $updates[$stored[$article]['uid']] = $data;
            }
        }
        $deletes = [];
        foreach ($stored as $article => $row) {
            if (!isset($wanted[$article])) {
                $deletes[] = $row['uid'];
            }
        }

        $output->writeln(sprintf(
            'PDFs: %d read, %d unchanged (skipped), %d not readable, %d forgotten',
            $read,
            $skipped,
            $failed,
            $forgotten
        ));
        $output->writeln(sprintf(
            '<info>Articles with technical data: %d of %d — %d new, %d changed, %d removed, %d unchanged</info>',
            count($wanted),
            count($knownArticles),
            count($inserts),
            count($updates),
            count($deletes),
            count($wanted) - count($inserts) - count($updates)
        ));

        $article = (string)$input->getOption('article');
        if ($article !== '') {
            $output->writeln($article . ': ' . (isset($wanted[$article]) ? json_encode($wanted[$article], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : 'nothing found'));
        }

        if ($dryRun) {
            $output->writeln('Dry run: nothing stored.');
            return Command::SUCCESS;
        }
        if ($inserts !== [] || $updates !== [] || $deletes !== []) {
            $this->repository->applyChanges($inserts, $updates, $deletes);
        }

        if (!$input->getOption('skip-case-reports')) {
            $this->matchCaseReports($pdftotext, $language, $full, $output);
        }
        $this->indexProductDocuments($pdftotext, $full, $output);

        $purged = $this->demandRepository->purge((int)$input->getOption('keep-demand-months'));
        if ($purged > 0) {
            $output->writeln(sprintf('Demand: %d counter(s) older than %d months deleted', $purged, (int)$input->getOption('keep-demand-months')));
        }

        return Command::SUCCESS;
    }

    /**
     * Case reports (file type "Case Report"): their text is kept and only read again when the file changed; the
     * matching to the implant families (CaseReportMatcher) runs every time, as the families change with the
     * technical data.
     */
    private function matchCaseReports(string $pdftotext, string $language, bool $full, OutputInterface $output): void
    {
        $files = $this->findFiles(['Case Report'], false, $language);
        $texts = $this->caseReportRepository->findTexts();
        $matcher = new CaseReportMatcher($this->caseReportRepository->sectionAreas());
        $read = $matched = 0;
        foreach ($files as $file) {
            $uid = $file['uid'];
            $hash = $file['sha1'];
            $text = $texts[$uid]['content'] ?? null;
            if ($full || $text === null || $hash === '' || $texts[$uid]['hash'] !== $hash) {
                $path = $this->localPath($uid);
                $text = $path !== null ? $this->pdfText($pdftotext, $path, false) : null;
                if ($text === null) {
                    $output->writeln(sprintf('<comment>Could not read %s</comment>', $file['identifier']));
                    continue;
                }
                $read++;
            }
            $sections = $matcher->match($text, array_values(array_filter(array_map('trim', explode(',', $file['system'])))));
            $this->caseReportRepository->save($uid, $hash, $text, $sections);
            $matched += $sections !== [] ? 1 : 0;
            $output->writeln(sprintf('%s: %s', $file['identifier'], $sections === [] ? '-' : implode(' | ', $sections)), OutputInterface::VERBOSITY_VERBOSE);
        }
        $this->caseReportRepository->removeExcept(array_column($files, 'uid'));
        $output->writeln(sprintf('<info>Case reports: %d, %d read, %d matched automatically (families set by hand in the backend module are kept)</info>', count($files), $read, $matched));
    }

    /**
     * The article numbers named in the product information / product overview PDFs (all languages: the article page
     * lists them in the language of the page), for the article page to list the documents an article is really in.
     */
    private function indexProductDocuments(string $pdftotext, bool $full, OutputInterface $output): void
    {
        $files = $this->findFiles(['Product Information', 'Product Overview'], false, '');
        $hashes = $this->documentArticleRepository->findHashes();
        $read = $withArticles = 0;
        foreach ($files as $file) {
            $uid = $file['uid'];
            if (!$full && $file['sha1'] !== '' && ($hashes[$uid] ?? null) === $file['sha1']) {
                continue;
            }
            $path = $this->localPath($uid);
            $text = $path !== null ? $this->pdfText($pdftotext, $path, false) : null;
            if ($text === null) {
                $output->writeln(sprintf('<comment>Could not read %s</comment>', $file['identifier']));
                continue;
            }
            $products = DocumentArticleRepository::productsIn($text);
            $this->documentArticleRepository->save($uid, $file['sha1'], $products);
            $read++;
            $withArticles += $products !== [] ? 1 : 0;
        }
        $this->documentArticleRepository->removeExcept(array_column($files, 'uid'));
        $output->writeln(sprintf('<info>Product information: %d PDF(s), %d read%s</info>', count($files), $read, $read > 0 ? sprintf(', %d naming articles', $withArticles) : ''));
    }

    /**
     * @return array<string, true>
     */
    private function knownArticleNumbers(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_docmanager_domain_model_article');
        $queryBuilder->getRestrictions()->removeAll();
        $numbers = $queryBuilder
            ->select('article_number')
            ->from('tx_docmanager_domain_model_article')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->neq('article_number', $queryBuilder->createNamedParameter(''))
            )
            ->groupBy('article_number')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_fill_keys(array_map('trim', $numbers), true);
    }

    /**
     * PDF files of the given types, not archived, by type priority, English first. With a language, only the
     * documents in that language (also multilingual ones like "English,French,German").
     *
     * @param array<int, string> $types
     * @return array<int, array{uid: int, identifier: string, sha1: string, system: string}>
     */
    private function findFiles(array $types, bool $includeArchive = false, string $language = ''): array
    {
        if ($types === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('f.uid', 'f.identifier', 'f.sha1', 'm.filetype', 'm.sprache', 'm.system')
            ->from('sys_file', 'f')
            ->join('f', 'sys_file_metadata', 'm', 'm.file = f.uid AND m.sys_language_uid = 0')
            ->where(
                $queryBuilder->expr()->eq('f.extension', $queryBuilder->createNamedParameter('pdf')),
                $queryBuilder->expr()->eq('f.missing', 0),
                $queryBuilder->expr()->eq('m.archive', 0),
                $queryBuilder->expr()->in('m.filetype', $queryBuilder->createNamedParameter($types, \Doctrine\DBAL\ArrayParameterType::STRING))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($language !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => in_array(
                mb_strtolower($language),
                array_map(static fn (string $part): string => mb_strtolower(trim($part)), explode(',', (string)$row['sprache'])),
                true
            )));
        }
        if (!$includeArchive) {
            // old versions kept in archive folders (e.g. /MDD_Archiv/) may have outdated data
            $rows = array_values(array_filter($rows, static fn (array $row): bool => stripos((string)$row['identifier'], 'archiv') === false));
        }

        usort($rows, static function (array $a, array $b) use ($types): int {
            $english = static fn (array $row): int => stripos((string)$row['sprache'], 'english') !== false ? 0 : 1;
            return [array_search($a['filetype'], $types, true), $english($a), $a['identifier']]
                <=> [array_search($b['filetype'], $types, true), $english($b), $b['identifier']];
        });

        return array_map(static fn (array $row): array => [
            'uid' => (int)$row['uid'],
            'identifier' => (string)$row['identifier'],
            'sha1' => (string)$row['sha1'],
            'system' => (string)$row['system'],
        ], $rows);
    }

    private function localPath(int $fileUid): ?string
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            $path = $file->getForLocalProcessing(false);
            return is_file($path) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pdfText(string $pdftotext, string $path, bool $layout = true): ?string
    {
        $command = escapeshellcmd($pdftotext) . ($layout ? ' -layout' : '') . ' -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null';
        $lines = [];
        $status = 0;
        CommandUtility::exec($command, $lines, $status);
        if ($status !== 0) {
            return null;
        }

        return implode("\n", (array)$lines);
    }

    private function toolAvailable(string $pdftotext): bool
    {
        $lines = [];
        $status = 0;
        CommandUtility::exec(escapeshellcmd($pdftotext) . ' -v 2>&1', $lines, $status);
        return $status === 0;
    }
}
