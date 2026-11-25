<?php

declare(strict_types=1);

/**
 * Handles administrator POST actions on /plugins/voip-billing.
 */
require_once dirname(__DIR__) . '/lib/VoipBilling.php';

return static function (string $slug, array $settings): array {
    $action = (string) ($_POST['billing_action'] ?? '');
    if ($action === '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf', 'Invalid CSRF token')];
    }

    try {
        switch ($action) {
            case 'save_provider':
                VoipBilling::saveProvider((int) ($_POST['provider_id'] ?? 0), [
                    'name' => $_POST['name'] ?? '',
                    'provider' => $_POST['provider'] ?? 'clientfence',
                    'base_url' => $_POST['base_url'] ?? '',
                    'api_user' => $_POST['api_user'] ?? 'admin',
                    'api_key' => $_POST['api_key'] ?? '',
                    'is_active' => !empty($_POST['is_active']),
                ]);
                return ['message' => 'Provider account saved.'];

            case 'test_provider':
                $status = VoipBilling::testProvider((int) ($_POST['provider_id'] ?? 0), $settings);
                return ['message' => 'Connection test succeeded: ' . $status];

            case 'toggle_provider':
                $active = VoipBilling::toggleProvider((int) ($_POST['provider_id'] ?? 0));
                return ['message' => $active === 1 ? 'Provider account enabled.' : 'Provider account disabled.'];

            case 'delete_provider':
                VoipBilling::deleteProvider((int) ($_POST['provider_id'] ?? 0));
                return ['message' => 'Provider account and its billing records deleted.'];

            case 'save_customer':
                VoipBilling::saveCustomer((int) ($_POST['customer_id'] ?? 0), [
                    'provider_id' => $_POST['provider_id'] ?? 0,
                    'external_client_id' => $_POST['external_client_id'] ?? '',
                    'customer_name' => $_POST['customer_name'] ?? '',
                    'extensions_csv' => $_POST['extensions_csv'] ?? '',
                    'rate_per_minute' => $_POST['rate_per_minute'] ?? '',
                    'taxed' => $_POST['taxed'] ?? '',
                    'is_active' => !empty($_POST['is_active']),
                ]);
                return ['message' => 'Customer mapping saved.'];

            case 'delete_customer':
                VoipBilling::deleteCustomer((int) ($_POST['customer_id'] ?? 0));
                return ['message' => 'Customer mapping deleted.'];

            case 'save_rate':
                VoipBilling::saveRate((int) ($_POST['rate_id'] ?? 0), [
                    'provider_id' => $_POST['provider_id'] ?? 0,
                    'prefix' => $_POST['prefix'] ?? '',
                    'label' => $_POST['label'] ?? '',
                    'rate_per_minute' => $_POST['rate_per_minute'] ?? '',
                ]);
                return ['message' => 'Prefix rate saved.'];

            case 'delete_rate':
                VoipBilling::deleteRate((int) ($_POST['rate_id'] ?? 0));
                return ['message' => 'Prefix rate deleted.'];

            case 'reconcile_draft':
                VoipBilling::reconcileDraft((int) ($_POST['invoice_id'] ?? 0), (string) ($_POST['external_invoice_id'] ?? ''), $settings);
                return ['message' => 'Draft matched to the ClientFence invoice.'];

            case 'release_draft':
                VoipBilling::releasePendingDraft((int) ($_POST['invoice_id'] ?? 0));
                return ['message' => 'Pending draft released; calls are available for billing again.'];

            case 'create_drafts':
                $providerId = (int) ($_POST['provider_id'] ?? 0);
                $periodStart = (string) ($_POST['period_start'] ?? '');
                $periodEnd = (string) ($_POST['period_end'] ?? '');
                $res = VoipBilling::createDraftInvoices($providerId, $periodStart, $periodEnd, $settings);

                $currency = strtoupper(trim((string) ($settings['default_currency'] ?? 'USD'))) ?: 'USD';
                if ($res['created'] === 0 && $res['failed'] === 0) {
                    return ['message' => 'No unbilled outbound calls found for the selected period.'];
                }
                $out = [];
                if ($res['created'] > 0) {
                    $out['message'] = sprintf(
                        'Created %d draft invoice(s) (%d call(s), %s %s).',
                        $res['created'],
                        $res['total_calls'],
                        number_format((float) $res['total_amount'], 2, '.', ''),
                        $currency
                    );
                }
                if ($res['failed'] > 0) {
                    $out['error'] = sprintf(
                        '%d invoice(s) failed: %s',
                        $res['failed'],
                        implode(' | ', $res['errors'])
                    );
                }
                return $out;
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }

    return [];
};
