# Attributtify

**Rule-based combination builder for PrestaShop 8.x**

Attributtify replaces the default cartesian combination generator with a rule engine. Instead of generating every possible attribute combination and manually editing prices, you define pricing rules with conditions — and the module generates exactly the combinations you need with the correct prices.

---

## The problem it solves

Standard PrestaShop combination generation is cartesian: pick all attribute groups, generate all combinations, then manually edit each price. For products with complex pricing (e.g. base model × color × accessory kit × subscription tier) this means editing hundreds of rows by hand.

**Example — a configurable product with base models, options and subscription tiers:**

| Combination | Price |
|---|---|
| Widget Basic White | $200 |
| Widget Basic Black | $200 |
| Widget Pro White | $450 |
| Widget Pro Black | $450 |
| Widget Pro White + Accessory Kit S | $450 + $40 |
| Widget Pro White + Accessory Kit S + Support 1 Year | $450 + $40 + $90 |
| ... | ... |

With Attributtify you define ~5 rules instead of editing 80+ combinations.

---

## How it works

### Rules table

Each row in the Attributtify table is a pricing rule with three tabs:

**Conditions** — AND-chain of (Attribute Group → Values) pairs. All pairs must match for the rule to apply. Multiple OR blocks mean any block can match.

**Applies to** — restrict an impact rule to combinations that already contain specific attribute pairs. Leave empty to apply to all.

**Excludes** — skip combinations that contain any of the listed attribute values.

### Price types

| Type | Behavior |
|---|---|
| **Fixed $** | Most-specific matching rule sets the exact combination price. Specificity = number of condition pairs × 10000 − total values. |
| **Impact $** | Dollar amount added to the base price. All matching impact rules are summed. |
| **Impact %** | Percentage of the base price added as impact. |

### Generation logic

1. **Phase 1** — Fixed rules define the base combination tuples and their prices.
2. **Phase 2** — Impact rules expand each tuple, adding price deltas. Anti-amplification guard prevents the same impact from applying multiple times to the same combination.
3. **Fallback** — If no fixed rules exist, the cartesian product of impact rules (where Applies to is empty) is used as the base.

---

## Interface

The panel appears inside the **Combinations** tab of the product page, above the standard combination generator.

### Toolbar

| Button | Action |
|---|---|
| **Save** | Persist the current rule table to the database (stored as JSON in `ps_configuration`). |
| **Load** | Discard unsaved changes and reload the last saved config. Prompts if there are unsaved changes. |
| **Preview** | Save → compute combinations server-side → show a preview table. No combinations are created yet. |
| **Generate** | Save → compute → create/update combinations in PrestaShop. Sets the first combination as default. |

### Settings strip

- **Ask for confirmation before deleting rows and blocks** — enables confirm dialogs for destructive actions. Persisted in `localStorage`.
- **Auto-generate references (ATTY-{attrs}) when no custom ref is set** — when unchecked, combinations without a custom reference pattern are created with an empty reference. Persisted in `localStorage`.

### Reference patterns

Custom reference field supports tokens:

| Token | Resolves to |
|---|---|
| `{attrs}` | Hyphen-joined attribute value IDs |
| `{n}` | Sequential combination number |
| `{product_ref}` | Product's base reference |

Example: `WK-{product_ref}-{n}` → `WK-SKU001-1`, `WK-SKU001-2`, …

---

## Installation

1. Upload the `ps_attributtify` folder to `/modules/`.
2. Install via **Modules → Module Manager**.
3. No configuration needed — the panel appears automatically on all product pages that have the Combinations tab.

### Requirements

- PrestaShop 8.0.0 or higher
- PHP 7.4+

---

## Uninstall

Uninstalling removes all saved rule configurations (`ATTRIBUTTIFY_PRODUCT_*` keys from `ps_configuration`). Generated combinations are not affected.

---

## Architecture

```
attributtify/
├── attributtify.php                          # Module class, hook registration
├── controllers/
│   └── admin/
│       └── AdminAttributtifyAjaxController.php   # AJAX: saveConfig, loadConfig, preview, generate
└── views/
    ├── css/
    │   ├── attributtify.css                  # Spreadsheet-style UI
    │   └── select2.css                       # Select2 bundled styles
    └── js/
        └── attributtify.js                   # Panel injection, rule builder, serialisation
```

### AJAX actions

| Action | Description |
|---|---|
| `saveConfig` | Saves serialised rules JSON for the product. |
| `loadConfig` | Returns saved rules JSON for the product. |
| `previewCombinations` | Computes combinations without writing to DB. Returns preview array. |
| `generateCombinations` | Computes and creates/updates combinations in PrestaShop. |

### Data storage

Rules are stored as JSON in `ps_configuration` under the key `ATTRIBUTTIFY_PRODUCT_{id_product}`.

Rule schema:

```json
{
  "price_type": "fixed | impact | impact_pct",
  "price_value": 1234.56,
  "qty": 1,
  "ref_pattern": "WK-{n}",
  "weight_delta": 0,
  "condition_groups": [
    {
      "pairs": [
        { "id_attribute_group": 3, "id_attributes": [12, 13] }
      ]
    }
  ],
  "applies_to": [],
  "excludes": []
}
```

---

## License

MIT — © 2026 [levskiy0](https://github.com/levskiy0/ps_attributtify)
