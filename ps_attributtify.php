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
            && $this->registerHook('actionPresentProduct')
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
     * Populate attribute_prices / attribute_prices_raw on the presented product
     * so that theme templates can use them directly.
     *
     * @throws PrestaShop\PrestaShop\Core\Localization\Exception\LocalizationException
     */
    public function hookActionPresentProduct(array &$params): void
    {
        $idProduct = (int) (
            $params['presentedProduct']['id'] ??
            $params['presentedProduct']['id_product'] ??
            0
        );
        if ($idProduct <= 0) {
            return;
        }

        $impactMin = $this->getAttributeImpactMin($idProduct);
        if (empty($impactMin)) {
            return;
        }

        // Format prices
        $prices = [];
        foreach ($impactMin as $idAttribute => $impact) {
            if ($impact == 0) {
                continue;
            }
            try {
                $prices[$idAttribute] = $this->context->currentLocale->formatPrice(
                    abs($impact),
                    $this->context->currency->iso_code
                );
            } catch (\Throwable $e) {
                $prices[$idAttribute] = number_format(abs($impact), 2);
            }
        }

        if (empty($prices)) {
            return;
        }

        // Inject formatted price into attribute names inside groups so the
        // theme template sees "Kit S (+€608.00)" without any template changes.
        $groups = $params['presentedProduct']['groups'] ?? null;
        if (!is_array($groups)) {
            return;
        }

        foreach ($groups as &$group) {
            if (!isset($group['attributes']) || !is_array($group['attributes'])) {
                continue;
            }
            foreach ($group['attributes'] as $idAttribute => &$attribute) {
                if (!isset($prices[$idAttribute])) {
                    continue;
                }
                $sign = $impactMin[$idAttribute] > 0 ? '+' : '';
                $attribute['name'] .= ' (' . $sign . $prices[$idAttribute] . ')';
            }
            unset($attribute);
        }
        unset($group);

        $this->context->smarty->assign('groups', $groups);
    }

    /**
     * Read per-attribute surcharges directly from the saved Attributtify rules.
     *
     * For each impact rule whose condition is a single attribute group + one or
     * more attribute values, map every listed attribute → impact value.
     * Rules with multi-pair conditions (combined effects) are skipped — they
     * cannot be attributed to a single attribute unambiguously.
     *
     * @return array<int, float>  id_attribute → impact value
     */
    private function getAttributeImpactMin(int $idProduct): array
    {
        $json = Configuration::get('ATTRIBUTTIFY_PRODUCT_' . $idProduct);
        if (empty($json)) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Support both old (flat array of rules) and new ({rows, show_price_groups}) format
        if (isset($decoded['rows']) && is_array($decoded['rows'])) {
            $rules            = $decoded['rows'];
            $showPriceGroups  = array_flip(
                array_map('intval', (array) ($decoded['show_price_groups'] ?? []))
            );
        } else {
            $rules            = $decoded;
            $showPriceGroups  = null; // old format: no filter
        }

        $impacts = []; // attr_id → impact

        foreach ($rules as $rule) {
            $priceType  = $rule['price_type'] ?? 'impact';
            $priceValue = (float) ($rule['price_value'] ?? 0);

            if ($priceType !== 'impact' && $priceType !== 'impact_pct') {
                continue;
            }

            foreach ((array) ($rule['condition_groups'] ?? []) as $cg) {
                $pairs = (array) ($cg['pairs'] ?? []);

                // Only single-pair conditions map cleanly to a per-attribute surcharge
                if (count($pairs) !== 1) {
                    continue;
                }

                $pairGroupId = (int) ($pairs[0]['id_attribute_group'] ?? 0);

                // Filter by show_price_groups when available
                if ($showPriceGroups !== null && !isset($showPriceGroups[$pairGroupId])) {
                    continue;
                }

                foreach ((array) ($pairs[0]['id_attributes'] ?? []) as $attrId) {
                    $attrId = (int) $attrId;
                    if ($attrId <= 0) {
                        continue;
                    }
                    // Keep the first-encountered value (rules are ordered by specificity)
                    if (!isset($impacts[$attrId])) {
                        $impacts[$attrId] = $priceValue;
                    }
                }
            }
        }

        return $impacts;
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
