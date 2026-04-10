{*
 * Attributtify — Custom Attribute Group Types settings page
 * Rendered via Module::getContent() — action_url already contains the CSRF token.
 *}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-cogs"></i>
        Attributtify &mdash; Custom Attribute Group Types
    </div>
    <div class="panel-body">
        <p class="text-muted" style="margin-bottom:16px">
            Custom types are appended to the <strong>Group type</strong> dropdown on the
            <em>Attribute Groups</em> edit form. Use them to signal special rendering
            behaviour to your theme (e.g. <code>checkbox</code>, <code>swatch</code>).
        </p>

        <table class="table">
            <thead>
                <tr>
                    <th style="width:220px">{l s='Type ID' mod='ps_attributtify'}</th>
                    <th>{l s='Display Name' mod='ps_attributtify'}</th>
                    <th style="width:60px"></th>
                </tr>
            </thead>
            <tbody>
                {if $types|@count > 0}
                    {foreach $types as $type}
                    <tr>
                        <td><code>{$type.id|escape:'html':'UTF-8'}</code></td>
                        <td>{$type.name|escape:'html':'UTF-8'}</td>
                        <td>
                            <form method="post" action="{$action_url|escape:'html':'UTF-8'}"
                                  onsubmit="return confirm('{l s='Remove this type?' mod='ps_attributtify' js=1}')">
                                <input type="hidden" name="type_id" value="{$type.id|escape:'html':'UTF-8'}">
                                <button type="submit" name="deleteCustomType" class="btn btn-danger btn-xs">
                                    <i class="icon-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    {/foreach}
                {else}
                    <tr>
                        <td colspan="3" class="text-center text-muted" style="padding:16px">
                            {l s='No custom types defined yet.' mod='ps_attributtify'}
                        </td>
                    </tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <i class="icon-plus"></i>
        {l s='Add custom type' mod='ps_attributtify'}
    </div>
    <div class="panel-body">
        <form method="post" action="{$action_url|escape:'html':'UTF-8'}" class="form-horizontal" autocomplete="off">
            <div class="form-group">
                <label class="control-label col-lg-2" for="att-type-id">
                    {l s='Type ID' mod='ps_attributtify'}
                </label>
                <div class="col-lg-4">
                    <input type="text" id="att-type-id" name="type_id"
                           class="form-control" required
                           placeholder="e.g. checkbox"
                           pattern="[a-z][a-z0-9_]*"
                           maxlength="50">
                    <p class="help-block">
                        {l s='Lowercase letters, digits, underscores. Stored as group_type value in the DB.' mod='ps_attributtify'}
                    </p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-2" for="att-type-name">
                    {l s='Display Name' mod='ps_attributtify'}
                </label>
                <div class="col-lg-4">
                    <input type="text" id="att-type-name" name="type_name"
                           class="form-control" required
                           placeholder="e.g. Checkbox (No/Yes only)"
                           maxlength="100">
                    <p class="help-block">
                        {l s='Label shown in the Group type dropdown.' mod='ps_attributtify'}
                    </p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-lg-offset-2 col-lg-4">
                    <button type="submit" name="addCustomType" class="btn btn-primary">
                        <i class="icon-plus"></i>
                        {l s='Add type' mod='ps_attributtify'}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
