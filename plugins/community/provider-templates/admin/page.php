<?php
/**
 * Available here: $plugin, $settings, $csrf_token and the portal helpers. Escape everything printed:
 * template texts come from contributors.
 */
require_once dirname(__DIR__) . '/lib/Templates.php';
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$templates = ProviderTemplates::load(dirname(__DIR__) . '/templates');
$chosen = (string) ($_POST['provider_template'] ?? $_GET['template'] ?? '');
$template = $templates[$chosen] ?? null;
$countries = class_exists('DialplanRuleService') ? DialplanRuleService::getAllCountries() : [];
uasort($countries, static fn(array $a, array $b): int => strcmp((string) ($a['name_en'] ?? ''), (string) ($b['name_en'] ?? '')));
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-network-wired"></i> Provider templates</div>
    </div>
    <p class="u-muted u-fs-12" style="margin: 0 20px 12px;">Templates are written by the community. Only those marked as tested have been used with real calls. You see every setting before the trunk is created, and you can change it under Trunks afterwards.</p>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Provider</th><th>Country</th><th>Tested</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $id => $t): ?>
            <tr>
                <td><?php echo $h($t['name']); ?></td>
                <td><?php echo $h($t['country']); ?></td>
                <td><?php if (!empty($t['tested'])): ?>
                    <span class="badge badge-success"><?php echo $h($t['tested']['date']); ?>, AiPBX <?php echo $h($t['tested']['aipbx']); ?>, @<?php echo $h($t['tested']['by']); ?></span>
                <?php else: ?>
                    <span class="badge badge-warning">Not tested with real calls</span>
                <?php endif; ?></td>
                <td style="text-align: right;"><a class="btn btn-xs btn-secondary" href="/plugins/provider-templates?template=<?php echo $h($id); ?>#provider-form">Use</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="u-muted u-fs-12" style="margin: 12px 20px;">Is your provider missing, or did you test one? <a href="https://github.com/mahirgul/AiPBX-plugins/tree/main/plugins/community/provider-templates#adding-a-provider" target="_blank" rel="noopener noreferrer">Add it with one small file.</a></p>
</div>

<?php if ($template !== null): ?>
<div class="card" id="provider-form">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-plus-circle"></i> New trunk: <?php echo $h($template['name']); ?></div>
    </div>
    <div style="padding: 0 20px 20px;">
        <?php if (empty($template['tested'])): ?>
            <div class="alert alert-warning">This template comes from <?php echo $h($template['source']); ?> and was not tested with real calls.</div>
        <?php endif; ?>
        <?php if (!empty($template['notes'])): ?><p class="u-fs-12"><?php echo $h($template['notes']); ?></p><?php endif; ?>
        <p class="u-fs-12"><a href="<?php echo $h($template['docs_url']); ?>" target="_blank" rel="noopener noreferrer">Provider documentation</a></p>

        <h4 style="font-size: 13px; margin: 14px 0 6px;">Settings this template sets</h4>
        <div class="table-responsive"><table class="data-table"><tbody>
            <?php foreach ($template['trunk'] as $field => $value): ?>
                <tr><td><code><?php echo $h($field); ?></code></td><td><code><?php echo $h($value); ?></code></td></tr>
            <?php endforeach; ?>
            <?php if (!empty($template['send_e164'])): ?><tr><td><code>Send as E.164</code></td><td><code>yes, with the country below</code></td></tr><?php endif; ?>
        </tbody></table></div>

        <form method="POST" autocomplete="off" action="/plugins/provider-templates?template=<?php echo $h($chosen); ?>#provider-form" style="margin-top: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="provider_template" value="<?php echo $h($chosen); ?>">
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label">Trunk name</label>
                    <input type="text" name="trunk_name" class="form-control" required maxlength="40" pattern="[a-zA-Z0-9_-]+" value="<?php echo $h($_POST['trunk_name'] ?? $chosen); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">SIP server</label>
                    <input type="text" name="host" class="form-control" required maxlength="253" value="<?php echo $h($_POST['host'] ?? ($template['host'] ?? '')); ?>">
                    <?php if (!empty($template['host_hint'])): ?><small class="u-hint"><?php echo $h($template['host_hint']); ?></small><?php endif; ?>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">SIP domain (optional)</label>
                <input type="text" name="from_domain" class="form-control" maxlength="253" value="<?php echo $h($_POST['from_domain'] ?? ''); ?>">
                <small class="u-hint">Only if your provider gave you a domain for your account.</small>
            </div>
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label">User name</label>
                    <input type="text" name="auth_username" class="form-control" maxlength="80" autocomplete="off" <?php echo !empty($template['needs_login']) ? 'required' : ''; ?> value="<?php echo $h($_POST['auth_username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="auth_password" class="form-control" maxlength="120" autocomplete="new-password" <?php echo !empty($template['needs_login']) ? 'required' : ''; ?>>
                </div>
            </div>
            <?php if (!empty($template['send_e164'])): ?>
            <div class="form-group">
                <label class="form-label">Your country</label>
                <select name="country_code" class="form-control" required>
                    <option value=""></option>
                    <?php foreach ($countries as $cc => $country): if (!isset($country['trunk_prefix'], $country['intl_prefix'])) { continue; } ?>
                        <option value="<?php echo $h($cc); ?>" <?php echo (string) ($_POST['country_code'] ?? '') === (string) $cc ? 'selected' : ''; ?>><?php echo $h($country['name_en'] ?? $cc); ?> (+<?php echo $h($cc); ?>)</option>
                    <?php endforeach; ?>
                </select>
                <small class="u-hint">Numbers are converted to E.164 with this country's dialling plan.</small>
            </div>
            <?php endif; ?>
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label">Caller ID: keep the last digits (optional)</label>
                    <input type="number" name="cid_keep_last" class="form-control" min="0" max="20" value="<?php echo $h($_POST['cid_keep_last'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Caller ID: prepend (optional)</label>
                    <input type="text" name="cid_prepend" class="form-control" maxlength="30" placeholder="+1" value="<?php echo $h($_POST['cid_prepend'] ?? ''); ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create the trunk</button>
        </form>
    </div>
</div>
<?php endif; ?>
