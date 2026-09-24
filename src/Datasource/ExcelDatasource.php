<?php

declare(strict_types=1);

namespace Magmi\Core\Datasource;

/**
 * Excel datasource using PhpSpreadsheet.
 * Requires "phpoffice/phpspreadsheet" in consuming project.
 */
class ExcelDatasource implements DatasourceInterface
{
    private mixed $spreadsheet = null;
    private mixed $sheet = null;
    private int $currentRow = 2;
    private int $highestRow = 0;

    /** @var string[] */
    private array $headers = [];

    public function getName(): string
    {
        return 'Excel Datasource';
    }

    public function open(array $config): void
    {
        $path = $config['file'] ?? '';
        if ($path === '' || !file_exists($path)) {
            throw new \RuntimeException("Excel file not found: {$path}");
        }

        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new \RuntimeException('PhpSpreadsheet is required. Run: composer require phpoffice/phpspreadsheet');
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $this->spreadsheet = $reader->load($path);
        $this->sheet = $this->spreadsheet->getActiveSheet();
        $this->highestRow = (int) $this->sheet->getHighestRow();

        // Read headers from first row
        $headerRow = $this->sheet->rangeToArray('A1:' . $this->sheet->getHighestColumn() . '1', null, true, false);
        $this->headers = $headerRow[0] ?? [];
        $this->currentRow = 2;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getColumnNames(): array
    {
        return $this->getHeaders();
    }

    public function getRows(): \Generator
    {
        if (!$this->sheet) {
            throw new \RuntimeException('Datasource not opened.');
        }

        $highestColumn = $this->sheet->getHighestColumn();

        for ($row = $this->currentRow; $row <= $this->highestRow; $row++) {
            $rowData = $this->sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, null, true, false);
            $values = $rowData[0] ?? [];

            // Skip completely empty rows
            if (empty(array_filter($values, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            yield ($row - 2) => array_combine($this->headers, $values);
        }
    }

    public function getNextRecord(): ?array
    {
        if (!$this->sheet) {
            throw new \RuntimeException('Datasource not opened.');
        }

        if ($this->currentRow > $this->highestRow) {
            return null;
        }

        $highestColumn = $this->sheet->getHighestColumn();
        $rowData = $this->sheet->rangeToArray('A' . $this->currentRow . ':' . $highestColumn . $this->currentRow, null, true, false);
        $values = $rowData[0] ?? [];
        $this->currentRow++;

        if (empty(array_filter($values, fn($v) => $v !== null && $v !== ''))) {
            return $this->getNextRecord();
        }

        return array_combine($this->headers, $values);
    }

    public function getRecordsCount(): int
    {
        return max(0, $this->highestRow - 1);
    }

    public function close(): void
    {
        if ($this->spreadsheet !== null) {
            $this->spreadsheet->disconnectWorksheets();
        }
        $this->spreadsheet = null;
        $this->sheet = null;
        $this->headers = [];
        $this->currentRow = 2;
        $this->highestRow = 0;
    }
}
