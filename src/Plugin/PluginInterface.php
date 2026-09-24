<?php

declare(strict_types=1);

namespace Magmi\Core\Plugin;

/**
 * Magmi-compatible plugin interface.
 * All hook methods return true by default (continue processing).
 * Return false from before/process hooks to skip/abort.
 */
interface PluginInterface
{
    /**
     * Unique plugin identifier (e.g. "categorycreator")
     */
    public function getIdentifier(): string;

    /**
     * Human-readable name (e.g. "On the fly category creator/importer")
     */
    public function getName(): string;

    /**
     * Version string (e.g. "v0.2.5")
     */
    public function getVersion(): string;

    /**
     * Short description of what this plugin does
     */
    public function getDescription(): string;

    /**
     * Plugin family: datasources, general, itemprocessors, etc.
     */
    public function getCategory(): string;

    /**
     * Called once at import start, before beforeImport.
     */
    public function startImport(array $context): bool;

    /**
     * Called when column names are retrieved from datasource.
     * Plugins can mutate the column list.
     */
    public function processColumnList(array &$columns, array $context): bool;

    /**
     * Called before import loop starts. Return false to abort.
     */
    public function beforeImport(array $context): bool;

    /**
     * Called for each item BEFORE SKU/product ID resolution.
     * Return false to skip this item.
     */
    public function processItemBeforeId(array &$item, array $context): bool;

    /**
     * Called for each item AFTER SKU/product ID resolution.
     * Return false to skip this item.
     */
    public function preprocessItemAfterId(array &$item, array $context): bool;

    /**
     * Called for each item AFTER SKU/product ID resolution (main processing).
     * Return false to skip this item.
     */
    public function processItemAfterId(array &$item, array $context): bool;

    /**
     * Called after an item is fully imported.
     */
    public function processItemAfterImport(array &$item, array $context): bool;

    /**
     * Called when an exception occurs during item processing.
     */
    public function processItemException(array &$item, array $context): void;

    /**
     * Called after all items are processed, before afterImport.
     */
    public function endImport(array $context): bool;

    /**
     * Called at the very end of import.
     */
    public function afterImport(array $context): bool;
}
