{*
* Vocify AI - Webhook Logs Display
*
* @author Vocify AI
* @copyright 2025 Vocify AI
* @license MIT License
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i>
        {l s='Recent Webhook Activity' mod='vocifyai'}
    </div>
    <div class="panel-body">
        {if isset($webhook_logs) && count($webhook_logs) > 0}
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{l s='Order' mod='vocifyai'}</th>
                            <th>{l s='Status' mod='vocifyai'}</th>
                            <th>{l s='HTTP Code' mod='vocifyai'}</th>
                            <th>{l s='Response' mod='vocifyai'}</th>
                            <th>{l s='Date' mod='vocifyai'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$webhook_logs item=log}
                            <tr>
                                <td>
                                    <strong>#{$log.order_reference|escape:'html':'UTF-8'}</strong>
                                    <br>
                                    <small class="text-muted">ID: {$log.id_order|escape:'html':'UTF-8'}</small>
                                </td>
                                <td>
                                    {if $log.status == 'success'}
                                        <span class="badge badge-success">
                                            <i class="icon-check"></i> {l s='Success' mod='vocifyai'}
                                        </span>
                                    {elseif $log.status == 'failed'}
                                        <span class="badge badge-danger">
                                            <i class="icon-remove"></i> {l s='Failed' mod='vocifyai'}
                                        </span>
                                    {else}
                                        <span class="badge badge-warning">
                                            <i class="icon-warning"></i> {$log.status|escape:'html':'UTF-8'}
                                        </span>
                                    {/if}
                                </td>
                                <td>
                                    {if $log.http_code}
                                        <code>{$log.http_code|escape:'html':'UTF-8'}</code>
                                    {else}
                                        <span class="text-muted">-</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $log.response}
                                        <button type="button" class="btn btn-xs btn-default"
                                                onclick="alert('{$log.response|escape:'javascript':'UTF-8'}')">
                                            <i class="icon-search"></i> {l s='View' mod='vocifyai'}
                                        </button>
                                    {elseif $log.error_message}
                                        <span class="text-danger">{$log.error_message|truncate:50|escape:'html':'UTF-8'}</span>
                                    {else}
                                        <span class="text-muted">-</span>
                                    {/if}
                                </td>
                                <td>
                                    <small>{$log.created_at|escape:'html':'UTF-8'}</small>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
            <div class="alert alert-info">
                <i class="icon-info"></i>
                {l s='Showing the 20 most recent webhook attempts. For complete logs, check your PrestaShop logs.' mod='vocifyai'}
            </div>
        {else}
            <div class="alert alert-warning">
                <i class="icon-warning"></i>
                {l s='No webhook activity found. Make sure the integration is enabled and you have created orders.' mod='vocifyai'}
            </div>
        {/if}
    </div>
</div>

<style>
    .badge {
        padding: 5px 10px;
        font-size: 12px;
    }
    .badge-success {
        background-color: #72c02c;
    }
    .badge-danger {
        background-color: #e08f95;
    }
    .badge-warning {
        background-color: #f39c12;
    }
</style>
