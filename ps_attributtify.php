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
    public function __construct()
    {
        $this->name = 'ps_attributtify';
        $this->tab = 'administration';
        $this->version = '1.3.2';
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
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall(): bool
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "PS_ATTRIBUTTIFY_PRODUCT_%"'
        );
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration_lang`'
            . ' WHERE `id_configuration` NOT IN (SELECT `id_configuration` FROM `' . _DB_PREFIX_ . 'configuration`)'
        );

        return parent::uninstall();
    }

    /**
     * Load CSS/JS on the product edit page and expose the AJAX URL to JS.
     */
    public function hookDisplayBackOfficeHeader(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // New Symfony product page: /sell/catalog/products/
        $isNewProductPage = strpos($uri, '/sell/catalog/products') !== false;
        // Legacy product page
        $isLegacyProductPage = Tools::getValue('controller') === 'AdminProducts'
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
}
