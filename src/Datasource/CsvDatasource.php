<?php

declare(strict_types=1);

namespace Magmi\Core\Datasource;

class CsvDatasource implements DatasourceInterface
{
    private ?\SplFileObject $file = null;
    private ?string $filePath = null;

    /** @var string[] */
    private array $headers = [];

    private string $separator = ',';
    private string $enclosure = '"';
    private bool $headless = false;
    private bool $allowTruncatedLines = false;
    private bool $malformed = false;
    private int $recordCount = 0;

    public function getName(): string
    {
        return 'CSV Datasource';
    }

    public function open(array $config): void
    {
        $path = $config['file'] ?? '';
        if ($path === '' || !file_exists($path)) {
            throw new \RuntimeException("CSV file not found: {$path}");
        }
        $this->filePath = $path;

        $this->separator = $config['separator'] ?? ',';
        if ($this->separator === '\\t') {
            $this->separator = "\t";
        }
        $this->enclosure = $config['enclosure'] ?? '"';
        $this->headless = $config['headless'] ?? false;
        $this->allowTruncatedLines = $config['allow_truncated_lines'] ?? false;
        $this->malformed = $config['malformed'] ?? false;

        $this->file = new \SplFileObject($path, 'r');
        $this->file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $this->file->setCsvControl($this->separator, $this->enclosure, '');

        if ($this->headless) {
            $this->headers = [];
        } else {
            // Read header row
            $this->headers = $this->file->fgetcsv() ?: [];
            if (isset($this->headers[0])) {
                $this->headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $this->headers[0]);
            }
        }

        // Count records for getRecordsCount
        $this->recordCount = $this->countRecords($path);
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
        if (!$this->file) {
            throw new \RuntimeException('Datasource not opened.');
        }

        $index = 0;
        while (!$this->file->eof()) {
            $row = $this->file->fgetcsv();
            if ($row === false || $row === [null]) {
                continue;
            }

            // Skip empty rows
            if (count($row) === 1 && $row[0] === null) {
                continue;
            }

            // Handle truncated lines
            if (!$this->headless && count($row) !== count($this->headers)) {
                if (!$this->allowTruncatedLines || count($row) > count($this->headers)) {
                    continue;
                }
                // Pad with empty values so associative mapping matches the header count.
                $row = array_pad($row, count($this->headers), '');
            }

            if ($this->headless) {
                yield $index => $row;
            } else {
                yield $index => array_combine($this->headers, $row);
            }
            $index++;
        }
    }

    public function getNextRecord(): ?array
    {
        if (!$this->file) {
            throw new \RuntimeException('Datasource not opened.');
        }

        while (!$this->file->eof()) {
            $row = $this->file->fgetcsv();
            if ($row === false || $row === [null]) {
                continue;
            }
            if (count($row) === 1 && $row[0] === null) {
                continue;
            }
            if (!$this->headless && count($row) !== count($this->headers)) {
                if (!$this->allowTruncatedLines || count($row) > count($this->headers)) {
                    continue;
                }
                $row = array_pad($row, count($this->headers), '');
            }
            if ($this->headless) {
                return $row;
            }
            return array_combine($this->headers, $row);
        }
        return null;
    }

    public function getRecordsCount(): int
    {
        return $this->recordCount;
    }

    public function close(): void
    {
        $this->file = null;
        $this->filePath = null;
        $this->headers = [];
        $this->recordCount = 0;
    }

    private function countRecords(string $path): int
    {
        $count = 0;
        $temp = new \SplFileObject($path, 'r');
        $temp->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $temp->setCsvControl($this->separator, $this->enclosure, '');

        // Skip header if not headless
        if (!$this->headless) {
            $temp->fgetcsv();
        }

        while (!$temp->eof()) {
            $row = $temp->fgetcsv();
            if ($row === false || $row === [null]) {
                continue;
            }
            if (count($row) === 1 && $row[0] === null) {
                continue;
            }
            if (!$this->headless && count($row) !== count($this->headers) && (!$this->allowTruncatedLines || count($row) > count($this->headers))) {
                continue;
            }
            $count++;
        }
        return $count;
    }
}
