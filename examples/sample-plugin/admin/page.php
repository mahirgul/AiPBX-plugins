<?php
/**
 * Admin page hook: rendered on /plugins/sample-plugin below the settings form
 * that the core builds from settings_schema. Only administrators reach it.
 * Available here: $plugin (manifest + state), $settings (decrypted),
 * $csrf_token, getDB(), PluginManager and the portal helpers. Escape everything
 * you print. Plugins are not translated by the portal: write your own texts.
 */
$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$tick = PluginManager::kvGet((string)$plugin['slug'], 'state', 'last_tick');
$events = getDB()->query('SELECT event_name, summary, created_at FROM plg_sample_events ORDER BY id DESC LIMIT 10')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$badge = ($settings['colour'] ?? 'blue') === 'green' ? 'badge-success' : 'badge-info';
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-puzzle-piece"></i> Sample plugin</div>
    </div>
    <div style="padding: 16px;">
        <p><span class="badge <?php echo $badge; ?>"><?php echo $h($settings['greeting'] ?? ''); ?></span></p>
        <p>
            Worker ran <strong><?php echo (int)($tick['value']['count'] ?? 0); ?></strong> time(s),
            last at <?php echo $tick ? $h(date('Y-m-d H:i:s', (int)$tick['value']['at'])) : 'never (is the worker enabled?)'; ?>.
        </p>
        <?php foreach (['reset_tick' => 'Reset counter', 'clear_events' => 'Delete recorded events'] as $action => $label): ?>
            <form method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <input type="hidden" name="sample_action" value="<?php echo $h($action); ?>">
                <button type="submit" class="btn btn-secondary"><?php echo $h($label); ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list"></i> Last call.ended events (<?php echo count($events); ?>)</div>
    </div>
    <div style="padding: 16px;">
        <?php if (!$events): ?>
            <p>No event yet. Make a call; it appears here a few seconds after it ends.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Time</th><th>Event</th><th>Call</th></tr></thead>
                <tbody>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td><?php echo $h($e['created_at']); ?></td>
                        <td><?php echo $h($e['event_name']); ?></td>
                        <td><?php echo $h($e['summary']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
