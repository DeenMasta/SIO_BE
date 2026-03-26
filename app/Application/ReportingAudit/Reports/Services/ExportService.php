<?php

namespace App\Application\ReportingAudit\Reports\Services;

use DateTimeInterface;
use Dompdf\Dompdf;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    private const SUPPORTED_FORMATS = ['csv', 'xlsx', 'pdf'];

    public function isFormatSupported(string $format): bool
    {
        return in_array(strtolower($format), self::SUPPORTED_FORMATS, true);
    }

    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    /**
     * Export rows to CSV, Excel, or PDF format
     *
     * @param  Collection<mixed>  $rows
     * @param  string[]  $headers
     * @param  string  $filename  Filename without extension
     * @param  string  $format  csv, xlsx, or pdf
     */
    public function export(Collection $rows, array $headers, string $filename, string $format = 'csv'): StreamedResponse
    {
        $format = strtolower($format);

        return match ($format) {
            'csv' => $this->exportToCsv($rows, $headers, $filename),
            'xlsx' => $this->exportToExcel($rows, $headers, $filename),
            'pdf' => $this->exportToPdf($rows, $headers, $filename),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    private function exportToCsv(Collection $rows, array $headers, string $filename): StreamedResponse
    {
        $attachmentFilename = $filename.'.csv';

        return response()->streamDownload(function () use ($rows, $headers): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                $values = [];
                foreach ($headers as $header) {
                    $values[] = $this->formatValue($this->extractValue($row, $header));
                }
                fputcsv($handle, $values);
            }

            fclose($handle);
        }, $attachmentFilename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exportToExcel(Collection $rows, array $headers, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Add headers
        foreach ($headers as $col => $header) {
            $sheet->setCellValue($this->getExcelColumn($col + 1).'1', (string) $header);
        }

        // Add data
        $rowNum = 2;
        foreach ($rows as $row) {
            foreach ($headers as $col => $header) {
                $value = $this->formatValue($this->extractValue($row, $header));
                $sheet->setCellValue($this->getExcelColumn($col + 1).$rowNum, $value);
            }
            $rowNum++;
        }

        // Auto-size columns
        foreach ($headers as $col => $header) {
            $sheet->getColumnDimension($this->getExcelColumn($col + 1))->setAutoSize(true);
        }

        $attachmentFilename = $filename.'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $attachmentFilename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Convert column number to Excel column letter (1 = A, 2 = B, 27 = AA, etc.)
     */
    private function getExcelColumn(int $col): string
    {
        $result = '';
        while ($col > 0) {
            $col--;
            $result = chr(65 + ($col % 26)).$result;
            $col = intdiv($col, 26);
        }

        return $result;
    }

    private function exportToPdf(Collection $rows, array $headers, string $filename): StreamedResponse
    {
        $html = $this->generateHtmlTable($rows, $headers);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $attachmentFilename = $filename.'.pdf';

        return response()->streamDownload(function () use ($dompdf): void {
            echo $dompdf->output();
        }, $attachmentFilename, ['Content-Type' => 'application/pdf']);
    }

    private function generateHtmlTable(Collection $rows, array $headers): string
    {
        $html = '<html><head><meta charset="UTF-8"><style>';
        $html .= 'body { font-family: Arial, sans-serif; margin: 10px; }';
        $html .= 'table { border-collapse: collapse; width: 100%; }';
        $html .= 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
        $html .= 'th { background-color: #4472C4; color: white; }';
        $html .= '</style></head><body>';
        $html .= '<table><thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>'.htmlspecialchars((string) $header).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $value = $this->formatValue($this->extractValue($row, $header));
                $html .= '<td>'.htmlspecialchars((string) $value).'</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';

        return $html;
    }

    private function formatValue($value): string | int | float | null
    {
        if ($value instanceof \BackedEnum) {
            // Backed enum (has a value property)
            return $value->value;
        } elseif ($value instanceof \UnitEnum) {
            // Pure enum
            return $value->name;
        } elseif ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        } elseif (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($value === null || $value === '') {
            return '';
        } else {
            return (string) $value;
        }
    }

    private function extractValue($row, $header)
    {
        return $row->{str_replace(' ', '_', strtolower($header))} ?? $row->{$header} ?? '';
    }
}
