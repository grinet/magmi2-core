<?php

declare(strict_types=1);

namespace Magmi\Core\Tests\Datasource;

use Magmi\Core\Datasource\ExcelDatasource;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class ExcelDatasourceTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension not available.');
        }
        $this->testFile = sys_get_temp_dir() . '/magmi_test_' . uniqid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testBasicExcel(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'sku');
        $sheet->setCellValue('B1', 'name');
        $sheet->setCellValue('C1', 'price');
        $sheet->setCellValue('A2', 'TEST1');
        $sheet->setCellValue('B2', 'Product 1');
        $sheet->setCellValue('C2', 10.00);
        $sheet->setCellValue('A3', 'TEST2');
        $sheet->setCellValue('B3', 'Product 2');
        $sheet->setCellValue('C3', 20.00);

        $writer = new Xlsx($spreadsheet);
        $writer->save($this->testFile);

        $ds = new ExcelDatasource();
        $ds->open(['file' => $this->testFile]);

        $this->assertSame(['sku', 'name', 'price'], $ds->getHeaders());
        $this->assertSame(['sku', 'name', 'price'], $ds->getColumnNames());
        $this->assertSame(2, $ds->getRecordsCount());

        $rows = iterator_to_array($ds->getRows());
        $this->assertCount(2, $rows);
        $this->assertSame('Product 1', $rows[0]['name']);

        $ds->close();
    }

    public function testGetNextRecord(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'sku');
        $sheet->setCellValue('B1', 'name');
        $sheet->setCellValue('A2', 'A');
        $sheet->setCellValue('B2', 'First');
        $sheet->setCellValue('A3', 'B');
        $sheet->setCellValue('B3', 'Second');

        $writer = new Xlsx($spreadsheet);
        $writer->save($this->testFile);

        $ds = new ExcelDatasource();
        $ds->open(['file' => $this->testFile]);

        $row1 = $ds->getNextRecord();
        $this->assertSame(['sku' => 'A', 'name' => 'First'], $row1);

        $row2 = $ds->getNextRecord();
        $this->assertSame(['sku' => 'B', 'name' => 'Second'], $row2);

        $this->assertNull($ds->getNextRecord());
        $ds->close();
    }

    public function testFileNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $ds = new ExcelDatasource();
        $ds->open(['file' => '/nonexistent/file.xlsx']);
    }
}
