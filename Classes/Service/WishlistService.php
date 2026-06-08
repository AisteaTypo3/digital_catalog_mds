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
            return array_map('intval', $decoded);
        }
        // Backward compat: old comma-separated format — migrate to qty=1 each
        $uids = array_values(array_filter(array_map('intval', explode(',', (string)$sessionData))));
        return array_fill_keys($uids, 1);
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

        $html = $this->buildEmailHtml($name, $email, $company, $message, $articleList, $inquiryRef, $t);
        $xlsx = $this->buildExcel($articleList, $t);

        /** @var MailMessage $mail */
        $mail = GeneralUtility::makeInstance(MailMessage::class);
        $mail
            ->from(new Address($siteEmail, $siteName))
            ->to($recipientEmail)
            ->replyTo(new Address($email, $name))
            ->subject($t('email.subject') . ' ' . $inquiryRef . ' – Digital Catalog')
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

    /**
     * @param array<int, array{qty: int, name: string, number: string, systems: string}> $articles
     */
    private function buildEmailHtml(
        string $name,
        string $email,
        string $company,
        string $message,
        array $articles,
        string $inquiryRef,
        callable $t
    ): string {
        $count = count($articles);

        $senderRows = $this->emailRow($t('email.sender.name'), htmlspecialchars($name));
        $senderRows .= $this->emailRow(
            $t('email.sender.email'),
            '<a href="mailto:' . htmlspecialchars($email) . '" style="color:#c8102e;text-decoration:none;">'
            . htmlspecialchars($email) . '</a>'
        );
        if ($company !== '') {
            $senderRows .= $this->emailRow($t('email.sender.company'), htmlspecialchars($company));
        }
        if ($message !== '') {
            $senderRows .= $this->emailRow($t('email.sender.message'), nl2br(htmlspecialchars($message)), true);
        }

        $productRows = '';
        foreach ($articles as $i => $article) {
            $bg = ($i % 2 === 0) ? '#f9f9f9' : '#ffffff';
            $productRows .= sprintf(
                '<tr style="background-color:%s;">'
                . '<td style="padding:10px 14px;border-bottom:1px solid #eeeeee;color:#999999;font-size:13px;text-align:center;width:36px;">%d</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #eeeeee;text-align:center;font-weight:600;font-size:13px;color:#0a0a0a;width:48px;">%d</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #eeeeee;font-weight:500;color:#1a1a1a;font-size:14px;">%s</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #eeeeee;font-family:\'Courier New\',monospace;font-size:12px;color:#888888;white-space:nowrap;">%s</td>'
                . '<td style="padding:10px 14px;border-bottom:1px solid #eeeeee;font-size:12px;color:#666666;">%s</td>'
                . '</tr>',
                $bg,
                $i + 1,
                $article['qty'],
                htmlspecialchars($article['name']),
                htmlspecialchars($article['number']),
                htmlspecialchars($article['systems'])
            );
        }

        $subject        = htmlspecialchars($t('email.subject') . ' ' . $inquiryRef . ' – Digital Catalog');
        $intro          = htmlspecialchars($t('email.intro'));
        $senderTitle    = htmlspecialchars($t('email.sender.title'));
        $productTitle   = htmlspecialchars($t('email.products.title'));
        $colQty         = htmlspecialchars($t('email.products.col.qty'));
        $colName        = htmlspecialchars($t('email.products.col.name'));
        $colNumber      = htmlspecialchars($t('email.products.col.number'));
        $colSystems     = htmlspecialchars($t('email.products.col.systems'));
        $attachmentHint = htmlspecialchars($t('email.attachment.hint'));
        $footer         = htmlspecialchars($t('email.footer'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f0f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f0;padding:32px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">

      <tr>
        <td style="background-color:#0a0a0a;padding:24px 36px;">
          <span style="color:#ffffff;font-size:18px;font-weight:300;letter-spacing:-0.5px;">Medartis</span>
          <span style="color:#c8102e;font-size:18px;font-weight:300;"> &middot; Digital Catalog</span>
        </td>
      </tr>

      <tr>
        <td style="padding:32px 36px 8px;">
          <h1 style="margin:0 0 8px 0;font-size:18px;font-weight:400;color:#0a0a0a;letter-spacing:-0.5px;">{$subject}</h1>
          <p style="margin:0;font-size:14px;color:#888888;line-height:1.5;">{$intro}</p>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 36px 8px;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;border-radius:6px;overflow:hidden;">
            <tr>
              <td colspan="2" style="padding:10px 14px;background-color:#f7f7f7;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#888888;">{$senderTitle}</td>
            </tr>
            {$senderRows}
          </table>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 36px 8px;">
          <p style="margin:0 0 10px 0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#888888;">{$productTitle} ({$count})</p>
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eeeeee;border-radius:6px;overflow:hidden;">
            <tr style="background-color:#0a0a0a;">
              <th style="padding:10px 14px;text-align:center;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#666666;width:36px;">#</th>
              <th style="padding:10px 14px;text-align:center;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#ffffff;width:48px;">{$colQty}</th>
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#ffffff;">{$colName}</th>
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#ffffff;">{$colNumber}</th>
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#ffffff;">{$colSystems}</th>
            </tr>
            {$productRows}
          </table>
          <p style="margin:8px 0 0;font-size:12px;color:#aaaaaa;">{$attachmentHint}</p>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 36px;border-top:1px solid #f0f0f0;background-color:#fafafa;">
          <p style="margin:0;font-size:11px;color:#cccccc;">{$footer}</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    private function emailRow(string $label, string $value, bool $valignTop = false): string
    {
        $valign = $valignTop ? 'vertical-align:top;' : '';
        return sprintf(
            '<tr>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f5f5f5;color:#888888;font-size:13px;width:140px;%s">%s</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #f5f5f5;font-size:14px;color:#1a1a1a;%s">%s</td>'
            . '</tr>',
            $valign,
            htmlspecialchars($label),
            $valign,
            $value
        );
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
