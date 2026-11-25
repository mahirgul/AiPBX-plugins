<?php

declare(strict_types=1);

/**
 * Custom admin UI on /plugins/voip-billing rendered below the plugin settings card.
 * Available variables: $plugin, $settings, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/VoipBilling.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$providerTypes = VoipBilling::providers();
$providers = VoipBilling::listProviders();
$customers = VoipBilling::listCustomers();
$rates = VoipBilling::listRates();

$editProviderId = isset($_GET['edit_provider']) ? (int) $_GET['edit_provider'] : 0;
$editProvider = $editProviderId > 0 ? VoipBilling::getProvider($editProviderId, false) : null;

$editCustomerId = isset($_GET['edit_customer']) ? (int) $_GET['edit_customer'] : 0;
$editCustomer = $editCustomerId > 0 ? VoipBilling::getCustomer($editCustomerId) : null;

$editRateId = isset($_GET['edit_rate']) ? (int) $_GET['edit_rate'] : 0;
$editRate = $editRateId > 0 ? VoipBilling::getRate($editRateId) : null;

$selectedProviderId = isset($_GET['provider_id']) ? (int) $_GET['provider_id'] : (isset($_POST['provider_id']) ? (int) $_POST['provider_id'] : 0);
if ($selectedProviderId <= 0 && $providers !== []) {
    foreach ($providers as $p) {
        if ((int) $p['is_active'] === 1) {
            $selectedProviderId = (int) $p['id'];
            break;
        }
    }
    if ($selectedProviderId <= 0) {
        $selectedProviderId = (int) $providers[0]['id'];
    }
}
$selectedProvider = $selectedProviderId > 0 ? VoipBilling::getProvider($selectedProviderId, false) : null;

$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-d');
$periodStart = (string) ($_GET['period_start'] ?? $_POST['period_start'] ?? $defaultStart);
$periodEnd = (string) ($_GET['period_end'] ?? $_POST['period_end'] ?? $defaultEnd);

$preview = null;
$previewError = '';
if ($selectedProvider !== null) {
    try {
        $preview = VoipBilling::previewUsage($selectedProviderId, $periodStart, $periodEnd, $settings);
    } catch (\Throwable $e) {
        $previewError = $e->getMessage();
    }
}

$invoices = VoipBilling::listInvoices($selectedProviderId > 0 ? $selectedProviderId : null, 50);
$currency = strtoupper(trim((string) ($settings['default_currency'] ?? 'USD'))) ?: 'USD';
?>

<div class="card u-mb-20" id="billing-providers">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-server"></i> Billing Providers</div>
    </div>
    <div class="u-p-16">
        <p class="u-hint u-mb-14">Configure one or more ClientFence / FOSSBilling accounts. API keys are encrypted at rest with SecretBox and never shown in plain text.</p>

        <form method="post" action="/plugins/voip-billing" class="u-mb-20" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="billing_action" value="save_provider">
            <input type="hidden" name="provider_id" value="<?php echo (int) ($editProvider['id'] ?? 0); ?>">

            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label">Account Name</label>
                    <input type="text" name="name" class="form-control" maxlength="80" required placeholder="Main ClientFence" value="<?php echo $h($editProvider['name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Provider Type</label>
                    <select name="provider" class="form-control">
                        <?php foreach ($providerTypes as $typeId => $class): ?>
                            <option value="<?php echo $h($typeId); ?>" <?php echo (($editProvider['provider'] ?? 'clientfence') === $typeId) ? 'selected' : ''; ?>>
                                <?php echo $h($class::label()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">HTTPS Base URL</label>
                    <input type="url" name="base_url" class="form-control" maxlength="255" required placeholder="https://billing.example.com" value="<?php echo $h($editProvider['base_url'] ?? ''); ?>">
                    <small class="u-hint">Must be a public HTTPS URL without embedded credentials, query string, or fragment.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">API User</label>
                    <input type="text" name="api_user" class="form-control" maxlength="120" placeholder="admin" value="<?php echo $h($editProvider['api_user'] ?? 'admin'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">
                        API Key
                        <?php if ($editProvider !== null && !empty($editProvider['api_key_masked'])): ?>
                            <span class="badge badge-info u-fs-11"><?php echo $h($editProvider['api_key_masked']); ?></span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="api_key" class="form-control" autocomplete="new-password"
                           placeholder="<?php echo $editProvider !== null ? 'Leave blank to keep existing API key' : 'Enter ClientFence API key'; ?>"
                           <?php echo $editProvider === null ? 'required' : ''; ?>>
                </div>
                <div class="form-group u-d-flex u-ai-center">
                    <label class="u-check-label">
                        <input type="checkbox" name="is_active" value="1" class="u-check" <?php echo ($editProvider === null || !empty($editProvider['is_active'])) ? 'checked' : ''; ?>>
                        <span>Active</span>
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <?php if ($editProvider !== null): ?>
                    <a href="/plugins/voip-billing" class="btn btn-secondary">Cancel Edit</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editProvider !== null ? 'Update Provider' : 'Add Provider'; ?>
                </button>
            </div>
        </form>

        <?php echo uiTableOpen(['Name', 'Type', 'Base URL', 'Status', 'Last Test', 'Actions'], ['search' => false]); ?>
        <?php if ($providers === []): ?>
            <?php echo uiTableEmptyRow(6, 'No billing providers configured yet.'); ?>
        <?php else: ?>
            <?php foreach ($providers as $p):
                $pid = (int) $p['id'];
                $typeLabel = isset($providerTypes[$p['provider']]) ? $providerTypes[$p['provider']]::label() : (string) $p['provider'];
                $testStatus = (string) ($p['last_test_status'] ?? '');
                $testBadge = $testStatus === 'ok' ? 'badge-success' : ($testStatus === 'error' ? 'badge-danger' : 'badge-secondary');
            ?>
                <tr>
                    <td><strong><?php echo $h($p['name']); ?></strong></td>
                    <td><?php echo $h($typeLabel); ?></td>
                    <td><code><?php echo $h($p['base_url']); ?></code></td>
                    <td>
                        <?php if ((int) $p['is_active'] === 1): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($testStatus !== ''): ?>
                            <span class="badge <?php echo $testBadge; ?>"><?php echo $h(strtoupper($testStatus)); ?></span>
                            <div class="u-hint u-fs-11">
                                <?php echo $h($p['last_test_message'] ?: ''); ?>
                                <?php if (!empty($p['last_test_at'])): ?> (<?php echo $h($p['last_test_at']); ?>)<?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="badge badge-secondary">Not tested</span>
                        <?php endif; ?>
                    </td>
                    <td class="u-nowrap">
                        <a href="/plugins/voip-billing?edit_provider=<?php echo $pid; ?>#billing-providers" class="btn btn-secondary btn-sm" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="post" action="/plugins/voip-billing" class="u-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="billing_action" value="test_provider">
                            <input type="hidden" name="provider_id" value="<?php echo $pid; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" title="Test Connection">
                                <i class="fas fa-plug"></i> Test
                            </button>
                        </form>
                        <form method="post" action="/plugins/voip-billing" class="u-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="billing_action" value="toggle_provider">
                            <input type="hidden" name="provider_id" value="<?php echo $pid; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo (int) $p['is_active'] === 1 ? 'Disable' : 'Enable'; ?>">
                                <?php echo (int) $p['is_active'] === 1 ? 'Disable' : 'Enable'; ?>
                            </button>
                        </form>
                        <form method="post" action="/plugins/voip-billing" class="u-inline" onsubmit="return confirm('Delete this provider and all its customers, rates, and invoice history?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="billing_action" value="delete_provider">
                            <input type="hidden" name="provider_id" value="<?php echo $pid; ?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash-alt"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>

<div class="card u-mb-20" id="billing-customers">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-users"></i> Customer &amp; Extension Mappings</div>
    </div>
    <div class="u-p-16">
        <p class="u-hint u-mb-14">Map PBX extensions to external ClientFence client IDs. Only answered outbound calls from active mapped extensions are billed.</p>

        <?php echo uiTableOpen(['Provider', 'Customer Name', 'Client ID', 'Extensions', 'Rate Override', 'Taxed', 'Status', 'Actions'], ['search' => false]); ?>
        <?php if ($customers === []): ?>
            <?php echo uiTableEmptyRow(8, 'No customer mappings yet.'); ?>
        <?php else: ?>
            <?php foreach ($customers as $c):
                $cid = (int) $c['id'];
            ?>
                <tr>
                    <td><?php echo $h($c['provider_name'] ?: ('#' . $c['provider_id'])); ?></td>
                    <td><strong><?php echo $h($c['customer_name']); ?></strong></td>
                    <td><code><?php echo $h($c['external_client_id']); ?></code></td>
                    <td><code><?php echo $h($c['extensions_csv']); ?></code></td>
                    <td><?php echo $c['rate_per_minute'] !== null ? $h($c['rate_per_minute'] . ' ' . $currency) : '<span class="u-muted">Default / Prefix</span>'; ?></td>
                    <td><?php echo $c['taxed'] === null ? '<span class="u-muted">Default</span>' : ((int) $c['taxed'] === 1 ? 'Yes' : 'No'); ?></td>
                    <td>
                        <?php if ((int) $c['is_active'] === 1): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td class="u-nowrap">
                        <a href="/plugins/voip-billing?edit_customer=<?php echo $cid; ?>#billing-customers" class="btn btn-secondary btn-sm" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="post" action="/plugins/voip-billing" class="u-inline" onsubmit="return confirm('Delete this customer mapping?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="billing_action" value="delete_customer">
                            <input type="hidden" name="customer_id" value="<?php echo $cid; ?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash-alt"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php echo uiTableClose(); ?>

        <?php if ($providers !== []): ?>
            <form method="post" action="/plugins/voip-billing" class="u-mt-16">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <input type="hidden" name="billing_action" value="save_customer">
                <input type="hidden" name="customer_id" value="<?php echo (int) ($editCustomer['id'] ?? 0); ?>">

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label">Provider</label>
                        <select name="provider_id" class="form-control" required>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>" <?php echo ((int) ($editCustomer['provider_id'] ?? $selectedProviderId) === (int) $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo $h($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">External Client ID</label>
                        <input type="text" name="external_client_id" class="form-control" maxlength="64" required placeholder="12" value="<?php echo $h($editCustomer['external_client_id'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-control" maxlength="120" required placeholder="Acme Corp" value="<?php echo $h($editCustomer['customer_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Extensions (comma-separated)</label>
                        <input type="text" name="extensions_csv" class="form-control" maxlength="500" required placeholder="3001,3002" value="<?php echo $h($editCustomer['extensions_csv'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rate Override / min (optional)</label>
                        <input type="text" name="rate_per_minute" class="form-control" placeholder="Blank = use prefix / default rate" value="<?php echo $h($editCustomer['rate_per_minute'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Taxed</label>
                        <select name="taxed" class="form-control">
                            <option value="" <?php echo ($editCustomer === null || $editCustomer['taxed'] === null) ? 'selected' : ''; ?>>Default (global setting)</option>
                            <option value="1" <?php echo ($editCustomer !== null && $editCustomer['taxed'] !== null && (int) $editCustomer['taxed'] === 1) ? 'selected' : ''; ?>>Yes (taxed)</option>
                            <option value="0" <?php echo ($editCustomer !== null && $editCustomer['taxed'] !== null && (int) $editCustomer['taxed'] === 0) ? 'selected' : ''; ?>>No (untaxed)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="u-check-label">
                        <input type="checkbox" name="is_active" value="1" class="u-check" <?php echo ($editCustomer === null || !empty($editCustomer['is_active'])) ? 'checked' : ''; ?>>
                        <span>Active</span>
                    </label>
                </div>

                <div class="form-actions">
                    <?php if ($editCustomer !== null): ?>
                        <a href="/plugins/voip-billing#billing-customers" class="btn btn-secondary">Cancel Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> <?php echo $editCustomer !== null ? 'Update Customer' : 'Add Customer'; ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card u-mb-20" id="billing-rates">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-tags"></i> Destination Prefix Rates</div>
    </div>
    <div class="u-p-16">
        <p class="u-hint u-mb-14">Longest-prefix match applies after stripping leading <code>+</code> or <code>00</code>. Unmatched destinations fall back to the customer's rate override or the global default rate.</p>

        <?php echo uiTableOpen(['Provider', 'Prefix', 'Label', 'Rate / min', 'Actions'], ['search' => false]); ?>
        <?php if ($rates === []): ?>
            <?php echo uiTableEmptyRow(5, 'No prefix rates configured yet.'); ?>
        <?php else: ?>
            <?php foreach ($rates as $r):
                $rid = (int) $r['id'];
            ?>
                <tr>
                    <td><?php echo $h($r['provider_name'] ?: ('#' . $r['provider_id'])); ?></td>
                    <td><code>+<?php echo $h($r['prefix']); ?></code></td>
                    <td><?php echo $h($r['label'] ?: '—'); ?></td>
                    <td><?php echo $h($r['rate_per_minute']); ?> <?php echo $h($currency); ?></td>
                    <td class="u-nowrap">
                        <a href="/plugins/voip-billing?edit_rate=<?php echo $rid; ?>#billing-rates" class="btn btn-secondary btn-sm" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="post" action="/plugins/voip-billing" class="u-inline" onsubmit="return confirm('Delete this prefix rate?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="billing_action" value="delete_rate">
                            <input type="hidden" name="rate_id" value="<?php echo $rid; ?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash-alt"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php echo uiTableClose(); ?>

        <?php if ($providers !== []): ?>
            <form method="post" action="/plugins/voip-billing" class="u-mt-16">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <input type="hidden" name="billing_action" value="save_rate">
                <input type="hidden" name="rate_id" value="<?php echo (int) ($editRate['id'] ?? 0); ?>">

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label">Provider</label>
                        <select name="provider_id" class="form-control" required>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>" <?php echo ((int) ($editRate['provider_id'] ?? $selectedProviderId) === (int) $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo $h($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prefix</label>
                        <input type="text" name="prefix" class="form-control" maxlength="32" required placeholder="90532" value="<?php echo $h($editRate['prefix'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Label</label>
                        <input type="text" name="label" class="form-control" maxlength="120" placeholder="Turkey Mobile" value="<?php echo $h($editRate['label'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rate / min (<?php echo $h($currency); ?>)</label>
                        <input type="text" name="rate_per_minute" class="form-control" required placeholder="0.0800" value="<?php echo $h($editRate['rate_per_minute'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-actions">
                    <?php if ($editRate !== null): ?>
                        <a href="/plugins/voip-billing#billing-rates" class="btn btn-secondary">Cancel Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> <?php echo $editRate !== null ? 'Update Prefix Rate' : 'Add / Update Rate'; ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card" id="billing-usage">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-file-invoice-dollar"></i> Usage Preview &amp; Draft Invoices</div>
    </div>
    <div class="u-p-16">
        <?php if ($providers === []): ?>
            <p class="u-hint">Add a billing provider and customer mappings above to preview unbilled usage and create draft invoices.</p>
        <?php else: ?>
            <form method="get" action="/plugins/voip-billing#billing-usage" class="u-mb-16">
                <div class="u-grid-3">
                    <div class="form-group">
                        <label class="form-label">Provider</label>
                        <select name="provider_id" class="form-control">
                            <?php foreach ($providers as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>" <?php echo $selectedProviderId === (int) $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo $h($p['name']); ?><?php echo (int) $p['is_active'] !== 1 ? ' (disabled)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Period Start</label>
                        <input type="date" name="period_start" class="form-control" value="<?php echo $h($periodStart); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Period End</label>
                        <input type="date" name="period_end" class="form-control" value="<?php echo $h($periodEnd); ?>" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Preview Usage</button>
                </div>
            </form>

            <?php if ($previewError !== ''): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <span><?php echo $h($previewError); ?></span></div>
            <?php else: ?>
                <?php if ($selectedProvider !== null && (int) $selectedProvider['is_active'] !== 1): ?>
                    <div class="alert alert-warning"><i class="fas fa-pause-circle"></i> <span>This provider account is disabled. Enable it before creating draft invoices.</span></div>
                <?php endif; ?>

                <?php
                $previewRows = $preview['customers'] ?? [];
                $previewCalls = (int) ($preview['total_calls'] ?? 0);
                echo uiTableOpen(['Customer', 'Client ID', 'Extensions', 'Unbilled Calls', 'Billed Minutes', 'Subtotal', 'Items'], ['search' => false]);
                ?>
                <?php if ($previewRows === []): ?>
                    <?php echo uiTableEmptyRow(7, 'No unbilled answered outbound calls found for this period.'); ?>
                <?php else: ?>
                    <?php foreach ($previewRows as $g):
                        $cust = $g['customer'];
                        $mins = number_format(((int) $g['billed_seconds']) / 60.0, 2, '.', '');
                        $amt = number_format((float) $g['total_amount'], 2, '.', '');
                    ?>
                        <tr>
                            <td><strong><?php echo $h($cust['customer_name']); ?></strong></td>
                            <td><code><?php echo $h($cust['external_client_id']); ?></code></td>
                            <td><code><?php echo $h($cust['extensions_csv']); ?></code></td>
                            <td><?php echo (int) $g['call_count']; ?></td>
                            <td><?php echo $h($mins); ?></td>
                            <td><strong><?php echo $h($amt . ' ' . $currency); ?></strong></td>
                            <td><?php echo count($g['items']); ?> line(s)</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php echo uiTableClose(); ?>

                <?php if ($selectedProvider !== null && (int) $selectedProvider['is_active'] === 1 && $previewCalls > 0): ?>
                    <form method="post" action="/plugins/voip-billing?<?php echo $h(http_build_query(['provider_id' => $selectedProviderId, 'period_start' => $periodStart, 'period_end' => $periodEnd])); ?>#billing-usage"
                          class="u-mt-12 form-actions"
                          onsubmit="return confirm('Create draft invoice(s) in ClientFence for <?php echo $previewCalls; ?> call(s)?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="billing_action" value="create_drafts">
                        <input type="hidden" name="provider_id" value="<?php echo $selectedProviderId; ?>">
                        <input type="hidden" name="period_start" value="<?php echo $h($periodStart); ?>">
                        <input type="hidden" name="period_end" value="<?php echo $h($periodEnd); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Create Draft Invoices (<?php echo $previewCalls; ?> calls · <?php echo $h(number_format((float) ($preview['total_amount'] ?? 0), 2, '.', '') . ' ' . $currency); ?>)
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <h4 class="u-mt-20 u-mb-10">Recent Draft Invoices</h4>
        <?php echo uiTableOpen(['Created', 'Provider', 'Customer', 'Period', 'Calls', 'Minutes', 'Subtotal', 'External Invoice / Reference', 'Status'], ['search' => false]); ?>
        <?php if ($invoices === []): ?>
            <?php echo uiTableEmptyRow(9, 'No draft invoices created yet.'); ?>
        <?php else: ?>
            <?php foreach ($invoices as $inv):
                $st = (string) ($inv['status'] ?? '');
                $badge = $st === 'created' ? 'badge-success' : ($st === 'failed' ? 'badge-danger' : 'badge-warning');
            ?>
                <tr>
                    <td class="u-fs-12"><?php echo $h($inv['created_at']); ?></td>
                    <td><?php echo $h($inv['provider_name'] ?: ('#' . $inv['provider_id'])); ?></td>
                    <td><?php echo $h($inv['customer_name'] ?: ('#' . $inv['customer_id'])); ?></td>
                    <td class="u-fs-12"><?php echo $h($inv['period_start'] . ' → ' . $inv['period_end']); ?></td>
                    <td><?php echo (int) $inv['call_count']; ?></td>
                    <td><?php echo $h(number_format(((int) $inv['billed_seconds']) / 60.0, 2, '.', '')); ?></td>
                    <td><?php echo $h(number_format((float) $inv['total_amount'], 2, '.', '')); ?></td>
                    <td><code><?php echo $h($inv['external_invoice_id'] !== '' ? ($st === 'created' ? '#' : '') . $inv['external_invoice_id'] : '—'); ?></code></td>
                    <td>
                        <span class="badge <?php echo $badge; ?>"><?php echo $h($st === 'pending' ? 'Awaiting confirmation' : strtoupper($st)); ?></span>
                        <?php if ($st === 'pending' && $inv['external_invoice_id'] !== ''): ?>
                            <form method="post" class="u-mt-10">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                <input type="hidden" name="billing_action" value="reconcile_draft">
                                <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
                                <label class="form-label" for="billing-external-id-<?php echo (int) $inv['id']; ?>">ClientFence invoice ID</label>
                                <input id="billing-external-id-<?php echo (int) $inv['id']; ?>" class="form-control" name="external_invoice_id" inputmode="numeric" required>
                                <button class="btn btn-secondary btn-sm" type="submit">Match invoice</button>
                            </form>
                            <form method="post" class="u-mt-6" onsubmit="return confirm('Only release this draft if you confirmed it does not exist in ClientFence. Continue?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                <input type="hidden" name="billing_action" value="release_draft">
                                <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Release calls</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($inv['error'])): ?>
                            <div class="u-hint u-fs-11"><?php echo $h($inv['error']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>
