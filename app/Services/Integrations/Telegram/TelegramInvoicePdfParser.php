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
     *   customer_phone:?string,
     *   customer_address:?string,
     *   invoice_date:?string,
     *   items:array<int, string>,
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
        
        $customerDetails = $this->extractCustomerDetails($text);
        $customerName = $customerDetails['name'];
        $customerPhone = $customerDetails['phone'];
        $customerAddress = $customerDetails['address'];

        $items = $this->extractItems($text);

        $hasReadableText = mb_strlen($text) >= 20;
        $signals = array_filter([
            $invoiceNumber !== null ? 'invoice_number' : null,
            $invoiceDate !== null ? 'invoice_date' : null,
            $customerName !== null ? 'customer_name' : null,
            !empty($items) ? 'items' : null,
        ]);

        if (! $hasReadableText) {
            return [
                'readability_status' => 'image_pdf',
                'parse_status' => 'needs_review',
                'ocr_text' => null,
                'invoice_number' => $invoiceNumber,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_address' => $customerAddress,
                'invoice_date' => $invoiceDate,
                'items' => $items,
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
            'customer_phone' => $customerPhone,
            'customer_address' => $customerAddress,
            'invoice_date' => $invoiceDate,
            'items' => $items,
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
        
        $normalized = str_replace('MYSZ-INV-', '', $normalized);

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

    private function extractCustomerDetails(string $text): array
    {
        $details = ['name' => null, 'phone' => null, 'address' => null];

        $labelPatterns = [
            '/(?:bill|sold)\s*to\s*[:\-]?\s*\n(.*?)(?=\n\s*(?:invoice|date|due|items|total|notes?|dear|attention|\Z))/isu',
            '/customer\s*(?:name)?\s*[:\-]?\s*\n(.*?)(?=\n\s*(?:invoice|date|due|items|total|notes?|dear|attention|\Z))/isu',
        ];

        $block = '';
        foreach ($labelPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $block = trim($matches[1]);
                break;
            }
        }

        if ($block === '') {
            $details['name'] = $this->extractCustomerNameFallback($text);
            return $details;
        }

        $lines = array_filter(array_map('trim', explode("\n", $block)));
        $lines = array_values($lines);

        if (count($lines) > 0) {
            $details['name'] = $this->sanitizeCustomerNameCandidate(array_shift($lines));
            
            $addressLines = [];
            foreach ($lines as $line) {
                if (preg_match('/(?:tel|phone|h\/?p|mobile)\s*[:\-\.]?\s*([\+\d\-\s]{8,15})/iu', $line, $match)) {
                    $details['phone'] = trim($match[1]);
                } elseif (preg_match('/^\+?6?01\d{1}[\-\s]?\d{7,8}$/i', $line, $match) || preg_match('/^\+?6?0\d{1,2}[\-\s]?\d{6,8}$/i', $line, $match)) {
                    $details['phone'] = trim($match[0]);
                } else {
                    $addressLines[] = $line;
                }
            }
            $details['address'] = implode(', ', $addressLines) ?: null;
        }

        return $details;
    }

    private function extractCustomerNameFallback(string $text): ?string
    {
        $labelPatterns = [
            '/bill\s*to\s*[:\-]?\s*\n*([^\n]{3,120})/iu',
            '/sold\s*to\s*[:\-]?\s*\n*([^\n]{3,120})/iu',
            '/customer\s*(?:name)?\s*[:\-]?\s*([^\n]{3,120})/iu',
        ];

        foreach ($labelPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches) !== 1) {
                continue;
            }

            $value = $this->sanitizeCustomerNameCandidate((string) ($matches[1] ?? ''));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function extractItems(string $text): array
    {
        $items = [];
        
        if (preg_match_all('/^[ \t]*(?:\|\s*)?\d+\s*(?:\|\s*\*\*)?\s*([A-Z0-9]{3,20})\b/mu', $text, $matches)) {
            $items = $matches[1];
        }
        
        return array_values(array_unique($items));
    }

    private function sanitizeCustomerNameCandidate(string $value): ?string
    {
        $value = trim($value);
        $value = trim($value, " \t\n\r\0\x0B:;,.#");
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(invoice|date|due|sale agent|display\b)/iu', $value) === 1) {
            return null;
        }

        if (preg_match('/^[^A-Z0-9]*display\)?[^A-Z0-9]*$/iu', $value) === 1) {
            return null;
        }

        if (preg_match('/[A-Z]/iu', $value) !== 1) {
            return null;
        }

        return mb_substr($value, 0, 255);
    }
}
