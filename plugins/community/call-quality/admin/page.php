<?php
/**
 * /plugins/call-quality: is anything measured, quality per trunk and
 * extension, and the poor and fair call legs of the last 24 hours.
 * Available: $plugin, $settings, $csrf_token, getDB(), PluginManager.
 */
require_once dirname(__DIR__) . '/CallQuality.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pct = static fn($v): string => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . ' %';
$db = getDB();
$slug = (string) $plugin['slug'];
$capture = CallQuality::captureState($db);
$pass = PluginManager::kvGet($slug, 'state', 'pass')['value'] ?? null;
$summary = CallQuality::summary($db, 24);
$bad = CallQuality::badLegs($db, 24, 50);
$legName = static fn(array $r): string => match ($r['leg_type']) {
    'trunk' => 'Trunk ' . $r['leg_name'],
    'extension' => 'Extension ' . $r['leg_name'],
    'other' => 'Other (' . $r['leg_name'] . ')',
    default => 'Not identified yet',
};
$badge = static fn(string $level): string => ['poor' => 'badge-danger', 'fair' => 'badge-warning'][$level] ?? 'badge-success';
?>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-satellite-dish"></i> Measurement</div></div>
    <div style="padding: 16px;">
        <?php if (!$capture['counters']): ?>
            <p class="u-danger">This AiPBX has no RTCP counters (1.50.2 or newer is needed).</p>
        <?php elseif ($capture['last_rtcp'] === null): ?>
            <p class="u-warning"><strong>No RTCP report in the last 24 hours.</strong>
                Turn on signaling capture (Asterisk's <code>hep.conf</code>, see the AiPBX docs: Signaling capture),
                then make a call; the first measurements appear a minute after it.</p>
        <?php else: ?>
            <p>Last RTCP report: <strong><?php echo $h($capture['last_rtcp']); ?></strong>.</p>
        <?php endif; ?>
        <p class="u-muted u-fs-12">
            Last pass: <?php echo $pass ? (time() - (int) $pass['at']) . ' s ago — ' . (int) $pass['reports'] . ' report(s), ' . (int) $pass['legs'] . ' leg(s), ' . (int) $pass['alerts'] . ' alert(s) open' : 'not yet (is the worker running?)'; ?>.
            <br><strong>In</strong> is what this PBX received, <strong>out</strong> what the other side received:
            a trunk losing audio "in" points at the carrier's side, "out" at ours (uplink, firewall, bandwidth).
            A leg is <span class="badge badge-danger">poor</span> from two reports with
            <?php echo $h(CallQuality::poorPct($settings)); ?> % loss in one direction,
            <span class="badge badge-warning">fair</span> from <?php echo $h(CallQuality::FAIR_PCT); ?> %.
            Jitter is in RTP timestamp units (8 = 1 ms for G.711).
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-chart-line"></i> Last 24 hours by trunk and extension</div></div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Leg</th><th>Calls</th><th>Poor</th><th>Fair</th><th>Loss in (avg / max)</th><th>Loss out (avg / max)</th><th>Jitter max (in / out)</th></tr></thead>
            <tbody>
            <?php if (!$summary): ?>
                <tr><td colspan="7" class="u-muted">No measured call yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($summary as $r): ?>
                <tr>
                    <td><strong><?php echo $h($legName($r)); ?></strong></td>
                    <td><?php echo (int) $r['legs']; ?></td>
                    <td><?php echo (int) $r['poor'] ? '<span class="badge badge-danger">' . (int) $r['poor'] . '</span>' : '0'; ?></td>
                    <td><?php echo (int) $r['fair']; ?></td>
                    <td><?php echo $h($pct($r['avg_in'])) . ' / ' . $h($pct($r['max_in'])); ?></td>
                    <td><?php echo $h($pct($r['avg_out'])) . ' / ' . $h($pct($r['max_out'])); ?></td>
                    <td><?php echo (int) $r['jit_in'] . ' / ' . (int) $r['jit_out']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-triangle-exclamation"></i> Poor and fair calls (last 24 hours, newest first)</div></div>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Level</th><th>Leg</th><th>Media from</th><th>Loss in (max)</th><th>Loss out (max)</th><th>Reports in / out</th><th>Call-ID</th></tr></thead>
            <tbody>
            <?php if (!$bad): ?>
                <tr><td colspan="8" class="u-muted">None — every measured call was good.</td></tr>
            <?php endif; ?>
            <?php foreach ($bad as $r): ?>
                <tr>
                    <td class="u-fs-12"><?php echo $h($r['first_ts']); ?></td>
                    <td><span class="badge <?php echo $badge($r['level']); ?>"><?php echo $h($r['level']); ?></span></td>
                    <td><?php echo $h($legName($r)); ?></td>
                    <td class="u-fs-12"><?php echo $h($r['peer']); ?></td>
                    <td><?php echo $h($pct($r['loss_in_max'])); ?></td>
                    <td><?php echo $h($pct($r['loss_out_max'])); ?></td>
                    <td><?php echo (int) $r['reports_in'] . ' / ' . (int) $r['reports_out']; ?></td>
                    <td class="u-fs-11"><code><?php echo $h(mb_substr($r['call_id'], 0, 24)); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
