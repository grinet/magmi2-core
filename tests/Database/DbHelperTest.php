<?php

declare(strict_types=1);

namespace Magmi\Core\Tests\Database;

use Magmi\Core\Database\DbConfig;
use Magmi\Core\Database\DbHelper;
use PHPUnit\Framework\TestCase;

class DbHelperTest extends TestCase
{
    public function testTableNameWithPrefix(): void
    {
        $config = DbConfig::fromArray(['table_prefix' => 'mg_']);
        $db = new DbHelper($config);
        $this->assertSame('mg_catalog_product_entity', $db->tableName('catalog_product_entity'));
    }

    public function testTableNameWithoutPrefix(): void
    {
        $config = DbConfig::fromArray([]);
        $db = new DbHelper($config);
        $this->assertSame('catalog_product_entity', $db->tableName('catalog_product_entity'));
    }

    public function testDsnNet(): void
    {
        $config = DbConfig::fromArray([
            'host' => 'localhost',
            'port' => 3306,
            'dbname' => 'magento',
        ]);
        $this->assertSame('mysql:host=localhost;port=3306;dbname=magento;charset=utf8mb4', $config->getDsn());
    }

    public function testDsnSocket(): void
    {
        $config = DbConfig::fromArray([
            'connectivity' => 'socket',
            'unix_socket' => '/tmp/mysql.sock',
            'dbname' => 'magento',
        ]);
        $this->assertSame('mysql:unix_socket=/tmp/mysql.sock;dbname=magento;charset=utf8mb4', $config->getDsn());
    }
}
