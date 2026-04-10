<?php
/**
 * ps_attributtify — adds "checkbox" group type to the attribute group form.
 */

class AdminAttributesGroupsController extends AdminAttributesGroupsControllerCore
{
    /*
     * module: ps_attributtify
     * date: 2026-04-10 00:00:00
     * version: 1.4.0
     */
    public function renderForm()
    {
        $this->table = 'attribute_group';
        $this->identifier = 'id_attribute_group';

        $group_type = [
            [
                'id' => 'select',
                'name' => $this->trans('Drop-down list', [], 'Admin.Global'),
            ],
            [
                'id' => 'radio',
                'name' => $this->trans('Radio buttons', [], 'Admin.Global'),
            ],
            [
                'id' => 'color',
                'name' => $this->trans('Color or texture', [], 'Admin.Catalog.Feature'),
            ],
        ];

        $saved = Configuration::get('ATTRIBUTTIFY_CUSTOM_TYPES');
        if (!empty($saved)) {
            $custom = json_decode($saved, true);
            if (is_array($custom)) {
                foreach ($custom as $t) {
                    if (!empty($t['id']) && !empty($t['name'])) {
                        $group_type[] = ['id' => $t['id'], 'name' => $t['name']];
                    }
                }
            }
        }

        $this->fields_form = [
            'legend' => [
                'title' => $this->trans('Attributes', [], 'Admin.Catalog.Feature'),
                'icon' => 'icon-info-sign',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->trans('Name', [], 'Admin.Global'),
                    'name' => 'name',
                    'lang' => true,
                    'required' => true,
                    'col' => '4',
                    'hint' => $this->trans('Your internal name for this attribute.', [], 'Admin.Catalog.Help') . '&nbsp;' . $this->trans('Invalid characters:', [], 'Admin.Notifications.Info') . ' <>;=#{}',
                ],
                [
                    'type' => 'text',
                    'label' => $this->trans('Public name', [], 'Admin.Catalog.Feature'),
                    'name' => 'public_name',
                    'lang' => true,
                    'required' => true,
                    'col' => '4',
                    'hint' => $this->trans('The public name for this attribute, displayed to the customers.', [], 'Admin.Catalog.Help') . '&nbsp;' . $this->trans('Invalid characters:', [], 'Admin.Notifications.Info') . ' <>;=#{}',
                ],
                [
                    'type' => 'select',
                    'label' => $this->trans('Attribute type', [], 'Admin.Catalog.Feature'),
                    'name' => 'group_type',
                    'required' => true,
                    'options' => [
                        'query' => $group_type,
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'col' => '2',
                    'hint' => $this->trans('The way the attribute\'s values will be presented to the customers in the product\'s page.', [], 'Admin.Catalog.Help'),
                ],
            ],
        ];

        if (Shop::isFeatureActive()) {
            $this->fields_form['input'][] = [
                'type' => 'shop',
                'label' => $this->trans('Store association', [], 'Admin.Global'),
                'name' => 'checkBoxShopAsso',
            ];
        }

        $this->fields_form['submit'] = [
            'title' => $this->trans('Save', [], 'Admin.Actions'),
        ];

        if (!($obj = $this->loadObject(true))) {
            return;
        }

        // parent::renderForm() would overwrite $this->fields_form — skip it.
        // Call AdminController::renderForm() directly via a closure bound to the Core context.
        $render = Closure::bind(function () {
            return parent::renderForm();
        }, $this, 'AdminAttributesGroupsControllerCore');

        return $render();
    }
}
