<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * ClientFence / FOSSBilling Admin API billing provider.
 *
 * Authentication: HTTP Basic Auth with API user (default 'admin') and API key.
 * Required API permission: invoice.manage_invoices.
 */
final class BillingClientfence extends BillingProvider
{
    public static function id(): string
    {
        return 'clientfence';
    }

    public static function label(): string
    {
        return 'ClientFence';
    }

    public function configured(): bool
    {
        if ($this->accountField('api_key') === '') {
            return false;
        }
        try {
            self::validateBaseUrl($this->accountField('base_url'));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function testConnection(): string
    {
        $baseUrl = $this->validatedEndpointBase();
        [$code, $json] = $this->requestJson(
            'POST',
            $baseUrl . '/api/admin/invoice/get_list',
            $this->headers(),
            self::jsonEncode(['per_page' => 1])
        );

        $this->assertOk($code, $json);
        return 'Connected';
    }

    public function createDraftInvoice(string $externalClientId, string $periodText, array $items): string
    {
        $clientIdRaw = trim($externalClientId);
        if (!ctype_digit($clientIdRaw) || (int) $clientIdRaw <= 0) {
            throw new \InvalidArgumentException('ClientFence client_id must be a positive integer.');
        }
        $clientId = (int) $clientIdRaw;

        if ($items === []) {
            throw new \InvalidArgumentException('Cannot create a draft invoice without line items.');
        }

        $baseUrl = $this->validatedEndpointBase();
        $payload = [
            'client_id' => $clientId,
            'text_1' => $periodText,
            'approve' => false,
            'items' => array_values($items),
        ];

        [$code, $json] = $this->requestJson(
            'POST',
            $baseUrl . '/api/admin/invoice/prepare',
            $this->headers(),
            self::jsonEncode($payload)
        );

        $error = $json['error'] ?? null;
        if (is_array($error) && ($json['result'] ?? null) === null
            && (in_array($code, [401, 403], true)
                || ($code === 200 && in_array($error['message'] ?? '', [
                    'Client not found', 'Invoice item title is missing',
                    'Deposit invoice items can only be created through the Add Funds flow',
                ], true)))) {
            throw new BillingDraftRejected('ClientFence rejected the draft: ' . ($error['message'] ?? 'Access denied'));
        }
        $this->assertOk($code, $json);

        $result = $json['result'] ?? null;
        if (is_array($result) && isset($result['id'])) {
            $result = $result['id'];
        }
        if (is_int($result) && $result > 0) {
            return (string) $result;
        }
        if (is_string($result) && preg_match('/^[1-9][0-9]{0,63}$/D', $result)) {
            return $result;
        }

        throw new \RuntimeException('ClientFence did not return a valid invoice ID.');
    }

    public function customerCurrency(string $externalClientId): string
    {
        $result = $this->fetchRecord('client/get', $externalClientId);
        $currency = strtoupper(trim((string) ($result['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \RuntimeException('The ClientFence customer must have a currency configured.');
        }
        return $currency;
    }

    public function verifyInvoiceReference(string $externalInvoiceId, string $reference): void
    {
        $invoice = $this->fetchRecord('invoice/get', $externalInvoiceId);
        if ((string) ($invoice['text_1'] ?? '') !== $reference) {
            throw new \RuntimeException('This ClientFence invoice does not have the saved AiPBX reference.');
        }
    }

    private function fetchRecord(string $path, string $id): array
    {
        $number = (int) $id;
        if (!ctype_digit($id) || $number <= 0 || (string) $number !== ltrim($id, '0')) {
            throw new \InvalidArgumentException('ClientFence ID must be a positive integer.');
        }
        [$code, $json] = $this->requestJson(
            'POST',
            $this->validatedEndpointBase() . '/api/admin/' . $path,
            $this->headers(),
            self::jsonEncode(['id' => $number])
        );
        $this->assertOk($code, $json);
        $record = $json['result'] ?? null;
        if (!is_array($record) || (string) ($record['id'] ?? '') !== (string) $number) {
            throw new \RuntimeException('ClientFence returned an unexpected record.');
        }
        return $record;
    }

    private function validatedEndpointBase(): string
    {
        if ($this->accountField('api_key') === '') {
            throw new \RuntimeException('ClientFence API key is not configured.');
        }
        return self::validateBaseUrl($this->accountField('base_url'));
    }

    /** @return list<string> */
    private function headers(): array
    {
        $user = $this->accountField('api_user', 'admin');
        $key = $this->accountField('api_key');
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            self::basicAuthHeader($user, $key),
        ];
    }

    /** @param array<string, mixed> $json */
    private function assertOk(int $code, array $json): void
    {
        $error = $json['error'] ?? null;
        if ($error !== null && $error !== false && $error !== '') {
            $msg = is_array($error)
                ? (string) ($error['message'] ?? $error['detail'] ?? json_encode($error, JSON_UNESCAPED_UNICODE))
                : (string) $error;
            throw new \RuntimeException('ClientFence error' . ($code !== 200 ? " ({$code})" : '') . ': ' . mb_substr($msg, 0, 200));
        }

        if ($code !== 200) {
            $msg = is_string($json['message'] ?? null) ? $json['message'] : ('HTTP ' . $code);
            throw new \RuntimeException('ClientFence ' . mb_substr($msg, 0, 200));
        }
    }
}
