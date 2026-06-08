<?php

declare(strict_types=1);

namespace Medartis\DigitalCatalog\Service;

use Medartis\DocManager\Domain\Model\Article;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

class WishlistService implements SingletonInterface
{
    private const SESSION_KEY = 'tx_digitalcatalog_wishlist';

    private function getFrontendUser(): ?FrontendUserAuthentication
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return null;
        }
        return $request->getAttribute('frontend.user');
    }

    /** @return array<int, int> */
    public function getWishlistWithQuantities(): array
    {
        $feUser = $this->getFrontendUser();
        if ($feUser === null) {
            return [];
        }
        $sessionData = $feUser->getSessionData(self::SESSION_KEY);
        if (empty($sessionData)) {
            return [];
        }
        $decoded = json_decode((string)$sessionData, true);
        if (is_array($decoded)) {
            // JSON object keys are always strings — cast both key and value to int,
            // and drop any entries where uid or qty would be zero/negative.
            $result = [];
            foreach ($decoded as $uid => $qty) {
                $uid = (int)$uid;
                $qty = (int)$qty;
                if ($uid > 0 && $qty > 0) {
                    $result[$uid] = $qty;
                }
            }
            return $result;
        }
        // Backward compat: old comma-separated format — migrate to qty=1 each
        $uids = array_filter(array_map('intval', explode(',', (string)$sessionData)));
        return array_fill_keys(array_values($uids), 1);
    }

    /** @return array<int, int> */
    public function getWishlist(): array
    {
        return array_keys($this->getWishlistWithQuantities());
    }

    public function addToWishlist(int $articleUid): void
    {
        $current = $this->getWishlistWithQuantities();
        if (!isset($current[$articleUid])) {
            $current[$articleUid] = 1;
            $this->saveWishlist($current);
        }
    }

    public function removeFromWishlist(int $articleUid): void
    {
        $current = $this->getWishlistWithQuantities();
        unset($current[$articleUid]);
        $this->saveWishlist($current);
    }

    public function setQuantity(int $articleUid, int $qty): void
    {
        $current = $this->getWishlistWithQuantities();
        if (!isset($current[$articleUid])) {
            return;
        }
        if ($qty <= 0) {
            unset($current[$articleUid]);
        } else {
            $current[$articleUid] = $qty;
        }
        $this->saveWishlist($current);
    }

    public function clearWishlist(): void
    {
        $this->saveWishlist([]);
    }

    /** @param array<int, int> $quantities */
    private function saveWishlist(array $quantities): void
    {
        $feUser = $this->getFrontendUser();
        if ($feUser === null) {
            return;
        }
        $feUser->setAndSaveSessionData(self::SESSION_KEY, json_encode($quantities));
    }

    /** @param iterable<Article> $articles */
    public function sendInquiryMail(
        string $name,
        string $email,
        string $company,
        string $message,
        iterable $articles
    ): void {
        $recipientEmail = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? 'info@medartis.com';
        $siteEmail      = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? 'noreply@medartis.com';
        $siteName       = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] ?? 'Medartis';

        $t = fn (string $key): string =>
            LocalizationUtility::translate($key, 'DigitalCatalog', null, 'en') ?? $key;

        $inquiryRef  = 'DC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 4));
        $subject     = $t('email.subject') . ' ' . $inquiryRef . ' – Digital Catalog';
        $quantities  = $this->getWishlistWithQuantities();

        $articleList = [];
        foreach ($articles as $article) {
            $systemNames = [];
            foreach ($article->getSystems() as $system) {
                $systemNames[] = $system->getTitle();
            }
            $articleList[] = [
                'name'    => $article->getProductName(),
                'number'  => $article->getArticleNumber(),
                'systems' => implode(', ', $systemNames),
                'qty'     => $quantities[$article->getUid()] ?? 1,
            ];
        }

        $html = $this->renderEmailTemplate([
            'name'     => $name,
            'email'    => $email,
            'company'  => $company,
            'message'  => $message,
            'articles' => $articleList,
            'subject'  => $subject,
            'translations' => [
                'intro'          => $t('email.intro'),
                'senderTitle'    => $t('email.sender.title'),
                'senderName'     => $t('email.sender.name'),
                'senderEmail'    => $t('email.sender.email'),
                'senderCompany'  => $t('email.sender.company'),
                'senderMessage'  => $t('email.sender.message'),
                'productsTitle'  => $t('email.products.title'),
                'colQty'         => $t('email.products.col.qty'),
                'colName'        => $t('email.products.col.name'),
                'colNumber'      => $t('email.products.col.number'),
                'colSystems'     => $t('email.products.col.systems'),
                'attachmentHint' => $t('email.attachment.hint'),
                'footer'         => $t('email.footer'),
            ],
        ]);
        $xlsx = $this->buildExcel($articleList, $t);

        /** @var MailMessage $mail */
        $mail = GeneralUtility::makeInstance(MailMessage::class);
        $mail
            ->from(new Address($siteEmail, $siteName))
            ->to($recipientEmail)
            ->replyTo(new Address($email, $name))
            ->subject($subject)
            ->html($html)
            ->text(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));

        if ($xlsx !== null) {
            $mail->attach(
                $xlsx,
                'Produktanfrage_' . date('Y-m-d') . '.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
        }

        $mail->send();
    }

    /** @param array<string, mixed> $variables */
    private function renderEmailTemplate(array $variables): string
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName('EXT:digital_catalog/Resources/Private/Templates/Email/Inquiry.html')
        );
        $view->assignMultiple($variables);
        return $view->render();
    }

    /** @param array<int, array{qty: int, name: string, number: string, systems: string}> $articles */
    private function buildExcel(array $articles, callable $t): ?string
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();
            $sheet->setTitle(mb_substr($t('email.excel.sheetname'), 0, 31));

            $sheet->setCellValue('A1', '#');
            $sheet->setCellValue('B1', $t('email.products.col.qty'));
            $sheet->setCellValue('C1', $t('email.products.col.name'));
            $sheet->setCellValue('D1', $t('email.products.col.number'));
            $sheet->setCellValue('E1', $t('email.products.col.systems'));

            $sheet->getStyle('A1:E1')->applyFromArray([
                'font' => [
                    'bold'  => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size'  => 11,
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '0A0A0A'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getRowDimension(1)->setRowHeight(28);

            foreach ($articles as $i => $article) {
                $row = $i + 2;
                $sheet->setCellValue('A' . $row, $i + 1);
                $sheet->setCellValue('B' . $row, $article['qty']);
                $sheet->setCellValue('C' . $row, $article['name']);
                $sheet->setCellValue('D' . $row, $article['number']);
                $sheet->setCellValue('E' . $row, $article['systems']);

                if ($i % 2 === 1) {
                    $sheet->getStyle('A' . $row . ':E' . $row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F7F7F7');
                }
            }

            foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'dc_xlsx_');
            (new Xlsx($spreadsheet))->save($tmpFile);
            $content = file_get_contents($tmpFile);
            unlink($tmpFile);

            return $content !== false ? $content : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
