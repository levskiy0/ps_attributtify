<?php
/**
 * AdminPsAttributtifyAjaxController
 *
 * Row data model (persisted JSON in Configuration):
 * {
 *   "condition_groups": [ { "pairs": [ { id_attribute_group, id_attributes[] }, ... ] }, ... ],
 *   "applies_to":       [ { id_attribute_group, id_attributes[] }, ... ],
 *   "excludes":         [ { id_attribute_group, id_attributes[] }, ... ],
 *   "price_type":       "fixed" | "impact" | "impact_pct",
 *   "price_value":      float,
 *   "qty":              int,
 *   "reference":        string,
 *   "weight":           float
 * }
 *
 * Generation algorithm:
 *   Phase 1 — FIXED rules: build base tuples as the cartesian product of each
 *             condition group's pairs (OR across groups inside a rule).
 *             If no fixed rules exist, fall back to impact-only tuples.
 *   Phase 2 — IMPACT rules iteratively expand base tuples along new axes,
 *             guarded against amplifying an axis that's already present.
 *   Pricing — most-specific matching fixed rule sets base price (specificity =
 *             pair_count * 10000 − total_values). All matching impact rules are
 *             summed; `impact_pct` is applied as a percentage of the base price.
 *   Qty    — most-specific matching FIXED rule wins; for impact-only tuples,
 *             the empty-applies_to impact rule's qty is used (or 0).
 *   Weight — fixed rules: most-specific wins; impact rules: deltas sum.
 *   Reference — pattern with {SKU}, {sku}, {attrs}, {N}, {n} placeholders; the
 *               most-specific matching rule's pattern wins. Empty → ATTY-<ids>.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminPsAttributtifyAjaxController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function viewAccess($disable = false): bool
    {
        return (bool) $this->context->employee->id;
    }

    public function postProcess(): void
    {
        $action = Tools::getValue('action');
        try {
            switch ($action) {
                case 'getGroups':      $this->ajaxGetGroups();      break;
                case 'getAttributes':  $this->ajaxGetAttributes();  break;
                case 'getCustomTypes': $this->ajaxGetCustomTypes();  break;
                case 'saveConfig':     $this->ajaxSaveConfig();      break;
                case 'loadConfig':     $this->ajaxLoadConfig();      break;
                case 'generate':       $this->ajaxGenerate();        break;
                case 'preview':        $this->ajaxPreview();         break;
                default:
                    $this->jsonResponse(false, 'Unknown action');
            }
        } catch (\Throwable $e) {
            $this->jsonResponse(false, 'Exception: ' . $e->getMessage());
        }
    }

    // ─── Attribute group list ────────────────────────────────────────────────

    protected function ajaxGetGroups(): void
    {
        $idLang = (int) $this->context->language->id;

        $rows = Db::getInstance()->executeS('
            SELECT ag.`id_attribute_group`, ag.`group_type`, ag.`position`,
                   agl.`name`
            FROM `' . _DB_PREFIX_ . 'attribute_group` ag
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                ON ag.`id_attribute_group` = agl.`id_attribute_group`
                AND agl.`id_lang` = ' . $idLang . '
            ORDER BY ag.`position` ASC, agl.`name` ASC
        ');

        $out = [];
        foreach ((array) $rows as $g) {
            $out[] = [
                'id_attribute_group' => (int) $g['id_attribute_group'],
                'name'               => $g['name'] ?? '',
                'group_type'         => $g['group_type'] ?? 'select',
            ];
        }
        $this->jsonResponse(true, '', ['groups' => $out]);
    }

    // ─── Attribute values for a group ────────────────────────────────────────

    protected function ajaxGetAttributes(): void
    {
        $idLang  = (int) $this->context->language->id;
        $idGroup = (int) Tools::getValue('id_attribute_group');
        if ($idGroup <= 0) {
            $this->jsonResponse(false, 'Missing id_attribute_group');
        }

        $rows = Db::getInstance()->executeS('
            SELECT a.`id_attribute`, al.`name`
            FROM `' . _DB_PREFIX_ . 'attribute` a
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                ON a.`id_attribute` = al.`id_attribute`
                AND al.`id_lang` = ' . $idLang . '
            WHERE a.`id_attribute_group` = ' . $idGroup . '
            ORDER BY a.`position` ASC, a.`id_attribute` ASC
        ');

        $out = [];
        foreach ((array) $rows as $a) {
            $out[] = [
                'id_attribute' => (int) $a['id_attribute'],
                'name'         => $a['name'] ?? '',
            ];
        }
        $this->jsonResponse(true, '', ['attributes' => $out]);
    }

    // ─── Custom group types ───────────────────────────────────────────────────

    protected function ajaxGetCustomTypes(): void
    {
        $json  = Configuration::get('ATTRIBUTTIFY_CUSTOM_TYPES');
        $types = [];
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $types = $decoded;
            }
        }
        $this->jsonResponse(true, '', ['custom_types' => $types]);
    }

    // ─── Save config ─────────────────────────────────────────────────────────

    protected function ajaxSaveConfig(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) {
            $this->jsonResponse(false, 'Invalid product id');
        }

        $raw  = Tools::getValue('rows');
        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($rows)) {
            $rows = [];
        }

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // ── Migrate legacy formats → condition_groups ─────────────────────
            if (!isset($row['condition_groups'])) {
                if (!isset($row['pairs']) && isset($row['id_attribute_group'])) {
                    $row['pairs'] = [[
                        'id_attribute_group' => (int) $row['id_attribute_group'],
                        'id_attributes'      => array_values(array_map('intval', $row['id_attributes'] ?? [])),
                    ]];
                }
                $row['condition_groups'] = [['pairs' => $row['pairs'] ?? []]];
            }

            $cleanGroups = [];
            foreach ((array) $row['condition_groups'] as $cg) {
                $cgPairs = [];
                foreach ((array) ($cg['pairs'] ?? []) as $pair) {
                    $gid = (int) ($pair['id_attribute_group'] ?? 0);
                    if ($gid <= 0) { continue; }
                    $cgPairs[] = [
                        'id_attribute_group' => $gid,
                        'id_attributes'      => array_values(array_map('intval', $pair['id_attributes'] ?? [])),
                    ];
                }
                if (!empty($cgPairs)) {
                    $cleanGroups[] = ['pairs' => $cgPairs];
                }
            }

            $priceType = in_array($row['price_type'] ?? 'impact', ['fixed', 'impact', 'impact_pct'], true)
                ? $row['price_type'] : 'impact';

            // Fixed rules must have at least one condition group with pairs.
            // Impact rules with no conditions apply to ALL combinations — allowed.
            if (empty($cleanGroups) && $priceType === 'fixed') {
                continue;
            }

            $cleanAppliesTo = [];
            foreach ((array) ($row['applies_to'] ?? []) as $atPair) {
                $gid = (int) ($atPair['id_attribute_group'] ?? 0);
                if ($gid <= 0) { continue; }
                $cleanAppliesTo[] = [
                    'id_attribute_group' => $gid,
                    'id_attributes'      => array_values(array_map('intval', $atPair['id_attributes'] ?? [])),
                ];
            }

            $cleanExcludes = [];
            foreach ((array) ($row['excludes'] ?? []) as $exPair) {
                $gid = (int) ($exPair['id_attribute_group'] ?? 0);
                if ($gid <= 0) { continue; }
                $cleanExcludes[] = [
                    'id_attribute_group' => $gid,
                    'id_attributes'      => array_values(array_map('intval', $exPair['id_attributes'] ?? [])),
                ];
            }

            $qtyVal = isset($row['qty']) ? (int) $row['qty'] : 0;
            if ($qtyVal < 0) { $qtyVal = 0; }

            $refVal = isset($row['reference']) ? (string) $row['reference'] : '';
            $refVal = strip_tags($refVal);
            if (strlen($refVal) > 255) { $refVal = substr($refVal, 0, 255); }

            $wtVal  = isset($row['weight']) ? (float) $row['weight'] : 0.0;

            $clean[] = [
                'condition_groups' => $cleanGroups,
                'applies_to'       => $cleanAppliesTo,
                'excludes'         => $cleanExcludes,
                'price_type'       => $priceType,
                'price_value'      => (float) ($row['price_value'] ?? 0),
                'qty'              => $qtyVal,
                'reference'        => $refVal,
                'weight'           => $wtVal,
            ];
        }

        Configuration::updateValue('ATTRIBUTTIFY_PRODUCT_' . $idProduct, json_encode($clean));
        $this->jsonResponse(true, 'Configuration saved', ['rows' => $clean]);
    }

    // ─── Load config ─────────────────────────────────────────────────────────

    protected function ajaxLoadConfig(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) {
            $this->jsonResponse(false, 'Invalid product id');
        }
        $json = Configuration::get('ATTRIBUTTIFY_PRODUCT_' . $idProduct);
        $rows = [];
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $rows = $decoded;
            }
        }
        $this->jsonResponse(true, '', ['rows' => $rows]);
    }

    // ─── Load product + config (shared by generate & preview) ────────────────

    protected function loadProductAndRows(int $idProduct): array
    {
        $product = new Product($idProduct);
        if (!Validate::isLoadedObject($product)) {
            $this->jsonResponse(false, 'Product not found');
        }

        $json = Configuration::get('ATTRIBUTTIFY_PRODUCT_' . $idProduct);
        $rows = [];
        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $rows = $decoded;
            }
        }
        if (empty($rows)) {
            $this->jsonResponse(false, 'No rules defined for this product');
        }

        return [$product, $rows];
    }

    // ─── Compute tuples from rules (Phase 1 + Phase 2) ───────────────────────

    protected function computeTuples(array $rows): array
    {
        $matchesPairs = static function (array $tuple, array $conditions): bool {
            foreach ($conditions as $cPair) {
                $attrs = array_values(array_filter(array_map('intval', $cPair['id_attributes'] ?? [])));
                if (!empty($attrs) && empty(array_intersect($tuple, $attrs))) {
                    return false;
                }
            }
            return true;
        };

        $matchesExcludes = static function (array $tuple, array $excludes): bool {
            foreach ($excludes as $exPair) {
                $attrs = array_values(array_filter(array_map('intval', $exPair['id_attributes'] ?? [])));
                if (!empty($attrs) && !empty(array_intersect($tuple, $attrs))) {
                    return true;
                }
            }
            return false;
        };

        $buildAxesFromPairs = static function (array $pairs): array {
            $axes = [];
            foreach ($pairs as $pair) {
                $attrs = array_values(array_filter(
                    array_map('intval', $pair['id_attributes'] ?? []),
                    static function (int $v): bool { return $v > 0; }
                ));
                if (!empty($attrs)) { $axes[] = $attrs; }
            }
            return $axes;
        };

        $normaliseRow = static function (array $row): array {
            if (!isset($row['condition_groups'])) {
                $row['condition_groups'] = [['pairs' => $row['pairs'] ?? []]];
            }
            return $row;
        };

        $tupleMap = [];
        $addTuple = static function (array $tuple) use (&$tupleMap): bool {
            $s = $tuple; sort($s);
            $k = implode('-', $s);
            if (array_key_exists($k, $tupleMap)) { return false; }
            $tupleMap[$k] = $tuple;
            return true;
        };

        // ── Phase 1: FIXED rules build base tuples ───────────────────────────
        foreach ($rows as $row) {
            if (($row['price_type'] ?? 'impact') !== 'fixed') { continue; }
            $row      = $normaliseRow($row);
            $excludes = (array) ($row['excludes'] ?? []);
            foreach ((array) $row['condition_groups'] as $cg) {
                $axes = $buildAxesFromPairs((array) ($cg['pairs'] ?? []));
                if (empty($axes)) { continue; }
                foreach ($this->cartesian($axes) as $tuple) {
                    if (!$matchesExcludes($tuple, $excludes)) { $addTuple($tuple); }
                }
            }
        }

        // ── Phase 1 fallback: impact-only mode ───────────────────────────────
        if (empty($tupleMap)) {
            foreach ($rows as $row) {
                $priceType = $row['price_type'] ?? 'impact';
                if ($priceType !== 'impact' && $priceType !== 'impact_pct') { continue; }
                if (!empty($row['applies_to'] ?? [])) { continue; }
                $row      = $normaliseRow($row);
                $excludes = (array) ($row['excludes'] ?? []);
                foreach ((array) $row['condition_groups'] as $cg) {
                    $axes = $buildAxesFromPairs((array) ($cg['pairs'] ?? []));
                    if (empty($axes)) { continue; }
                    foreach ($this->cartesian($axes) as $tuple) {
                        if (!$matchesExcludes($tuple, $excludes)) { $addTuple($tuple); }
                    }
                }
            }
        }

        // ── Build attr→group map for group-aware anti-amplification ─────────
        // Prevents multiple attributes from the same exclusive-choice group
        // (e.g. two installation kit sizes, two AI tiers) from being stacked
        // onto the same combination tuple across Phase 2 iterations.
        $attrGroupMap = [];
        foreach ($rows as $mapRow) {
            $mapRow = $normaliseRow($mapRow);
            foreach ($mapRow['condition_groups'] as $cg) {
                foreach ((array) ($cg['pairs'] ?? []) as $pair) {
                    $gid = (int) ($pair['id_attribute_group'] ?? 0);
                    if ($gid <= 0) { continue; }
                    foreach ((array) ($pair['id_attributes'] ?? []) as $aid) {
                        $attrGroupMap[(int) $aid] = $gid;
                    }
                }
            }
        }

        // ── Phase 2: impact rules expand tuples (iterative) ──────────────────
        $maxIterations = 10;
        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $added = 0;
            foreach ($rows as $row) {
                $priceType = $row['price_type'] ?? 'impact';
                if ($priceType !== 'impact' && $priceType !== 'impact_pct') { continue; }
                $row            = $normaliseRow($row);
                $condGroups     = (array) $row['condition_groups'];
                $appliesTo      = (array) ($row['applies_to'] ?? []);
                $excludes       = (array) ($row['excludes']   ?? []);

                $impactAxesAll  = [];
                $impactGroupIds = [];
                foreach ($condGroups as $cg) {
                    $axes = $buildAxesFromPairs((array) ($cg['pairs'] ?? []));
                    if (!empty($axes)) {
                        $impactAxesAll[] = $axes;
                    }
                    foreach ((array) ($cg['pairs'] ?? []) as $pair) {
                        $gid = (int) ($pair['id_attribute_group'] ?? 0);
                        if ($gid > 0) { $impactGroupIds[] = $gid; }
                    }
                }
                $impactGroupIds = array_unique($impactGroupIds);
                if (empty($impactAxesAll)) { continue; }

                foreach (array_values($tupleMap) as $baseTuple) {
                    if (!$matchesPairs($baseTuple, $appliesTo))  { continue; }
                    if ($matchesExcludes($baseTuple, $excludes)) { continue; }

                    // Group-aware anti-amplification: if the tuple already contains
                    // any attribute from the same group as what this rule adds,
                    // skip — prevents kit_10m + kit_20m or AI_1yr + AI_3yr stacking.
                    $tupleGroups = array_map(static function (int $aid) use ($attrGroupMap): int {
                        return $attrGroupMap[$aid] ?? 0;
                    }, $baseTuple);
                    if (!empty(array_intersect($tupleGroups, $impactGroupIds))) { continue; }

                    foreach ($impactAxesAll as $impactAxes) {
                        foreach ($this->cartesian($impactAxes) as $impactAttrs) {
                            $newTuple = array_merge($baseTuple, $impactAttrs);
                            if ($addTuple($newTuple)) { $added++; }
                        }
                    }
                }
            }
            if ($added === 0) { break; }
        }

        // ── Drop tuples missing a required impact group (always-on) ──────────
        // Every impact group is implicitly required — same as PrestaShop's own
        // combination generator. To make a group optional, add an explicit
        // "None" attribute with zero impact (that way the no-selection case is
        // represented by a real combination, not by the absence of the group).
        //
        // For each impact group G, collect all the applies_to conditions from
        // every rule that adds attributes from G. A tuple must contain at least
        // one attribute from G if ANY of those applies_to conditions match it.

        $impactGroupApplies = []; // group_id => [ applies_to[], ... ]
        foreach ($rows as $row) {
            $priceType = $row['price_type'] ?? 'impact';
            if ($priceType !== 'impact' && $priceType !== 'impact_pct') { continue; }
            $row       = $normaliseRow($row);
            $appliesTo = (array) ($row['applies_to'] ?? []);
            foreach ($row['condition_groups'] as $cg) {
                foreach ((array) ($cg['pairs'] ?? []) as $pair) {
                    $gid = (int) ($pair['id_attribute_group'] ?? 0);
                    if ($gid > 0) { $impactGroupApplies[$gid][] = $appliesTo; }
                }
            }
        }

        foreach ($impactGroupApplies as $reqGid => $allAppliesTo) {
            foreach ($tupleMap as $k => $tuple) {
                // Does any impact rule for group $reqGid apply to this tuple?
                $anyApplies = false;
                foreach ($allAppliesTo as $appliesTo) {
                    if ($matchesPairs($tuple, $appliesTo)) { $anyApplies = true; break; }
                }
                if (!$anyApplies) { continue; }

                // Tuple must contain at least one attribute from the required group
                $tupleGroups = array_map(static function (int $aid) use ($attrGroupMap): int {
                    return $attrGroupMap[$aid] ?? 0;
                }, $tuple);
                if (!in_array($reqGid, $tupleGroups, true)) {
                    unset($tupleMap[$k]);
                }
            }
        }

        return array_values($tupleMap);
    }

    // ─── Resolve row-specific values for a tuple (price / qty / weight / ref) ──

    protected function resolveTupleData(array $tuple, array $rows, float $basePrice): array
    {
        $matchesPairs = static function (array $tuple, array $conditions): bool {
            foreach ($conditions as $cPair) {
                $attrs = array_values(array_filter(array_map('intval', $cPair['id_attributes'] ?? [])));
                if (!empty($attrs) && empty(array_intersect($tuple, $attrs))) {
                    return false;
                }
            }
            return true;
        };

        $matchesExcludes = static function (array $tuple, array $excludes): bool {
            foreach ($excludes as $exPair) {
                $attrs = array_values(array_filter(array_map('intval', $exPair['id_attributes'] ?? [])));
                if (!empty($attrs) && !empty(array_intersect($tuple, $attrs))) {
                    return true;
                }
            }
            return false;
        };

        $matchesAnyGroup = static function (array $tuple, array $conditionGroups) use ($matchesPairs): bool {
            if (empty($conditionGroups)) { return true; }
            foreach ($conditionGroups as $cg) {
                if ($matchesPairs($tuple, (array) ($cg['pairs'] ?? []))) {
                    return true;
                }
            }
            return false;
        };

        $bestSpecificity = static function (array $tuple, array $conditionGroups) use ($matchesPairs): int {
            $best = PHP_INT_MIN;
            foreach ($conditionGroups as $cg) {
                $pairs = (array) ($cg['pairs'] ?? []);
                if (!$matchesPairs($tuple, $pairs)) { continue; }
                $totalValues = array_sum(array_map(static function ($p) {
                    return count($p['id_attributes'] ?? []);
                }, $pairs));
                $spec = count($pairs) * 10000 - $totalValues;
                if ($spec > $best) { $best = $spec; }
            }
            return $best;
        };

        $normaliseRow = static function (array $row): array {
            if (!isset($row['condition_groups'])) {
                $row['condition_groups'] = [['pairs' => $row['pairs'] ?? []]];
            }
            return $row;
        };

        $fixedPrice    = null;
        $maxFixedSpec  = PHP_INT_MIN;
        $impactSum     = 0.0;

        $qtyValue      = 0;
        $maxQtySpec    = PHP_INT_MIN;

        $refPattern    = '';
        $maxRefSpec    = PHP_INT_MIN;

        $fixedWeight   = 0.0;
        $maxWeightSpec = PHP_INT_MIN;
        $hasFixedWeight = false;
        $impactWeight  = 0.0;

        // Fallback qty for impact-only mode: first impact rule with empty applies_to
        $fallbackQty      = 0;
        $fallbackQtyFound = false;
        $fallbackRef      = '';
        $fallbackRefFound = false;

        foreach ($rows as $row) {
            $row        = $normaliseRow($row);
            $priceType  = $row['price_type'] ?? 'impact';
            $priceValue = (float) ($row['price_value'] ?? 0);
            $condGroups = (array) $row['condition_groups'];
            $excludes   = (array) ($row['excludes'] ?? []);
            $appliesTo  = (array) ($row['applies_to'] ?? []);
            $rowQty     = (int) ($row['qty'] ?? 0);
            $rowRef     = (string) ($row['reference'] ?? '');
            $rowWeight  = (float) ($row['weight'] ?? 0);

            if ($matchesExcludes($tuple, $excludes)) { continue; }

            $isEmptyCond = empty($condGroups)
                || (count($condGroups) === 1 && empty($condGroups[0]['pairs'] ?? []));

            if ($isEmptyCond) {
                // Unconditional impact rule — applies to every tuple (honouring applies_to)
                if ($priceType === 'impact' || $priceType === 'impact_pct') {
                    if ($matchesPairs($tuple, $appliesTo)) {
                        if ($priceType === 'impact_pct') {
                            $impactSum += $basePrice * ($priceValue / 100.0);
                        } else {
                            $impactSum += $priceValue;
                        }
                        $impactWeight += $rowWeight;

                        // Remember first empty-applies impact rule's qty/ref as fallback
                        if (empty($appliesTo)) {
                            if (!$fallbackQtyFound) {
                                $fallbackQty      = $rowQty;
                                $fallbackQtyFound = true;
                            }
                            if (!$fallbackRefFound && $rowRef !== '') {
                                $fallbackRef      = $rowRef;
                                $fallbackRefFound = true;
                            }
                        }
                    }
                }
                continue;
            }

            if (!$matchesAnyGroup($tuple, $condGroups)) { continue; }

            $specificity = $bestSpecificity($tuple, $condGroups);

            if ($priceType === 'fixed') {
                if ($specificity > $maxFixedSpec) {
                    $maxFixedSpec = $specificity;
                    $fixedPrice   = $priceValue;
                }
                if ($specificity > $maxQtySpec) {
                    $maxQtySpec = $specificity;
                    $qtyValue   = $rowQty;
                }
                if ($specificity > $maxRefSpec && $rowRef !== '') {
                    $maxRefSpec = $specificity;
                    $refPattern = $rowRef;
                }
                if ($specificity > $maxWeightSpec) {
                    $maxWeightSpec  = $specificity;
                    $fixedWeight    = $rowWeight;
                    $hasFixedWeight = true;
                }
            } else {
                // impact or impact_pct with conditions
                if ($matchesPairs($tuple, $appliesTo)) {
                    if ($priceType === 'impact_pct') {
                        $impactSum += $basePrice * ($priceValue / 100.0);
                    } else {
                        $impactSum += $priceValue;
                    }
                    $impactWeight += $rowWeight;
                }
            }
        }

        // If no fixed rule matched this tuple, use the impact-only fallback qty/ref
        if ($maxQtySpec === PHP_INT_MIN && $fallbackQtyFound) {
            $qtyValue = $fallbackQty;
        }
        if ($maxRefSpec === PHP_INT_MIN && $fallbackRefFound) {
            $refPattern = $fallbackRef;
        }

        $finalPrice  = ($fixedPrice !== null) ? $fixedPrice : $basePrice;
        $priceImpact = ($finalPrice + $impactSum) - $basePrice;
        $weightDelta = $hasFixedWeight ? ($fixedWeight + $impactWeight) : $impactWeight;

        return [
            'final_price'   => $finalPrice,
            'price_impact'  => $priceImpact,
            'qty'           => $qtyValue,
            'ref_pattern'   => $refPattern,
            'weight_delta'  => $weightDelta,
        ];
    }

    // ─── Generate combinations ────────────────────────────────────────────────

    protected function ajaxGenerate(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) {
            $this->jsonResponse(false, 'Invalid product id');
        }

        [$product, $rows] = $this->loadProductAndRows($idProduct);

        $tuples = $this->computeTuples($rows);
        if (empty($tuples)) {
            $this->jsonResponse(false, 'No valid attribute groups in configuration');
        }

        $autoRefs     = (Tools::getValue('auto_refs', '1') === '1');
        $basePrice    = (float) $product->price;
        $productRef   = (string) $product->reference;

        // Delete existing combinations
        try {
            $product->deleteProductAttributes();
        } catch (\Throwable $e) {
            $existingCombos = $product->getAttributesGroups($this->context->language->id);
            $seen = [];
            foreach ((array) $existingCombos as $c) {
                $idPA = (int) ($c['id_product_attribute'] ?? 0);
                if ($idPA > 0 && !in_array($idPA, $seen, true)) {
                    $seen[] = $idPA;
                    $combo  = new Combination($idPA);
                    if (Validate::isLoadedObject($combo)) {
                        $combo->delete();
                    }
                }
            }
        }

        $created  = 0;
        $skipped  = 0;
        $comboIds = [];
        $n        = 0;

        foreach ($tuples as $tuple) {
            $tuple = array_map('intval', $tuple);
            $n++;

            $data = $this->resolveTupleData($tuple, $rows, $basePrice);
            $reference = $this->resolveReference(
                (string) $data['ref_pattern'],
                $productRef,
                $tuple,
                $n,
                $autoRefs
            );

            try {
                $combo                    = new Combination();
                $combo->id_product        = $idProduct;
                $combo->price             = (float) $data['price_impact'];
                $combo->weight            = (float) $data['weight_delta'];
                $combo->reference         = $reference;
                $combo->minimal_quantity  = 1;
                $combo->default_on        = ($created === 0) ? 1 : 0;

                if (!$combo->add()) {
                    $skipped++;
                    continue;
                }

                $combo->setAttributes($tuple);
                StockAvailable::setQuantity($idProduct, (int) $combo->id, (int) $data['qty']);
                $comboIds[] = (int) $combo->id;
                $created++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        Db::getInstance()->update('product', ['cache_default_attribute' => 0], 'id_product = ' . $idProduct);
        Product::updateDefaultAttribute($idProduct);

        $this->jsonResponse(true, sprintf('Generated %d combination(s) (%d skipped)', $created, $skipped), [
            'created'      => $created,
            'skipped'      => $skipped,
            'total_tuples' => count($tuples),
            'combo_ids'    => $comboIds,
        ]);
    }

    // ─── Preview combinations (no DB writes) ─────────────────────────────────

    protected function ajaxPreview(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) {
            $this->jsonResponse(false, 'Invalid product id');
        }

        [$product, $rows] = $this->loadProductAndRows($idProduct);

        $tuples = $this->computeTuples($rows);
        if (empty($tuples)) {
            $this->jsonResponse(false, 'No valid attribute groups in configuration');
        }

        $autoRefs   = (Tools::getValue('auto_refs', '1') === '1');
        $basePrice  = (float) $product->price;
        $productRef = (string) $product->reference;
        $idLang     = (int) $this->context->language->id;

        // Collect unique attribute ids across all tuples for a single name lookup
        $allIds = [];
        foreach ($tuples as $tuple) {
            foreach ($tuple as $id) { $allIds[(int) $id] = true; }
        }
        $nameMap = $this->getAttributeNames(array_keys($allIds), $idLang);

        $preview = [];
        $n       = 0;
        foreach ($tuples as $tuple) {
            $tuple = array_map('intval', $tuple);
            $n++;

            $data = $this->resolveTupleData($tuple, $rows, $basePrice);
            $reference = $this->resolveReference(
                (string) $data['ref_pattern'],
                $productRef,
                $tuple,
                $n,
                $autoRefs
            );

            $attrNames = [];
            foreach ($tuple as $id) {
                $attrNames[] = $nameMap[$id] ?? ('#' . $id);
            }

            $preview[] = [
                'n'         => $n,
                'attrs'     => implode(' / ', $attrNames),
                'price'     => round($basePrice + (float) $data['price_impact'], 2),
                'impact'    => round((float) $data['price_impact'], 2),
                'qty'       => (int) $data['qty'],
                'reference' => $reference,
                'weight'    => round((float) $data['weight_delta'], 4),
            ];
        }

        $this->jsonResponse(true, '', [
            'preview' => $preview,
            'count'   => count($preview),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Fetch attribute names for a list of ids in the given language.
     *
     * @param int[] $ids
     *
     * @return array<int,string> id → name
     */
    protected function getAttributeNames(array $ids, int $idLang): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $v): bool {
            return $v > 0;
        })));
        if (empty($ids)) { return []; }

        $rows = Db::getInstance()->executeS('
            SELECT al.`id_attribute`, al.`name`
            FROM `' . _DB_PREFIX_ . 'attribute_lang` al
            WHERE al.`id_lang` = ' . (int) $idLang . '
              AND al.`id_attribute` IN (' . implode(',', $ids) . ')
        ');

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r['id_attribute']] = (string) ($r['name'] ?? '');
        }
        return $out;
    }

    /**
     * Resolve a reference pattern with placeholders.
     *   {SKU}, {sku} → product reference
     *   {attrs}      → tuple ids joined by '-'
     *   {N}, {n}     → 1-based tuple index
     * Empty pattern → 'ATTY-<sorted-ids>'.
     */
    protected function resolveReference(string $pattern, string $productRef, array $tuple, int $n, bool $autoFallback = true): string
    {
        if ($pattern === '') {
            if (!$autoFallback) {
                return '';
            }
            $sorted = $tuple;
            sort($sorted);
            return 'ATTY-' . implode('-', $sorted);
        }

        $attrsStr = implode('-', array_map('intval', $tuple));
        $out = $pattern;
        $out = str_replace(['{SKU}', '{sku}'],     $productRef, $out);
        $out = str_replace(['{attrs}', '{ATTRS}'], $attrsStr,   $out);
        $out = str_replace(['{N}', '{n}'],         (string) $n, $out);
        return $out;
    }

    /**
     * Cartesian product of a list of arrays.
     *
     * @param array[] $arrays
     *
     * @return array[]
     */
    protected function cartesian(array $arrays): array
    {
        $result = [[]];
        foreach ($arrays as $list) {
            if (empty($list)) {
                continue;
            }
            $tmp = [];
            foreach ($result as $existing) {
                foreach ($list as $item) {
                    $tmp[] = array_merge($existing, [$item]);
                }
            }
            $result = $tmp;
        }

        return $result;
    }

    /**
     * Emit a JSON response and terminate.
     */
    protected function jsonResponse(bool $success, string $message = '', array $data = []): void
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
        exit;
    }
}
