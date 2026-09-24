<?php

declare(strict_types=1);

namespace Magmi\Core\Datasource;

final class DatasourceFactory
{
    public static function open(string $file, array $config = []): DatasourceInterface
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, ['xlsx', 'xls'], true) || ($config['type'] ?? '') === 'excel') {
            $source = new ExcelDatasource();
        } else {
            $source = new CsvDatasource();
            if ($extension === 'tsv' && ($config['separator'] ?? ',') === ',') {
                $config['separator'] = "\t";
            }
        }
        try {
            $source->open(array_merge($config, ['file' => $file]));
            return $source;
        } catch (\Throwable $error) {
            $source->close();
            throw $error;
        }
    }
}
