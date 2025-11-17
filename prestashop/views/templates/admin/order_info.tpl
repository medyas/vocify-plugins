{*
* Vocify AI - Order Information Display
*
* @author Vocify AI
* @copyright 2025 Vocify AI
* @license MIT License
*}

<div class="panel card">
    <div class="panel-heading card-header">
        <i class="icon-phone"></i>
        {l s='Vocify AI - Order Confirmation Calls' mod='vocifyai'}
    </div>
    <div class="panel-body card-body">
        {if $vocify_enabled}
            {if isset($vocify_logs) && count($vocify_logs) > 0}
                <div class="alert alert-info">
                    <i class="icon-check-circle"></i>
                    {l s='This order has been sent to Vocify AI for confirmation calls.' mod='vocifyai'}
                </div>

                <h4>{l s='Webhook History' mod='vocifyai'}</h4>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>{l s='Status' mod='vocifyai'}</th>
                                <th>{l s='HTTP Code' mod='vocifyai'}</th>
                                <th>{l s='Date' mod='vocifyai'}</th>
                                <th>{l s='Details' mod='vocifyai'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$vocify_logs item=log}
                                <tr>
                                    <td>
                                        {if $log.status == 'success'}
                                            <span class="label label-success">
                                                <i class="icon-check"></i> {l s='Success' mod='vocifyai'}
                                            </span>
                                        {elseif $log.status == 'failed'}
                                            <span class="label label-danger">
                                                <i class="icon-remove"></i> {l s='Failed' mod='vocifyai'}
                                            </span>
                                        {else}
                                            <span class="label label-warning">
                                                {$log.status|escape:'html':'UTF-8'}
                                            </span>
                                        {/if}
                                    </td>
                                    <td>
                                        {if $log.http_code}
                                            <code>{$log.http_code|escape:'html':'UTF-8'}</code>
                                        {else}
                                            -
                                        {/if}
                                    </td>
                                    <td>
                                        <small>{$log.created_at|escape:'html':'UTF-8'}</small>
                                    </td>
                                    <td>
                                        {if $log.response}
                                            <button type="button" class="btn btn-xs btn-default"
                                                    onclick="showVocifyDetails('{$log.response|escape:'javascript':'UTF-8'}')">
                                                <i class="icon-search"></i> {l s='View Details' mod='vocifyai'}
                                            </button>
                                        {elseif $log.error_message}
                                            <span class="text-danger">{$log.error_message|truncate:100|escape:'html':'UTF-8'}</span>
                                        {else}
                                            -
                                        {/if}
                                    </td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            {else}
                <div class="alert alert-warning">
                    <i class="icon-exclamation-triangle"></i>
                    {l s='No webhook activity found for this order.' mod='vocifyai'}
                    <br>
                    <small>{l s='This could mean the order was created before the integration was enabled, or there was an issue sending the webhook.' mod='vocifyai'}</small>
                </div>
            {/if}
        {else}
            <div class="alert alert-danger">
                <i class="icon-times-circle"></i>
                {l s='Vocify AI integration is currently disabled.' mod='vocifyai'}
                <br>
                <a href="{$link->getAdminLink('AdminModules')|escape:'html':'UTF-8'}&configure=vocifyai" class="btn btn-sm btn-primary" style="margin-top: 10px;">
                    <i class="icon-cog"></i> {l s='Configure Vocify AI' mod='vocifyai'}
                </a>
            </div>
        {/if}
    </div>
</div>

<script type="text/javascript">
    function showVocifyDetails(response) {
        try {
            var data = JSON.parse(response);
            var message = 'Vocify AI Response:\n\n';

            if (data.jobId) {
                message += 'Job ID: ' + data.jobId + '\n';
            }
            if (data.orderId) {
                message += 'Vocify Order ID: ' + data.orderId + '\n';
            }
            if (data.status) {
                message += 'Status: ' + data.status + '\n';
            }
            if (data.scheduledFor) {
                message += 'Scheduled For: ' + data.scheduledFor + '\n';
            }

            alert(message);
        } catch (e) {
            alert(response);
        }
    }
</script>

<style>
    .label {
        display: inline-block;
        padding: 4px 8px;
        font-size: 11px;
        font-weight: bold;
        line-height: 1;
        color: #fff;
        text-align: center;
        white-space: nowrap;
        vertical-align: baseline;
        border-radius: 3px;
    }
    .label-success {
        background-color: #72c02c;
    }
    .label-danger {
        background-color: #e08f95;
    }
    .label-warning {
        background-color: #f39c12;
    }
</style>
