<?php
require_once dirname(__DIR__) . '/lib/Templates.php';

/** Creates a trunk from a template. Administrators only reach this page. */
return static function (string $slug, array $settings): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['provider_template'])) {
        return [];
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return ['error' => t('common.invalid_csrf')];
    }
    if (!class_exists('TrunkService') || !class_exists('DialplanRuleService') || !function_exists('trunkE164Plan')) {
        return ['error' => 'This plugin needs AiPBX 1.60.6 or newer.'];
    }
    $templates = ProviderTemplates::load(dirname(__DIR__) . '/templates');
    $template = $templates[(string) $_POST['provider_template']] ?? null;
    if ($template === null) {
        return ['error' => 'Unknown template.'];
    }
    if (!empty($template['send_e164']) && DialplanRuleService::getDialPlan((string) ($_POST['country_code'] ?? '')) === null) {
        return ['error' => 'Choose your country: this provider needs numbers with a country code.'];
    }
    if (!empty($template['needs_login']) && (trim((string) ($_POST['auth_username'] ?? '')) === '' || (string) ($_POST['auth_password'] ?? '') === '')) {
        return ['error' => 'This provider needs a user name and a password.'];
    }
    $domain = trim((string) ($_POST['from_domain'] ?? ''));
    if ($domain !== '' && !ProviderTemplates::isHost($domain)) {
        return ['error' => 'The SIP domain must be a host name.'];
    }
    $result = TrunkService::saveTrunk(['csrf_token' => $_POST['csrf_token']] + ProviderTemplates::trunkData($template, $_POST));
    return !empty($result['success'])
        ? ['message' => 'The trunk was created. Check it under Trunks, add an outbound route, and press Apply.']
        : ['error' => (string) ($result['error'] ?? 'The trunk could not be saved.')];
};
