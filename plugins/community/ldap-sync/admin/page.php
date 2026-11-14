<?php
/**
 * Status of the last read and the two buttons. Rendered below the settings form.
 */
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$slug = (string) $plugin['slug'];
$last = PluginManager::kvGet($slug, 'state', 'last_run')['value'] ?? null;
$count = 0;
try {
    $st = getDB()->prepare('SELECT COUNT(*) FROM phonebook_contacts WHERE source = ?');
    $st->execute(['ldap-sync']);
    $count = (int) $st->fetchColumn();
} catch (Throwable $e) {
}
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-address-book"></i> Directory contacts</div>
    </div>
    <div style="padding: 16px;">
        <?php if (!function_exists('ldap_connect')): ?>
            <div class="alert alert-danger">PHP LDAP support (php-ldap) is missing on this PBX. Updating AiPBX installs it.</div>
        <?php endif; ?>
        <p><strong><?php echo $count; ?></strong> contact(s) from the directory are in the
            <a href="/phonebook">phonebook</a>; the desk phones see them there.</p>
        <?php if ($last === null): ?>
            <p class="text-muted">Not read yet. Fill in the settings above, test the connection, then read.</p>
        <?php elseif (!empty($last['ok'])): ?>
            <p><span class="badge badge-success">OK</span>
                Last read <?php echo $h(date('Y-m-d H:i', (int) $last['at'])); ?>:
                <?php echo (int) $last['found']; ?> found, <?php echo (int) $last['insert']; ?> added,
                <?php echo (int) $last['update']; ?> changed, <?php echo (int) $last['delete']; ?> removed.</p>
        <?php else: ?>
            <p><span class="badge badge-danger">Error</span>
                <?php echo $h(date('Y-m-d H:i', (int) $last['at'])); ?>: <?php echo $h($last['error'] ?? ''); ?></p>
        <?php endif; ?>
        <?php foreach (['test' => 'Test the connection', 'run_now' => 'Read now'] as $action => $label): ?>
            <form method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <input type="hidden" name="ldap_sync_action" value="<?php echo $h($action); ?>">
                <button type="submit" class="btn btn-secondary btn-sm"><?php echo $h($label); ?></button>
            </form>
        <?php endforeach; ?>
        <p class="text-muted" style="margin-top: 12px; font-size: 12px;">Contacts are only read: nothing is written to
            the directory. A contact needs a name and at least one number. When the directory answers with nobody, the
            phonebook is left as it is.</p>
    </div>
</div>
