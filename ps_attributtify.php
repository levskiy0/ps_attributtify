<?php
/**
 * Ps_Attributtify - Simplified product combination creation for PrestaShop 8.x
 *
 * Injects a combination builder panel into the Combinations tab of the new
 * Symfony-based product page by overriding the combination form theme Twig
 * template (documented PS8 approach: views/PrestaShop/... override path).
 *
 * @author    levskiy0 (https://github.com/levskiy0/ps_attributtify)
 * @copyright 2026 levskiy0
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Attributtify extends Module
{
    const CONF_CUSTOM_TYPES = 'ATTRIBUTTIFY_CUSTOM_TYPES';

    public function __construct()
    {
        $this->name = 'ps_attributtify';
        $this->tab = 'administration';
        $this->version = '1.4.7';
        $this->author = 'levskiy0';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Attributtify');
        $this->description = $this->l('Combination builder inside the Combinations tab — define rules, set prices, generate.');
        $this->confirmUninstall = $this->l('Remove Attributtify? All saved rule configurations will be deleted.');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('actionProductGetAttributesGroupsAfter')
            && $this->installTab('AdminPsAttributtifyAjax', 'Attributtify Ajax');
    }

    public function uninstall(): bool
    {
        /*
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name` LIKE "ATTRIBUTTIFY_PRODUCT_%"'
            . '    OR `name` = "' . pSQL(self::CONF_CUSTOM_TYPES) . '"'
        );
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration_lang`'
            . ' WHERE `id_configuration` NOT IN (SELECT `id_configuration` FROM `' . _DB_PREFIX_ . 'configuration`)'
        );
        */
        $this->uninstallTab('AdminPsAttributtifyAjax');

        return parent::uninstall();
    }

    // ─── Module settings (Configure button) ──────────────────────────────────

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('addCustomType')) {
            $output .= $this->processAddType();
        } elseif (Tools::isSubmit('deleteCustomType')) {
            $output .= $this->processDeleteType();
        }

        return $output . $this->renderSettingsPage();
    }

    protected function processAddType(): string
    {
        $id   = trim((string) Tools::getValue('type_id'));
        $name = trim((string) Tools::getValue('type_name'));

        if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $id)) {
            return $this->displayError(
                $this->l('Invalid type ID. Use lowercase letters, digits and underscores (start with a letter, max 50 chars).')
            );
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return $this->displayError($this->l('Display name is required (max 100 characters).'));
        }

        $types = $this->loadCustomTypes();
        foreach ($types as $t) {
            if ($t['id'] === $id) {
                return $this->displayError($this->l('A custom type with this ID already exists.'));
            }
        }

        $types[] = ['id' => $id, 'name' => $name];
        $this->saveCustomTypes($types);

        return $this->displayConfirmation($this->l('Custom type added.'));
    }

    protected function processDeleteType(): string
    {
        $delId = (string) Tools::getValue('type_id');
        $types = $this->loadCustomTypes();
        $types = array_values(array_filter($types, static function (array $t) use ($delId): bool {
            return $t['id'] !== $delId;
        }));
        $this->saveCustomTypes($types);

        return $this->displayConfirmation($this->l('Custom type removed.'));
    }

    protected function renderSettingsPage(): string
    {
        $actionUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name,
        ]);

        $this->context->smarty->assign([
            'types'      => $this->loadCustomTypes(),
            'action_url' => $actionUrl,
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/settings.tpl');
    }

    // ─── Custom types persistence ─────────────────────────────────────────────

    protected function loadCustomTypes(): array
    {
        $json = Configuration::get(self::CONF_CUSTOM_TYPES);
        if (empty($json)) {
            return [];
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    protected function saveCustomTypes(array $types): void
    {
        Configuration::updateValue(self::CONF_CUSTOM_TYPES, json_encode(array_values($types)));
    }

    // ─── Hooks ────────────────────────────────────────────────────────────────

    /**
     * Load CSS/JS on the product edit page and expose the AJAX URL to JS.
     */
    public function hookDisplayBackOfficeHeader(): void
    {
        $uri        = $_SERVER['REQUEST_URI'] ?? '';
        $controller = Tools::getValue('controller');

        $isNewProductPage    = strpos($uri, '/sell/catalog/products') !== false;
        $isLegacyProductPage = $controller === 'AdminProducts'
            && (Tools::getValue('addproduct') || Tools::getValue('updateproduct') || Tools::getValue('id_product'));

        if (!$isNewProductPage && !$isLegacyProductPage) {
            return;
        }

        $this->context->controller->addCSS($this->_path . 'views/css/select2.css?v=' . $this->version);
        $this->context->controller->addCSS($this->_path . 'views/css/attributtify.css?v=' . $this->version);
        $this->context->controller->addJS($this->_path . 'views/js/attributtify.js?v=' . $this->version);

        Media::addJsDef([
            'attributtifyAjaxUrl' => $this->context->link->getAdminLink('AdminPsAttributtifyAjax'),
        ]);
    }

    /**
     * Append formatted surcharge to attribute_name in the raw DB result.
     *
     * PS calls getAttributesGroups($lang, $id_product_attribute) for the current
     * combination. $params['id_product_attribute'] is that combination ID — we
     * use it to load the full attribute set of that specific combination, then
     * match each rule's condition_groups + applies_to + excludes against it to
     * find the correct impact price for each attribute in the result.
     */
    public function hookActionProductGetAttributesGroupsAfter(array &$params): void
    {
        $product   = $params['product'] ?? null;
        $idProduct = $product ? (int) $product->id : 0;
        if ($idProduct <= 0) {
            return;
        }

        $json = Configuration::get('ATTRIBUTTIFY_PRODUCT_' . $idProduct);
        if (empty($json)) {
            return;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return;
        }

        if (isset($decoded['rows']) && is_array($decoded['rows'])) {
            $rules = $decoded['rows'];
            $spg   = array_filter(array_map('intval', (array) ($decoded['show_price_groups'] ?? [])));
            if (empty($spg)) {
                return;
            }
            $showPriceGroups = array_flip($spg);
        } else {
            $rules           = $decoded;
            $showPriceGroups = null;
        }

        // Base price needed for impact_pct conversion
        $basePrice = 0.0;
        $prod = new Product($idProduct, false, $this->context->language->id);
        if (Validate::isLoadedObject($prod)) {
            $basePrice = (float) $prod->price;
        }

        // Determine the combination context for evaluating applies_to / excludes.
        //
        // PS calls getAttributesGroups with id_product_attribute = null in two cases:
        //   1. Initial page load — no selection yet → use the product's default combination.
        //   2. AJAX attribute switch — request carries group[id_group]=id_attribute params
        //      that describe the currently selected attributes → use those directly.
        //
        // When id_product_attribute IS provided (non-zero), we can load that combo's
        // attributes directly for an exact context.
        $idProductAttribute = (int) ($params['id_product_attribute'] ?? 0);

        if ($idProductAttribute > 0) {
            $pacRows = Db::getInstance()->executeS(
                'SELECT `id_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_combination`'
                . ' WHERE `id_product_attribute` = ' . $idProductAttribute
            );
            $comboContext = array_map('intval', array_column((array) $pacRows, 'id_attribute'));
        } else {
            // Try group[] request params first (AJAX combination switch)
            $groupParams = Tools::getValue('group');
            if (is_array($groupParams) && !empty($groupParams)) {
                $comboContext = array_values(array_map('intval', $groupParams));
            } else {
                // Initial page load: fall back to the default combination
                $defaultComboId = (int) Db::getInstance()->getValue(
                    'SELECT `cache_default_attribute` FROM `' . _DB_PREFIX_ . 'product`'
                    . ' WHERE `id_product` = ' . $idProduct
                );
                $pacRows = $defaultComboId > 0 ? Db::getInstance()->executeS(
                    'SELECT `id_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_combination`'
                    . ' WHERE `id_product_attribute` = ' . $defaultComboId
                ) : [];
                $comboContext = array_map('intval', array_column((array) $pacRows, 'id_attribute'));
            }
        }

        // Append the correct surcharge label to each attribute in the result
        foreach ($params['attributes_groups'] as &$row) {
            $attrId = (int) ($row['id_attribute'] ?? 0);
            if ($attrId <= 0) {
                continue;
            }

            $impact = $this->resolveImpactForAttr(
                $attrId, $comboContext, $rules, $showPriceGroups, $basePrice
            );

            if ($impact === null || $impact == 0) {
                continue;
            }

            try {
                $formatted = $this->context->currentLocale->formatPrice(
                    abs($impact),
                    $this->context->currency->iso_code
                );
            } catch (\Throwable $e) {
                $formatted = number_format(abs($impact), 2);
            }
            $sign = $impact > 0 ? '+' : '-';
            $row['attribute_name'] .= ' (' . $sign . $formatted . ')';
        }
        unset($row);
    }

    /**
     * Find the impact price for $attrId in the context of a given combination.
     *
     * Iterates rules in order and returns the first matching impact rule's value.
     * A rule matches when:
     *   - price_type is impact or impact_pct
     *   - applies_to conditions are satisfied by the combination's attribute set
     *   - excludes conditions are NOT triggered
     *   - condition_groups has exactly one pair containing $attrId in a show_price_group
     *
     * @param int[]      $comboContext    all attribute IDs of the current combination
     * @param array|null $showPriceGroups flipped map of allowed group IDs, or null = all
     */
    private function resolveImpactForAttr(
        int $attrId,
        array $comboContext,
        array $rules,
        ?array $showPriceGroups,
        float $basePrice
    ): ?float {
        foreach ($rules as $rule) {
            $priceType = $rule['price_type'] ?? 'impact';
            if ($priceType !== 'impact' && $priceType !== 'impact_pct') {
                continue;
            }

            // applies_to: every condition must be satisfied by the combo
            if (!$this->matchesConditions($comboContext, (array) ($rule['applies_to'] ?? []))) {
                continue;
            }

            // excludes: if any exclusion matches, skip this rule
            if ($this->matchesConditions($comboContext, (array) ($rule['excludes'] ?? []), false)) {
                continue;
            }

            foreach ((array) ($rule['condition_groups'] ?? []) as $cg) {
                $pairs = (array) ($cg['pairs'] ?? []);
                if (count($pairs) !== 1) {
                    continue;
                }

                $pairGroupId = (int) ($pairs[0]['id_attribute_group'] ?? 0);
                if ($showPriceGroups !== null && !isset($showPriceGroups[$pairGroupId])) {
                    continue;
                }

                $pairAttrs = array_map('intval', (array) ($pairs[0]['id_attributes'] ?? []));
                if (!in_array($attrId, $pairAttrs, true)) {
                    continue;
                }

                $value = (float) ($rule['price_value'] ?? 0);
                if ($priceType === 'impact_pct') {
                    $value = $basePrice * ($value / 100.0);
                }

                return $value;
            }
        }

        return null;
    }

    /**
     * Checks a list of attribute conditions against a combination's attribute set.
     *
     * When $requireAll is true (applies_to semantics): every condition must have
     * at least one of its id_attributes present in $attrIds.
     *
     * When $requireAll is false (excludes semantics): returns true if ANY condition
     * has at least one of its id_attributes present (i.e. the combo is excluded).
     *
     * @param int[]  $attrIds     attribute IDs of the current combination
     * @param array  $conditions  array of {id_attribute_group, id_attributes[]} pairs
     * @param bool   $requireAll  true = AND logic (applies_to), false = OR logic (excludes)
     */
    private function matchesConditions(array $attrIds, array $conditions, bool $requireAll = true): bool
    {
        if (empty($conditions)) {
            return $requireAll; // empty applies_to → always passes; empty excludes → never excluded
        }

        foreach ($conditions as $cond) {
            $required = array_values(array_filter(array_map('intval', $cond['id_attributes'] ?? [])));
            $hit = !empty($required) && !empty(array_intersect($attrIds, $required));

            if ($requireAll && !$hit) {
                return false; // AND: one miss → fail
            }
            if (!$requireAll && $hit) {
                return true; // OR: one hit → excluded
            }
        }

        return $requireAll; // AND: all passed → true; OR: none hit → false
    }

    // ─── Tab helpers ──────────────────────────────────────────────────────────

    private function installTab(string $className, string $tabName): bool
    {
        if (Tab::getIdFromClassName($className)) {
            return true;
        }

        $tab = new Tab();
        $tab->active     = 1;
        $tab->class_name = $className;
        $tab->id_parent  = -1;
        $tab->module     = $this->name;
        $tab->name       = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $tabName;
        }

        return (bool) $tab->add();
    }

    private function uninstallTab(string $className): void
    {
        $id = Tab::getIdFromClassName($className);
        if (!$id) {
            return;
        }
        (new Tab($id))->delete();
    }
}
