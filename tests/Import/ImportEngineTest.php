<?php

declare(strict_types=1);

namespace Magmi\Core\Tests\Import;

use Magmi\Core\Datasource\ArrayDatasource;
use Magmi\Core\Import\ImportEngine;
use Magmi\Core\Plugin\AbstractPlugin;
use Magmi\Core\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

final class ImportEngineTest extends TestCase
{
    public function testPreprocessorMutationsReachLaterHooksAndZeroSkuIsValid(): void
    {
        $manager = new PluginManager();
        $mapper = new class('mapper', 'Mapper', '1', 'preprocessors') extends AbstractPlugin {
            public function processItemBeforeId(array &$item, array $context): bool
            {
                $item['sku'] = $item['external_id'];
                $item['name'] = 'Mapped';
                return true;
            }
        };
        $observer = new class('observer', 'Observer', '1', 'itemprocessors') extends AbstractPlugin {
            public array $seen = [];
            public int $starts = 0;
            public int $ends = 0;
            public function startImport(array $context): bool { $this->starts++; return true; }
            public function endImport(array $context): bool { $this->ends++; return true; }
            public function processItemAfterImport(array &$item, array $context): bool
            {
                $this->seen[] = $item;
                return true;
            }
        };
        foreach ([$mapper, $observer] as $plugin) {
            $manager->register($plugin);
            $manager->enable($plugin->getIdentifier());
        }
        $source = new ArrayDatasource();
        $source->setRecords([['external_id' => '0', 'name' => 'Original']]);
        $engine = new ImportEngine($manager);
        $engine->setContext(['dry_run' => true]);
        $engine->setDatasource($source);
        $report = $engine->import();
        self::assertSame(1, $report['processed']);
        self::assertSame('Mapped', $observer->seen[0]['name']);
        self::assertSame('0', $observer->seen[0]['sku']);
        self::assertSame(1, $observer->starts);
        self::assertSame(1, $observer->ends);
    }

    public function testAbortStopsRecordsAndStillEndsSession(): void
    {
        $manager = new PluginManager();
        $plugin = new class('abort', 'Abort', '1', 'itemprocessors') extends AbstractPlugin {
            public bool $ended = false;
            public function beforeImport(array $context): bool { return false; }
            public function endImport(array $context): bool { $this->ended = true; return true; }
        };
        $manager->register($plugin);
        $manager->enable('abort');
        $source = new ArrayDatasource();
        $source->setRecords([['sku' => 'A']]);
        $engine = new ImportEngine($manager);
        $engine->setContext(['dry_run' => true]);
        $engine->setDatasource($source);
        $report = $engine->import();
        self::assertFalse($report['status']);
        self::assertSame(0, $report['processed']);
        self::assertTrue($plugin->ended);
    }

    public function testMissingDatabaseCannotReportSuccess(): void
    {
        $engine = new ImportEngine(new PluginManager());
        $this->expectException(\RuntimeException::class);
        $engine->beginSession(['sku']);
    }

    public function testInvalidModeFailsBeforeImport(): void
    {
        $engine = new ImportEngine(new PluginManager());
        $engine->setContext(['mode' => 'typo', 'dry_run' => true]);
        $this->expectException(\InvalidArgumentException::class);
        $engine->beginSession(['sku']);
    }
}
