/**
 * Attributtify — Combination Builder JS (tab-based compact UI).
 *
 * Each row is a pricing RULE with three tabs:
 *   1. Conditions — AND-chain of (Group → Values) pairs, OR between groups.
 *   2. Applies to — optional filter (impact rules only).
 *   3. Excludes   — optional blacklist of attribute pairs.
 *
 * Most-specific matching fixed rule sets base price; impact rules stack.
 */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') { return; }

    // ── Select2 helper ────────────────────────────────────────────────────────
    function s2($el, opts) {
        if (typeof $.fn.select2 !== 'function') { return; }
        if ($el.data('select2')) { try { $el.select2('destroy'); } catch (e) {} }
        $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
    }

    // ── Build panel HTML ──────────────────────────────────────────────────────
    function buildPanelHtml(productId) {
        return [
            '<div id="attributtify-wrapper" class="card mb-3" data-product-id="' + productId + '">',
            '  <div class="card-header attributtify-card-header"',
            '       data-toggle="collapse" data-target="#attributtify-body"',
            '       aria-expanded="false" role="button" style="cursor:pointer">',
            '    <h3 class="card-header-title d-flex align-items-center justify-content-between mb-0">',
            '      <span class="d-flex align-items-center">',
            '        <i class="material-icons mr-2">tune</i>',
            '        Attributtify \u2014 Combination Builder',
            '      </span>',
            '      <i class="material-icons attributtify-chevron">expand_more</i>',
            '    </h3>',
            '  </div>',
            '  <div class="collapse" id="attributtify-body">',
            '    <div class="card-body" style="padding:0">',
            '      <table class="att-sheet">',
            '        <colgroup><col><col><col><col><col></colgroup>',
            '        <thead><tr>',
            '          <th>Rule conditions</th>',
            '          <th>Price type</th>',
            '          <th>Amount</th>',
            '          <th>Qty / Ref / Wt</th>',
            '          <th></th>',
            '        </tr></thead>',
            '        <tbody id="attributtify-rows"></tbody>',
            '        <tfoot>',
            '          <tr id="attributtify-add-row" class="att-add-row-foot">',
            '            <td colspan="5">',
            '              <i class="material-icons" style="font-size:14px;vertical-align:middle">add</i> Add row',
            '            </td>',
            '          </tr>',
            '        </tfoot>',
            '      </table>',
            '      <div class="attributtify-toolbar">',
            '        <button type="button" class="btn btn-outline-secondary btn-sm" id="attributtify-save">',
            '          <i class="material-icons" style="font-size:16px;vertical-align:middle">save</i> Save',
            '        </button>',
            '        <button type="button" class="btn btn-outline-secondary btn-sm" id="attributtify-load">',
            '          <i class="material-icons" style="font-size:16px;vertical-align:middle">refresh</i> Load',
            '        </button>',
            '        <button type="button" class="btn btn-success btn-sm" id="attributtify-generate">',
            '          <i class="material-icons" style="font-size:16px;vertical-align:middle">visibility</i> Preview &amp; Generate',
            '        </button>',
            '        <span id="attributtify-status"></span>',
            '      </div>',
            '      <div class="att-delete-settings">',
            '        <label class="att-confirm-label">',
            '          <input type="checkbox" id="att-confirm-delete">',
            '          Ask for confirmation before deleting rows and blocks',
            '        </label>',
            '        <label class="att-confirm-label">',
            '          <input type="checkbox" id="att-auto-refs">',
            '          Auto-generate references (ATTY-{attrs}) when no custom ref is set',
            '        </label>',
            '      </div>',
            '      <div class="attributtify-legend">',
            '        <strong>Conditions</strong> \u2014 chain of Group\u2192Values pairs; ALL must match for the rule to apply. Multiple OR blocks = any block can match.',
            '        &nbsp;&nbsp;<strong>Applies to</strong> \u2014 restrict this impact rule to combinations that already contain the specified pairs; leave empty to apply to all.',
            '        &nbsp;&nbsp;<strong>Excludes</strong> \u2014 skip combinations that contain any of the specified values.',
            '        &nbsp;&nbsp;<strong>Fixed $</strong> \u2014 most specific matching rule sets the exact combination price.',
            '        &nbsp;&nbsp;<strong>Impact $/%</strong> \u2014 all matching impact rules are summed and added to the base price.',
            '      </div>',
            '      <div class="attributtify-repo-link">',
            '        <a href="https://github.com/levskiy0/ps_attributtify" target="_blank" rel="noopener noreferrer" class="att-gh-btn">',
            '          <svg class="att-gh-icon" viewBox="0 0 16 16" aria-hidden="true">',
            '            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38',
            '              0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13',
            '              -.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66',
            '              .07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15',
            '              -.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27',
            '              .68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12',
            '              .51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48',
            '              0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/>',
            '          </svg>',
            '          Module page',
            '        </a>',
            '      </div>',
            '    </div>',
            '  </div>',
            '</div>',
            '<div id="att-preview-modal" class="att-preview-overlay" style="display:none">',
            '  <div class="att-preview-dialog">',
            '    <div class="att-preview-header">',
            '      <span>Preview: <strong id="att-preview-count">0</strong> combinations</span>',
            '      <button type="button" class="att-preview-close" id="att-preview-close">&times;</button>',
            '    </div>',
            '    <div class="att-preview-body">',
            '      <table class="att-preview-table">',
            '        <thead><tr>',
            '          <th>#</th>',
            '          <th>Combination</th>',
            '          <th>Price</th>',
            '          <th>Impact</th>',
            '          <th>Qty</th>',
            '          <th>Reference</th>',
            '          <th>Weight</th>',
            '        </tr></thead>',
            '        <tbody id="att-preview-rows"></tbody>',
            '      </table>',
            '    </div>',
            '    <div class="att-preview-footer">',
            '      <button type="button" class="btn btn-secondary btn-sm" id="att-preview-cancel">Cancel</button>',
            '      <button type="button" class="btn btn-success btn-sm" id="att-preview-confirm">',
            '        <i class="material-icons" style="font-size:16px;vertical-align:middle">sync</i> Confirm &amp; Generate',
            '      </button>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('\n');
    }

    // ── Inject panel into combinations tab ────────────────────────────────────
    function injectPanel(productId) {
        if ($('#attributtify-wrapper').length) { return; }

        var html = buildPanelHtml(productId);

        var $gen = $('#product_combinations_generator');
        if ($gen.length) {
            var $formGroup = $gen.closest('.form-group');
            ($formGroup.length ? $formGroup : $gen).before(html);
            return;
        }

        var $legacy = $('#combinations');
        if ($legacy.length) {
            $legacy.before(html);
            return;
        }

        var attempts = 0;
        var interval = setInterval(function () {
            attempts++;
            var $g   = $('#product_combinations_generator');
            var $leg = $('#combinations');
            if ($g.length || $leg.length) {
                clearInterval(interval);
                if ($('#attributtify-wrapper').length) { return; }
                if ($g.length) {
                    var $fg = $g.closest('.form-group');
                    ($fg.length ? $fg : $g).before(html);
                } else {
                    $leg.before(html);
                }
                init(productId);
            } else if (attempts > 50) {
                clearInterval(interval);
            }
        }, 100);
    }

    $(function () {
        var ajaxUrl = (typeof attributtifyAjaxUrl !== 'undefined') ? attributtifyAjaxUrl : '';
        if (!ajaxUrl) { return; }

        function resolveProductId() {
            var id = 0;

            if (typeof window.productId !== 'undefined' && parseInt(window.productId, 10) > 0) {
                return parseInt(window.productId, 10);
            }

            id = parseInt($('form[data-product-id]').data('product-id'), 10) || 0;
            if (id > 0) { return id; }

            var pathMatch = window.location.pathname.match(/\/products\/(\d+)/);
            if (pathMatch) { id = parseInt(pathMatch[1], 10) || 0; }
            if (id > 0) { return id; }

            var $hidden = $('input[name="product[id]"], input[name="id_product"]').first();
            if ($hidden.length) { id = parseInt($hidden.val(), 10) || 0; }
            if (id > 0) { return id; }

            var action = $('form').first().attr('action') || '';
            var actionMatch = action.match(/\/products\/(\d+)/);
            if (actionMatch) { id = parseInt(actionMatch[1], 10) || 0; }
            if (id > 0) { return id; }

            var urlParams = new URLSearchParams(window.location.search);
            id = parseInt(urlParams.get('id_product') || urlParams.get('productId'), 10) || 0;

            return id;
        }

        var productId = resolveProductId();
        console.log('[Attributtify] productId =', productId, '| URL:', window.location.href);

        injectPanel(productId);

        if ($('#attributtify-wrapper').length) {
            init(productId);
        }

        function syncVisibility() {
            var isCombinations = $('input[name="show_variations"][value="1"]').is(':checked');
            $('#attributtify-wrapper').toggle(isCombinations);
        }

        syncVisibility();
        $(document).on('change', 'input[name="show_variations"]', syncVisibility);
    });

    // ── Main initialisation ───────────────────────────────────────────────────
    function init(productId) {
        var ajaxUrl = (typeof attributtifyAjaxUrl !== 'undefined') ? attributtifyAjaxUrl : '';
        var $status = $('#attributtify-status');
        var $rows   = $('#attributtify-rows');

        var groupsCache = null;
        var attrCache   = {};

        function setStatus(msg, type) {
            $status.removeClass('text-success text-danger text-warning text-info')
                   .addClass('text-' + (type || 'info'))
                   .text(msg || '');
        }

        // ── Preferences (localStorage) ────────────────────────────────────────
        function lsPref($chk, key, defaultOn) {
            var saved = localStorage.getItem(key);
            $chk.prop('checked', saved === null ? defaultOn : saved === '1');
            $chk.on('change', function () {
                localStorage.setItem(key, $(this).is(':checked') ? '1' : '0');
            });
        }

        var $confirmChk = $('#att-confirm-delete');
        var $autoRefsChk = $('#att-auto-refs');
        lsPref($confirmChk,  'attributtify_confirm_delete', true);
        lsPref($autoRefsChk, 'attributtify_auto_refs',      true);

        function shouldConfirm() { return $confirmChk.is(':checked'); }

        // ── Dirty tracking ────────────────────────────────────────────────────
        var isDirty = false;
        function markDirty() { isDirty = true; }
        function markClean() { isDirty = false; }

        function ajax(action, data) {
            return $.ajax({
                url: ajaxUrl, type: 'POST', dataType: 'json',
                data: $.extend({ action: action, ajax: 1 }, data || {})
            });
        }

        function opt(value, label, selected) {
            return $('<option>').val(value).text(label).prop('selected', !!selected);
        }

        // ── Data loaders ──────────────────────────────────────────────────────
        function loadGroups() {
            if (groupsCache) { return $.Deferred().resolve(groupsCache).promise(); }
            return ajax('getGroups').then(function (r) {
                groupsCache = (r && r.success) ? (r.groups || []) : [];
                return groupsCache;
            });
        }

        function loadAttributes(idGroup) {
            idGroup = parseInt(idGroup, 10);
            if (!idGroup) { return $.Deferred().resolve([]).promise(); }
            if (attrCache[idGroup]) { return $.Deferred().resolve(attrCache[idGroup]).promise(); }
            return ajax('getAttributes', { id_attribute_group: idGroup }).then(function (r) {
                attrCache[idGroup] = (r && r.success) ? (r.attributes || []) : [];
                return attrCache[idGroup];
            });
        }

        // ── Select2 apply helpers ─────────────────────────────────────────────
        function applyS2Group($sel) {
            s2($sel, { placeholder: '\u2014 group \u2014' });
        }

        function applyS2Multi($sel, placeholder) {
            s2($sel, { placeholder: placeholder || '\u2014 select \u2014' });
        }

        // ── Fill values select ────────────────────────────────────────────────
        function fillVals($sel, attrs, selected) {
            if ($sel.data('select2')) { try { $sel.select2('destroy'); } catch (e) {} }
            $sel.empty();
            (attrs || []).forEach(function (a) {
                var isSel = (selected || []).some(function (id) {
                    return parseInt(id, 10) === parseInt(a.id_attribute, 10);
                });
                $sel.append(opt(a.id_attribute, a.name, isSel));
            });
            applyS2Multi($sel, 'Select values\u2026');
        }

        // ── Build a single condition pair (Group → Values) ────────────────────
        function buildPair(pairData) {
            pairData = pairData || {};

            var $grp  = $('<select class="form-control form-control-sm attributtify-group">').append(opt('', ''));
            var $vals = $('<select multiple class="form-control form-control-sm attributtify-values">');
            var $rm   = $('<button type="button" class="att-remove-pair" title="Remove condition">' +
                '<i class="material-icons">close</i></button>');

            var $pair = $('<div class="att-pair">').append(
                $('<div class="att-pair-group">').append($grp),
                $('<div class="att-pair-values">').append($vals),
                $('<div class="att-pair-remove">').append($rm)
            );

            loadGroups().done(function (groups) {
                groups.forEach(function (g) {
                    $grp.append(opt(
                        g.id_attribute_group,
                        g.name,
                        parseInt(g.id_attribute_group, 10) === parseInt(pairData.id_attribute_group, 10)
                    ));
                });
                applyS2Group($grp);

                if (pairData.id_attribute_group) {
                    loadAttributes(pairData.id_attribute_group).done(function (attrs) {
                        fillVals($vals, attrs, pairData.id_attributes || []);
                    });
                } else {
                    applyS2Multi($vals, 'Select values\u2026');
                }
            });

            return $pair;
        }

        // ── Build a single condition group (one AND-chain) ────────────────────
        function buildConditionGroup(cgData) {
            cgData = cgData || {};
            var $cg    = $('<div class="att-condition-group">');
            var $chain = $('<div class="att-chain att-main-chain">').appendTo($cg);
            (cgData.pairs || [{}]).forEach(function (p) { $chain.append(buildPair(p)); });
            // Footer: "+ condition" left, "× remove block" right (hidden until 2+ OR blocks exist)
            $chain.append(
                $('<div class="att-chain-footer">').append(
                    $('<button type="button" class="att-add-pair">')
                        .html('<i class="material-icons" style="font-size:12px;vertical-align:middle">add</i> condition'),
                    $('<button type="button" class="att-remove-cg" title="Remove this OR-block">')
                        .html('<i class="material-icons">close</i>')
                        .hide()
                )
            );
            return $cg;
        }

        // ── Sync badges on Applies / Excludes tabs ────────────────────────────
        function syncTabBadges($ruleCell) {
            var appliesCount  = $ruleCell.find('.att-applies-chain  > .att-pair').length;
            var excludesCount = $ruleCell.find('.att-excludes-chain > .att-pair').length;
            $ruleCell.find('.att-tab[data-tab="applies"]').toggleClass('has-content',  appliesCount  > 0);
            $ruleCell.find('.att-tab[data-tab="excludes"]').toggleClass('has-content', excludesCount > 0);
        }

        // ── Price-type helpers ────────────────────────────────────────────────
        function rowClassFor(priceType) {
            return (priceType === 'fixed') ? 'att-row-fixed' : 'att-row-impact';
        }

        function unitFor(priceType) {
            return (priceType === 'impact_pct') ? '%' : '$';
        }

        // ── Build a full row ──────────────────────────────────────────────────
        function buildRow(data) {
            data = data || {};
            var priceType = data.price_type || 'impact';
            if (priceType !== 'fixed' && priceType !== 'impact' && priceType !== 'impact_pct') {
                priceType = 'impact';
            }
            var isFixed = (priceType === 'fixed');

            // Normalise: support legacy "pairs" format
            var condGroups = data.condition_groups ||
                (data.pairs ? [{ pairs: data.pairs }] : [{}]);

            // ── Rule cell ─────────────────────────────────────────────────────
            var $ruleCell = $('<td class="att-rule-cell">');

            // Tab bar
            var $tabs = $('<div class="att-tabs">').appendTo($ruleCell);
            $tabs.append(
                $('<button type="button" class="att-tab active" data-tab="conditions">Conditions</button>'),
                $('<button type="button" class="att-tab" data-tab="applies">Applies to</button>' )
                    .toggleClass('d-none', isFixed),
                $('<button type="button" class="att-tab" data-tab="excludes">Excludes</button>')
            );

            // Conditions panel
            var $condPanel = $('<div class="att-tab-panel active" data-panel="conditions">').appendTo($ruleCell);
            var $condWrap  = $('<div class="att-conditions-wrap">').appendTo($condPanel);
            condGroups.forEach(function (cg, i) {
                if (i > 0) { $condWrap.append($('<div class="att-or-divider">OR</div>')); }
                $condWrap.append(buildConditionGroup(cg));
            });
            $condWrap.append(
                $('<div class="att-add-or-wrap">').append(
                    $('<button type="button" class="att-add-or-group">')
                        .html('<i class="material-icons" style="font-size:12px;vertical-align:middle">add_circle_outline</i> Add OR block')
                )
            );

            // Applies-to panel
            var $appliesPanel = $('<div class="att-tab-panel" data-panel="applies">').appendTo($ruleCell);
            var $appliesChain = $('<div class="att-chain att-applies-chain">');
            (data.applies_to || []).forEach(function (p) { $appliesChain.append(buildPair(p)); });
            $appliesChain.append(
                $('<div class="att-chain-add">').append(
                    $('<button type="button" class="att-add-pair">')
                        .html('<i class="material-icons" style="font-size:13px;vertical-align:middle">add</i> filter')
                )
            );
            $appliesPanel.append($appliesChain);

            // Excludes panel
            var $excludesPanel = $('<div class="att-tab-panel" data-panel="excludes">').appendTo($ruleCell);
            var $excludesChain = $('<div class="att-chain att-excludes-chain">');
            (data.excludes || []).forEach(function (p) { $excludesChain.append(buildPair(p)); });
            $excludesChain.append(
                $('<div class="att-chain-add">').append(
                    $('<button type="button" class="att-add-pair">')
                        .html('<i class="material-icons" style="font-size:13px;vertical-align:middle">add</i> exclude')
                )
            );
            $excludesPanel.append($excludesChain);

            // ── Type cell ─────────────────────────────────────────────────────
            var $ptype = $('<select class="form-control form-control-sm attributtify-ptype">');
            $ptype.append(opt('fixed',      'Fixed $',  priceType === 'fixed'));
            $ptype.append(opt('impact',     'Impact $', priceType === 'impact'));
            $ptype.append(opt('impact_pct', 'Impact %', priceType === 'impact_pct'));

            var $typeCell = $('<td class="att-type-cell">').append($ptype);

            // ── Value cell ────────────────────────────────────────────────────
            var $pval = $('<input type="number" step="0.01" class="form-control form-control-sm attributtify-pvalue">')
                .val(data.price_value != null ? data.price_value : 0);
            var $unit = $('<span class="att-value-unit">').text(unitFor(priceType));
            var $valueCell = $('<td class="att-value-cell">').append(
                $('<div class="att-value-wrap">').append($pval, $unit)
            );

            // ── Extras cell (Qty / Ref / Wt) ──────────────────────────────────
            var $qty = $('<input type="number" min="0" step="1" class="form-control attributtify-qty">')
                .val(data.qty != null ? data.qty : '');
            var $ref = $('<input type="text" class="form-control attributtify-ref" placeholder="{SKU}-{attrs}">')
                .val(data.reference != null ? data.reference : '');
            var $wt = $('<input type="number" step="0.001" class="form-control attributtify-weight" placeholder="0">')
                .val(data.weight != null ? data.weight : '');

            var $extrasCell = $('<td class="att-extras-cell">').append(
                $('<div class="att-extras">').append(
                    $('<div class="att-extra-row">').append(
                        $('<span class="att-extra-label">Qty</span>'), $qty
                    ),
                    $('<div class="att-extra-row">').append(
                        $('<span class="att-extra-label">Ref</span>'), $ref
                    ),
                    $('<div class="att-extra-row">').append(
                        $('<span class="att-extra-label">Wt</span>'), $wt
                    )
                )
            );

            // ── Delete cell ───────────────────────────────────────────────────
            var $del = $('<button type="button" class="attributtify-remove" title="Remove row">' +
                '<i class="material-icons">close</i></button>');
            var $delCell = $('<td class="att-del-cell">').append($del);

            var $tr = $('<tr class="attributtify-row ' + rowClassFor(priceType) + '">').append(
                $ruleCell, $typeCell, $valueCell, $extrasCell, $delCell
            );

            syncTabBadges($ruleCell);
            return $tr;
        }

        // ── Serialise ─────────────────────────────────────────────────────────
        function collectPairs($chain) {
            var pairs = [];
            $chain.find('> .att-pair').each(function () {
                var gid  = parseInt($(this).find('.attributtify-group').val(), 10) || 0;
                var vals = ($(this).find('.attributtify-values').val() || []).map(function (v) { return parseInt(v, 10); });
                if (gid > 0) { pairs.push({ id_attribute_group: gid, id_attributes: vals }); }
            });
            return pairs;
        }

        function serialise() {
            var out = [];
            $rows.find('.attributtify-row').each(function () {
                var $tr   = $(this);
                var ptype = $tr.find('.attributtify-ptype').val();

                var conditionGroups = [];
                $tr.find('.att-condition-group').each(function () {
                    var cgPairs = collectPairs($(this).find('.att-main-chain'));
                    if (cgPairs.length > 0) {
                        conditionGroups.push({ pairs: cgPairs });
                    }
                });

                var appliesTo = collectPairs($tr.find('.att-applies-chain'));
                var excludes  = collectPairs($tr.find('.att-excludes-chain'));

                var qtyVal = $tr.find('.attributtify-qty').val();
                var refVal = $tr.find('.attributtify-ref').val();
                var wtVal  = $tr.find('.attributtify-weight').val();

                if (conditionGroups.length > 0 || ptype === 'impact' || ptype === 'impact_pct') {
                    out.push({
                        condition_groups: conditionGroups,
                        applies_to:       appliesTo,
                        excludes:         excludes,
                        price_type:       ptype,
                        price_value:      parseFloat($tr.find('.attributtify-pvalue').val()) || 0,
                        qty:              (qtyVal === '' || qtyVal == null) ? 0 : (parseInt(qtyVal, 10) || 0),
                        reference:        refVal || '',
                        weight:           (wtVal === '' || wtVal == null) ? 0 : (parseFloat(wtVal) || 0)
                    });
                }
            });
            return out;
        }

        // ── Events ────────────────────────────────────────────────────────────
        // Any input/select change inside rows = dirty
        $rows.on('input change', 'input, select, textarea', function () { markDirty(); });

        $('#attributtify-add-row').on('click', function () {
            $rows.append(buildRow({}));
            markDirty();
        });

        // Tab switching
        $rows.on('click', '.att-tab', function () {
            var $tab      = $(this);
            var $ruleCell = $tab.closest('.att-rule-cell');
            var target    = $tab.data('tab');
            $ruleCell.find('.att-tab').removeClass('active');
            $tab.addClass('active');
            $ruleCell.find('.att-tab-panel').removeClass('active');
            $ruleCell.find('.att-tab-panel[data-panel="' + target + '"]').addClass('active');
        });

        // Delete row
        $rows.on('click', '.attributtify-remove', function () {
            if (shouldConfirm() && !confirm('Remove this rule row?')) { return; }
            $(this).closest('tr').find('select').each(function () {
                if ($(this).data('select2')) { try { $(this).select2('destroy'); } catch (e) {} }
            });
            $(this).closest('tr').remove();
            markDirty();
        });

        // Add a condition pair (works in both .att-chain-footer and .att-chain-add)
        $rows.on('click', '.att-add-pair', function () {
            var $btn      = $(this);
            var $container = $btn.closest('.att-chain-footer, .att-chain-add');
            var $ruleCell  = $btn.closest('.att-rule-cell');
            $container.before(buildPair({}));
            syncTabBadges($ruleCell);
            markDirty();
        });

        // Remove a single condition pair
        $rows.on('click', '.att-remove-pair', function () {
            var $pair     = $(this).closest('.att-pair');
            var $chain    = $pair.closest('.att-chain');
            var $ruleCell = $pair.closest('.att-rule-cell');
            $pair.find('select').each(function () {
                if ($(this).data('select2')) { try { $(this).select2('destroy'); } catch (e) {} }
            });
            $pair.remove();
            // Keep at least one empty pair in main chain; applies/excludes may be empty
            if ($chain.hasClass('att-main-chain') && $chain.find('.att-pair').length === 0) {
                $chain.find('.att-chain-add').before(buildPair({}));
            }
            syncTabBadges($ruleCell);
            markDirty();
        });

        // Price type change → update class, unit, applies-tab visibility
        $rows.on('change', '.attributtify-ptype', function () {
            var $tr       = $(this).closest('tr');
            var $ruleCell = $tr.find('.att-rule-cell');
            var ptype     = $(this).val();
            var isFixed   = (ptype === 'fixed');

            $tr.removeClass('att-row-fixed att-row-impact').addClass(rowClassFor(ptype));
            $tr.find('.att-value-unit').text(unitFor(ptype));

            var $appliesTab = $ruleCell.find('.att-tab[data-tab="applies"]');
            $appliesTab.toggleClass('d-none', isFixed);

            // If applies tab becomes hidden while active, fall back to conditions tab
            if (isFixed && $appliesTab.hasClass('active')) {
                $ruleCell.find('.att-tab').removeClass('active');
                $ruleCell.find('.att-tab[data-tab="conditions"]').addClass('active');
                $ruleCell.find('.att-tab-panel').removeClass('active');
                $ruleCell.find('.att-tab-panel[data-panel="conditions"]').addClass('active');
            }

        });

        // Add OR condition group
        $rows.on('click', '.att-add-or-group', function () {
            var $wrap    = $(this).closest('.att-conditions-wrap');
            var $addWrap = $(this).closest('.att-add-or-wrap');
            // Only insert OR divider when at least one block already exists
            if ($wrap.find('.att-condition-group').length > 0) {
                $addWrap.before($('<div class="att-or-divider">OR</div>'));
            }
            $addWrap.before(buildConditionGroup({}));
            updateRemoveCgVisibility($wrap);
            markDirty();
        });

        // Remove OR condition group (with optional confirmation)
        $rows.on('click', '.att-remove-cg', function () {
            if (shouldConfirm() && !confirm('Remove this OR block?')) { return; }
            var $cg   = $(this).closest('.att-condition-group');
            var $wrap = $cg.closest('.att-conditions-wrap');
            var $prev = $cg.prev('.att-or-divider');
            var $next = $cg.next('.att-or-divider');
            $cg.find('select').each(function () {
                if ($(this).data('select2')) { try { $(this).select2('destroy'); } catch (e) {} }
            });
            if ($prev.length) { $prev.remove(); } else if ($next.length) { $next.remove(); }
            $cg.remove();
            updateRemoveCgVisibility($wrap);
            markDirty();
        });

        function updateRemoveCgVisibility($wrap) {
            var $groups = $wrap.find('.att-condition-group');
            // Show × only when there are 2+ OR blocks
            $groups.find('.att-remove-cg').toggle($groups.length > 1);
        }

        // Group selection changed → reload values select
        $rows.on('change', '.attributtify-group', function () {
            var $pair = $(this).closest('.att-pair');
            var $vals = $pair.find('.attributtify-values');
            var idGrp = parseInt($(this).val(), 10);
            if ($vals.data('select2')) { try { $vals.select2('destroy'); } catch (e) {} }
            $vals.empty();
            if (!idGrp) { applyS2Multi($vals, 'Select values\u2026'); return; }
            loadAttributes(idGrp).done(function (attrs) { fillVals($vals, attrs, []); });
        });

        // ── Save ──────────────────────────────────────────────────────────────
        $('#attributtify-save').on('click', function () {
            setStatus('Saving\u2026', 'info');
            ajax('saveConfig', { id_product: productId, rows: JSON.stringify(serialise()) })
                .done(function (r) {
                    setStatus(
                        r && r.success ? (r.message || 'Saved.') : (r.message || 'Save failed.'),
                        r && r.success ? 'success' : 'danger'
                    );
                    if (r && r.success) { markClean(); }
                })
                .fail(function () { setStatus('AJAX error.', 'danger'); });
        });

        // ── Preview modal helpers ─────────────────────────────────────────────
        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function fmtMoney(v) {
            var n = parseFloat(v);
            if (!isFinite(n)) { n = 0; }
            return '$' + n.toFixed(2);
        }

        function fmtImpact(v) {
            var n = parseFloat(v);
            if (!isFinite(n)) { n = 0; }
            var sign = n > 0 ? '+' : (n < 0 ? '\u2212' : '');
            return sign + Math.abs(n).toFixed(2);
        }

        function renderPreview(items) {
            var $tbody = $('#att-preview-rows').empty();
            (items || []).forEach(function (it) {
                var wt = parseFloat(it.weight);
                var $tr = $('<tr>').append(
                    $('<td>').text(it.n),
                    $('<td>').text(it.attrs || ''),
                    $('<td class="att-preview-price">').text(fmtMoney(it.price)),
                    $('<td class="att-preview-impact">').text(fmtImpact(it.impact)),
                    $('<td>').text(it.qty != null ? it.qty : 0),
                    $('<td class="att-preview-ref">').text(it.reference || ''),
                    $('<td>').text(isFinite(wt) && wt !== 0 ? wt.toFixed(4) : '')
                );
                $tbody.append($tr);
            });
            $('#att-preview-count').text((items || []).length);
        }

        function showPreviewModal() { $('#att-preview-modal').css('display', 'flex'); }
        function hidePreviewModal() { $('#att-preview-modal').hide(); }

        // ── Generate button (saves then previews) ─────────────────────────────
        $('#attributtify-generate').on('click', function () {
            setStatus('Computing preview\u2026', 'info');
            ajax('saveConfig', { id_product: productId, rows: JSON.stringify(serialise()) })
                .done(function (save) {
                    if (!save || !save.success) {
                        setStatus(save && save.message ? save.message : 'Save step failed.', 'danger');
                        return;
                    }
                    ajax('preview', { id_product: productId, auto_refs: $autoRefsChk.is(':checked') ? '1' : '0' })
                        .done(function (r) {
                            if (r && r.success) {
                                renderPreview(r.preview || []);
                                showPreviewModal();
                                setStatus('Review ' + (r.count || 0) + ' combinations then confirm.', 'info');
                            } else {
                                setStatus(r && r.message ? r.message : 'Preview failed.', 'danger');
                            }
                        })
                        .fail(function () { setStatus('AJAX error.', 'danger'); });
                })
                .fail(function () { setStatus('AJAX error.', 'danger'); });
        });

        // ── Preview modal events ──────────────────────────────────────────────
        $(document).on('click', '#att-preview-close, #att-preview-cancel', hidePreviewModal);

        $(document).on('click', '#att-preview-confirm', function () {
            if (!confirm('This will delete all existing combinations for this product and regenerate them. Continue?')) {
                return;
            }
            setStatus('Generating\u2026', 'info');
            ajax('generate', { id_product: productId, auto_refs: $autoRefsChk.is(':checked') ? '1' : '0' })
                .done(function (r) {
                    hidePreviewModal();
                    if (r && r.success) {
                        setStatus(r.message || 'Done.', 'success');
                        if (r.combo_ids && r.combo_ids.length) {
                            refreshCombinationsList(r.combo_ids);
                        }
                    } else {
                        setStatus(r && r.message ? r.message : 'Generation failed.', 'danger');
                    }
                })
                .fail(function () { setStatus('AJAX error.', 'danger'); });
        });

        // ── Load saved config (shared by bootstrap and Load button) ───────────
        function loadSavedConfig() {
            setStatus('Loading\u2026', 'info');
            $rows.find('select').each(function () {
                if ($(this).data('select2')) { try { $(this).select2('destroy'); } catch (e) {} }
            });
            $rows.empty();
            loadGroups().done(function () {
                ajax('loadConfig', { id_product: productId }).done(function (r) {
                    var saved = (r && r.success && Array.isArray(r.rows)) ? r.rows : [];
                    if (!saved.length) {
                        $rows.append(buildRow({}));
                        setStatus('No saved config.', 'info');
                        return;
                    }
                    var groupIds = {};
                    saved.forEach(function (row) {
                        (row.condition_groups || (row.pairs ? [{ pairs: row.pairs }] : [])).forEach(function (cg) {
                            (cg.pairs || []).forEach(function (p) {
                                if (p.id_attribute_group) { groupIds[p.id_attribute_group] = true; }
                            });
                        });
                        ['applies_to', 'excludes'].forEach(function (field) {
                            (row[field] || []).forEach(function (p) {
                                if (p.id_attribute_group) { groupIds[p.id_attribute_group] = true; }
                            });
                        });
                    });
                    var defs = Object.keys(groupIds).map(function (gid) {
                        return loadAttributes(parseInt(gid, 10));
                    });
                    $.when.apply($, defs).always(function () {
                        saved.forEach(function (row) { $rows.append(buildRow(row)); });
                        // Restore remove-cg button visibility for rows with multiple OR blocks
                        $rows.find('.att-conditions-wrap').each(function () {
                            updateRemoveCgVisibility($(this));
                        });
                        setStatus('Loaded ' + saved.length + ' rule(s).', 'success');
                        markClean();
                    });
                }).fail(function () { setStatus('Load failed.', 'danger'); });
            });
        }

        // Load button
        $('#attributtify-load').on('click', function () {
            if (isDirty && !confirm('You have unsaved changes. Discard and reload?')) { return; }
            loadSavedConfig();
        });

        // ── Bootstrap ─────────────────────────────────────────────────────────
        loadSavedConfig();
    }

    // ── Refresh PS8 combinations list ────────────────────────────────────────
    function refreshCombinationsList(comboIds) {
        if (!comboIds || !comboIds.length) { return; }

        var m = window.location.pathname.match(/^(.*\/sell\/catalog\/products)/);
        if (!m) {
            console.warn('[Attributtify] refreshCombinationsList: cannot derive base URL from', window.location.pathname);
            return;
        }
        var base = m[1];

        var $list = (
            $('.js-combinations-list').length          ? $('.js-combinations-list') :
            $('[data-combinations-url]').length        ? $('[data-combinations-url]') :
            $('#combinations-list').length             ? $('#combinations-list') :
            null
        );

        console.log('[Attributtify] refreshCombinationsList — ids:', comboIds, '| base:', base, '| $list found:', !!($list && $list.length));

        if ($list && $list.length) {
            var removed = $list.find('tr.combination, tr.loaded').length;
            $list.find('tr.combination, tr.loaded').remove();
            $list.attr('data-ids-product-attribute', comboIds.join(','));
            console.log('[Attributtify] removed', removed, 'old rows; updated data-ids-product-attribute');
        }

        var step = 50;
        (function fetchBatch(remaining) {
            if (!remaining.length) { return; }
            var batch = remaining.slice(0, step);
            var rest  = remaining.slice(step);
            var url   = base + '/combinations/form/' + batch.join('-');

            console.log('[Attributtify] fetching:', url);

            $.get(url)
                .done(function (html) {
                    console.log('[Attributtify] got HTML length:', (html || '').length);

                    var $loader = $('#loading-attribute');
                    if ($loader.length && $loader.parent().length) {
                        $loader.before(html);
                    } else if ($list && $list.length) {
                        $list.append(html);
                    } else {
                        var $tbody = $('table[data-ids-product-attribute] tbody').first();
                        if ($tbody.length) { $tbody.append(html); }
                        else { console.warn('[Attributtify] no injection target found'); }
                    }

                    batch.forEach(function (id) {
                        $('#attribute_' + id).css('display', 'table-row');
                    });

                    if (!rest.length && typeof window.refreshTotalCombinations === 'function') {
                        window.refreshTotalCombinations(comboIds.length, 0);
                    }

                    fetchBatch(rest);
                })
                .fail(function (xhr) {
                    console.error('[Attributtify] fetch failed:', xhr.status, url);
                });
        }(comboIds));
    }

})(typeof jQuery !== 'undefined' ? jQuery : null);
