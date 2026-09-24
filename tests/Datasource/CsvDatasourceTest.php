<?php

declare(strict_types=1);

namespace Magmi\Core\Tests\Datasource;

use Magmi\Core\Datasource\CsvDatasource;
use PHPUnit\Framework\TestCase;

class CsvDatasourceTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/magmi_test_' . uniqid() . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testBasicCsv(): void
    {
        file_put_contents($this->testFile, "sku,name,price\nTEST1,Product 1,10.00\nTEST2,Product 2,20.00\n");

        $ds = new CsvDatasource();
        $ds->open(['file' => $this->testFile]);

        $this->assertSame(['sku', 'name', 'price'], $ds->getHeaders());
        $this->assertSame(['sku', 'name', 'price'], $ds->getColumnNames());
        $this->assertSame(2, $ds->getRecordsCount());

        $rows = iterator_to_array($ds->getRows());
        $this->assertCount(2, $rows);
        $this->assertSame('Product 1', $rows[0]['name']);

        $ds->close();
    }

    public function testCustomSeparator(): void
    {
        file_put_contents($this->testFile, "sku;name;price\nTEST1;Product 1;10.00\n");

        $ds = new CsvDatasource();
        $ds->open(['file' => $this->testFile, 'separator' => ';']);

        $this->assertSame(['sku', 'name', 'price'], $ds->getHeaders());
        $ds->close();
    }

    public function testHeadlessCsv(): void
    {
        file_put_contents($this->testFile, "TEST1,Product 1,10.00\nTEST2,Product 2,20.00\n");

        $ds = new CsvDatasource();
        $ds->open(['file' => $this->testFile, 'headless' => true]);

        $this->assertSame([], $ds->getHeaders());
        $this->assertSame(2, $ds->getRecordsCount());

        $rows = iterator_to_array($ds->getRows());
        $this->assertSame(['TEST1', 'Product 1', '10.00'], $rows[0]);
        $ds->close();
    }

    public function testGetNextRecord(): void
    {
        file_put_contents($this->testFile, "sku,name\nA,First\nB,Second\n");

        $ds = new CsvDatasource();
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
        $ds = new CsvDatasource();
        $ds->open(['file' => '/nonexistent/file.csv']);
    }
    public function testBomAndTruncatedRowsDoNotLoseSkuOrCrash(): void
    {
        file_put_contents($this->testFile, "\xEF\xBB\xBFsku,name\nA,First\nB\nC,Third,Extra\n");
        $ds = new CsvDatasource();
        $ds->open(['file' => $this->testFile, 'allow_truncated_lines' => true]);
        self::assertSame(['sku', 'name'], $ds->getColumnNames());
        self::assertSame(2, $ds->getRecordsCount());
        self::assertSame([['sku' => 'A', 'name' => 'First'], ['sku' => 'B', 'name' => '']], iterator_to_array($ds->getRows()));
        $ds->close();
    }

    public function testLiteralTabSeparator(): void
    {
        file_put_contents($this->testFile, "sku\tname\nA\tFirst\n");
        $ds = new CsvDatasource();
        $ds->open(['file' => $this->testFile, 'separator' => '\\t']);
        self::assertSame(['sku' => 'A', 'name' => 'First'], $ds->getNextRecord());
        $ds->close();
    }
}
