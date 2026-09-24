<?php

declare(strict_types=1);

namespace Magmi\Core\Database;

/**
 * Magento EAV attribute helper.
 * Loads and caches attribute metadata for efficient product attribute handling.
 */
class AttributeHelper
{
    private DbHelper $db;
    private ?int $productEntityTypeId = null;

    /** @var array<string, array<string, mixed>> */
    private array $attrCache = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $optionsCache = [];

    public function __construct(DbHelper $db)
    {
        $this->db = $db;
    }

    public function getProductEntityTypeId(): int
    {
        if ($this->productEntityTypeId === null) {
            $table = $this->db->tableName('eav_entity_type');
            $sql = "SELECT entity_type_id FROM {$table} WHERE entity_type_code = ?";
            $id = $this->db->selectOne($sql, ['catalog_product'], 'entity_type_id');
            $this->productEntityTypeId = $id !== null ? (int) $id : 4;
        }
        return $this->productEntityTypeId;
    }

    /**
     * Load attribute metadata for a list of attribute codes.
     *
     * @param string[] $codes
     */
    public function initAttrInfos(array $codes): void
    {
        $missing = array_diff($codes, array_keys($this->attrCache));
        if (empty($missing)) {
            return;
        }

        $entityTypeId = $this->getProductEntityTypeId();
        $eavTable = $this->db->tableName('eav_attribute');
        $catalogEavTable = $this->db->tableName('catalog_eav_attribute');

        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $sql = "SELECT a.attribute_id, a.attribute_code, a.backend_type, a.frontend_input, a.frontend_label,
                       a.is_required, a.default_value, a.note,
                       COALESCE(ca.is_global, 0) as is_global,
                       COALESCE(ca.is_visible, 1) as is_visible,
                       COALESCE(ca.is_searchable, 0) as is_searchable,
                       COALESCE(ca.is_filterable, 0) as is_filterable,
                       COALESCE(ca.is_comparable, 0) as is_comparable,
                       COALESCE(ca.is_visible_on_front, 0) as is_visible_on_front,
                       COALESCE(ca.is_html_allowed_on_front, 0) as is_html_allowed_on_front,
                       COALESCE(ca.used_in_product_listing, 0) as used_in_product_listing
                FROM {$eavTable} a
                LEFT JOIN {$catalogEavTable} ca ON ca.attribute_id = a.attribute_id
                WHERE a.entity_type_id = ? AND a.attribute_code IN ({$placeholders})";

        $params = array_merge([$entityTypeId], $missing);
        $rows = $this->db->selectAll($sql, $params);

        foreach ($rows as $row) {
            $this->attrCache[$row['attribute_code']] = $row;
        }

        // Cache nulls for missing attributes so we don't query again
        foreach ($missing as $code) {
            if (!isset($this->attrCache[$code])) {
                $this->attrCache[$code] = [];
            }
        }
    }

    /**
     * Get attribute info for a single attribute code.
     *
     * @return array<string, mixed>|null
     */
    public function getAttrInfo(string $code): ?array
    {
        if (!isset($this->attrCache[$code])) {
            $this->initAttrInfos([$code]);
        }
        $info = $this->attrCache[$code];
        return empty($info) ? null : $info;
    }

    /**
     * Check if a column name is a valid EAV attribute.
     */
    public function isAttribute(string $code): bool
    {
        $info = $this->getAttrInfo($code);
        return $info !== null;
    }

    /**
     * Get all loaded attribute codes.
     *
     * @return string[]
     */
    public function getLoadedAttrCodes(): array
    {
        return array_keys(array_filter($this->attrCache, fn($v) => !empty($v)));
    }

    /**
     * Get backend type for an attribute.
     */
    public function getBackendType(string $code): ?string
    {
        $info = $this->getAttrInfo($code);
        return $info['backend_type'] ?? null;
    }

    /**
     * Get attribute ID for an attribute code.
     */
    public function getAttributeId(string $code): ?int
    {
        $info = $this->getAttrInfo($code);
        return isset($info['attribute_id']) ? (int) $info['attribute_id'] : null;
    }

    /**
     * Get option IDs for select/multiselect attribute values.
     * Creates missing options on the fly.
     *
     * @return array<string, int>  value => option_id
     */
    public function getOptionIds(int $attributeId, int $storeId, array $values): array
    {
        $adminValues = [];
        $storeValues = [];
        $result = [];

        foreach ($values as $val) {
            $val = trim($val);
            if ($val === '') {
                continue;
            }
            // Handle translated values: value::[admin_value]
            if (preg_match('|^(.*)::\[(.*)\]$|', $val, $matches)) {
                $storeValues[] = $matches[1];
                $adminValues[] = $matches[2];
            } else {
                $storeValues[] = $val;
                $adminValues[] = $val;
            }
        }

        // Build cache key
        $cacheKey = "a{$attributeId}";
        if (!isset($this->optionsCache[$cacheKey])) {
            $this->optionsCache[$cacheKey] = [];
        }
        if (!isset($this->optionsCache[$cacheKey][$storeId])) {
            $this->optionsCache[$cacheKey][$storeId] = [];
            $existing = $this->loadOptionsFromDb($attributeId, $storeId);
            foreach ($existing as $opt) {
                $optionId = (int) $opt['opvs'];
                $this->optionsCache[$cacheKey][$storeId][$opt['value']] = $optionId;
            }
        }

        // Also ensure the admin cache includes the option ID lookup so that numeric
        // values (e.g. status=1, visibility=4) can be resolved as existing option IDs.
        if ($storeId === 0 && !isset($this->optionsCache[$cacheKey][0])) {
            $this->optionsCache[$cacheKey][0] = $this->optionsCache[$cacheKey][$storeId];
        }
        if (!isset($this->optionsCache[$cacheKey][0])) {
            $this->optionsCache[$cacheKey][0] = [];
            $existing = $this->loadOptionsFromDb($attributeId, 0);
            foreach ($existing as $opt) {
                $optionId = (int) $opt['opvs'];
                $this->optionsCache[$cacheKey][0][$opt['value']] = $optionId;
            }
        }
        // Add numeric option ID lookup to admin cache if not already present.
        foreach ($this->optionsCache[$cacheKey][0] as $label => $optionId) {
            if (is_numeric($label) && !isset($this->optionsCache[$cacheKey][0][(string) $optionId])) {
                $this->optionsCache[$cacheKey][0][(string) $optionId] = $optionId;
            }
        }

        $optTable = $this->db->tableName('eav_attribute_option');
        $valTable = $this->db->tableName('eav_attribute_option_value');

        // Pre-resolve numeric input values that are already valid option IDs.
        // This prevents shadowing by options that were accidentally created with
        // a numeric label (e.g. status=1 label mapped to option ID 212).
        $optionIdSet = [];
        foreach ($this->optionsCache[$cacheKey][0] as $label => $oid) {
            $optionIdSet[$oid] = true;
        }
        foreach ($adminValues as $idx => $av) {
            if (is_numeric($av) && isset($optionIdSet[(int) $av])) {
                $result[$av] = (int) $av;
                unset($adminValues[$idx]);
            }
        }

        // Create missing admin options
        $optHasGroup = $this->db->columnExists($optTable, 'group_id');

        foreach ($adminValues as $idx => $av) {
            if (!isset($this->optionsCache[$cacheKey][0][$av])) {
                if ($optHasGroup) {
                    $optId = $this->db->insert(
                        "INSERT INTO {$optTable} (attribute_id, sort_order, group_id) VALUES (?, ?, 0)",
                        [$attributeId, 0]
                    );
                } else {
                    $optId = $this->db->insert(
                        "INSERT INTO {$optTable} (attribute_id, sort_order) VALUES (?, ?)",
                        [$attributeId, 0]
                    );
                }
                $this->db->insert(
                    "INSERT INTO {$valTable} (option_id, store_id, value) VALUES (?, ?, ?)",
                    [$optId, 0, $av]
                );
                $this->optionsCache[$cacheKey][0][$av] = (int) $optId;
            }
        }

        // Create missing store-specific option values
        if ($storeId !== 0) {
            foreach ($storeValues as $idx => $sv) {
                $av = $adminValues[$idx];
                if ($sv !== $av && !isset($this->optionsCache[$cacheKey][$storeId][$sv])) {
                    $optId = $this->optionsCache[$cacheKey][0][$av];
                    $this->db->insert(
                        "INSERT INTO {$valTable} (option_id, store_id, value) VALUES (?, ?, ?)",
                        [$optId, $storeId, $sv]
                    );
                    $this->optionsCache[$cacheKey][$storeId][$sv] = (int) $optId;
                }
            }
        }

        // Collect option IDs for all requested values
        foreach ($adminValues as $av) {
            if (isset($this->optionsCache[$cacheKey][0][$av])) {
                $result[$av] = $this->optionsCache[$cacheKey][0][$av];
            }
        }

        return $result;
    }

    /**
     * Load existing options from DB.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadOptionsFromDb(int $attributeId, int $storeId): array
    {
        $optTable = $this->db->tableName('eav_attribute_option');
        $valTable = $this->db->tableName('eav_attribute_option_value');
        $sql = "SELECT optvals.option_id as opvs, optvals.value, opt.sort_order
                FROM {$valTable} as optvals
                JOIN {$optTable} as opt ON opt.option_id = optvals.option_id AND opt.attribute_id = ?
                WHERE optvals.store_id = ?";
        return $this->db->selectAll($sql, [$attributeId, $storeId]);
    }
}
