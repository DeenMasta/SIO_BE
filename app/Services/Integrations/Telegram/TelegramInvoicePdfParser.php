<?php

namespace App\Services\Integrations\Telegram;

use Smalot\PdfParser\Parser;

class TelegramInvoicePdfParser
{
    public function __construct(
        private readonly Parser $parser,
    ) {
    }

    /**
     * @return array{
     *   readability_status:string,
     *   parse_status:string,
     *   ocr_text:?string,
     *   invoice_number:?string,
     *   customer_name:?string,
     *   invoice_date:?string,
     *   confidence_score:float,
     *   review_notes:?string,
     *   extracted_json:array<string, mixed>
     * }
     */
    public function parse(string $absolutePdfPath, ?string $caption = null, ?string $fileName = null): array
    {
        try {
            $document = $this->parser->parseFile($absolutePdfPath);
            $text = $this->normalizeText($document->getText());
        } catch (\Throwable) {
            $text = '';
        }

        $invoiceNumber = $this->extractInvoiceNumber($text, $caption, $fileName);
        $invoiceDate = $this->extractInvoiceDate($text);
        $customerName = $this->extractCustomerName($text);

        $hasReadableText = mb_strlen($text) >= 20;
        $signals = array_filter([
            $invoiceNumber !== null ? 'invoice_number' : null,
            $invoiceDate !== null ? 'invoice_date' : null,
            $customerName !== null ? 'customer_name' : null,
        ]);

        if (! $hasReadableText) {
            return [
                'readability_status' => 'image_pdf',
                'parse_status' => 'needs_review',
                'ocr_text' => null,
                'invoice_number' => $invoiceNumber,
                'customer_name' => $customerName,
                'invoice_date' => $invoiceDate,
                'confidence_score' => $invoiceNumber !== null ? 0.35 : 0.00,
                'review_notes' => 'PDF text extraction returned little or no text. Likely scanned or image-only PDF.',
                'extracted_json' => [
                    'text_length' => mb_strlen($text),
                    'signals_found' => array_values($signals),
                    'source' => [
                        'caption' => $caption,
                        'file_name' => $fileName,
                    ],
                ],
            ];
        }

        $confidence = 0.25;
        if ($invoiceNumber !== null) {
            $confidence += 0.45;
        }
        if ($invoiceDate !== null) {
            $confidence += 0.20;
        }
        if ($customerName !== null) {
            $confidence += 0.10;
        }

        $parseStatus = $invoiceNumber !== null ? 'parsed' : 'needs_review';

        return [
            'readability_status' => 'text_pdf',
            'parse_status' => $parseStatus,
            'ocr_text' => $text,
            'invoice_number' => $invoiceNumber,
            'customer_name' => $customerName,
            'invoice_date' => $invoiceDate,
            'confidence_score' => min($confidence, 0.99),
            'review_notes' => $parseStatus === 'parsed'
                ? null
                : 'Readable PDF, but invoice number could not be extracted confidently.',
            'extracted_json' => [
                'text_length' => mb_strlen($text),
                'signals_found' => array_values($signals),
                'source' => [
                    'caption' => $caption,
                    'file_name' => $fileName,
                ],
            ],
        ];
    }

    private function normalizeText(string $text): string
    {
        $normalized = preg_replace('/[^\S\r\n]+/u', ' ', $text) ?? $text;
        $normalized = preg_replace("/\r\n|\r/u", "\n", $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/u", "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function extractInvoiceNumber(string $text, ?string $caption, ?string $fileName): ?string
    {
        $candidates = array_filter([
            $this->extractLabeledInvoiceNumber($text),
            $this->extractGenericInvoiceToken($text),
            $this->extractGenericInvoiceToken((string) $caption),
            $this->extractGenericInvoiceToken((string) $fileName),
        ]);

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeInvoiceToken((string) $candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function extractLabeledInvoiceNumber(string $text): ?string
    {
        $patterns = [
            '/invoice\s*(?:no|number|#)\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-_\.]{2,})/iu',
            '/inv\s*(?:no|number|#)\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-_\.]{2,})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return (string) ($matches[1] ?? '');
            }
        }

        return null;
    }

    private function extractGenericInvoiceToken(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        if (preg_match('/\bINV[-\/]?[A-Z0-9][A-Z0-9\/\-_\.]{2,}\b/iu', $text, $matches) === 1) {
            return (string) ($matches[0] ?? '');
        }

        return null;
    }

    private function normalizeInvoiceToken(string $token): ?string
    {
        $normalized = strtoupper(trim($token));
        $normalized = trim($normalized, " \t\n\r\0\x0B:;,.#");

        if ($normalized === '' || mb_strlen($normalized) < 4) {
            return null;
        }

        return $normalized;
    }

    private function extractInvoiceDate(string $text): ?string
    {
        $patterns = [
            '/invoice\s*date\s*[:\-]?\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/iu',
            '/invoice\s*date\s*[:\-]?\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/iu',
            '/date\s*[:\-]?\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/iu',
            '/date\s*[:\-]?\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return $this->normalizeDate((string) ($matches[1] ?? ''));
            }
        }

        return null;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim(str_replace('/', '-', $value));

        foreach (['Y-m-d', 'd-m-Y', 'm-d-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function extractCustomerName(string $text): ?string
    {
        $patterns = [
            '/customer\s*(?:name)?\s*[:\-]?\s*([^\n]{3,120})/iu',
            '/bill\s*to\s*[:\-]?\s*([^\n]{3,120})/iu',
            '/sold\s*to\s*[:\-]?\s*([^\n]{3,120})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $value = trim((string) ($matches[1] ?? ''));
                $value = trim($value, " \t\n\r\0\x0B:;,.#");

                if ($value !== '') {
                    return mb_substr($value, 0, 255);
                }
            }
        }

        return null;
    }
}
