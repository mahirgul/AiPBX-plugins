<?php
/**
 * Call quality panel on `/plugins/call-quality`.
 *
 * Read-only on purpose. The plugin records evidence, names the route change a
 * person could make and mails an address if one is configured; it never
 * reorders a route, regenerates a dialplan or touches a live call. The change
 * itself is applied by an administrator on the Outbound routes page, which
 * stays the only place a route order is edited.
 *
 * The first question an install that "does nothing" has is whether RTCP even
 * arrives here, so capture state comes before the numbers.
 *
 * @var array<string, mixed> $plugin
 * @var array<string, mixed> $settings
 * @var string $csrf_token
 */

require_once dirname(__DIR__) . '/CaptureState.php';
require_once dirname(__DIR__) . '/CallQualityService.php';

$settings = is_array($settings ?? null) ? $settings : [];
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$capture = CaptureState::snapshot();
$counters = CallQualityService::countersAvailable();
$scoring = $capture['local'] && $counters;
$summary = $scoring ? CallQualityService::summary() : null;
$evidence = CallQualityService::recentEvidence(24, 20);
$lastPass = CallQualityService::lastPass((string) ($plugin['slug'] ?? 'call-quality'));
$alertTo = CallQualityService::notifyAddress($settings);
$cooldown = max(1, min(168, (int) ($settings['alert_cooldown_hours'] ?? 6)));
$trailId = isset($_GET['evidence']) ? (int) $_GET['evidence'] : 0;
$trail = $trailId > 0 ? CallQualityService::evidenceTrail($trailId) : '';

// What is degraded right now, grouped per trunk, with the change a person could
// make. Nothing here is applied.
$findings = [];
if ($scoring) {
    $map = CallQualityService::trunkMap();
    foreach (CallQualityService::degraded(CallQualityService::ALERT_WINDOW_MIN) as $call) {
        $trunk = CallQualityService::trunkFor($call['peers'], $map);
        $name = $trunk !== null ? (string) $trunk['trunk_name'] : '';
        $key = $name !== '' ? $name : 'peer:' . (string) ($call['peers'][0] ?? 'unknown');
        if (!isset($findings[$key])) {
            $findings[$key] = [
                'trunk' => $name,
                'peer' => (string) ($call['peers'][0] ?? ''),
                'calls' => [],
                'loss' => 0.0,
                'reports' => 0,
            ];
        }
        $findings[$key]['calls'][] = $call;
        $findings[$key]['loss'] = max((float) $findings[$key]['loss'], (float) $call['verdict']['loss_pct']);
        $findings[$key]['reports'] += (int) $call['verdict']['reports'];
    }
    foreach ($findings as $key => $finding) {
        // Computed once per trunk: the text and the link must not disagree.
        $findings[$key]['routes'] = $finding['trunk'] !== ''
            ? CallQualityService::routeOpportunities($finding['trunk'])
            : [];
    }
}

// Two trunks losing media at once is not a trunk problem, and suggesting a
// reorder for one of them would blame a carrier that may not be at fault — the
// same rule the pass applies before it records `multiple_trunks`.
$trunkFindings = array_filter($findings, static fn(array $f): bool => $f['trunk'] !== '');
$multiTrunk = count($trunkFindings) > 1;
// Two trunks losing media is one problem (a shared uplink), not two suggestions.
$problemCount = $multiTrunk
    ? 1 + count(array_filter($findings, static fn(array $f): bool => $f['trunk'] === ''))
    : count($findings);

$captureBadge = $scoring ? 'badge-success' : 'badge-warning';
$captureLabel = $scoring
    ? 'RTCP arrives here'
    : ($capture['local'] ? 'Counters not stored by this build' : 'No local RTCP rows');

$levelBadge = static fn(string $level): string => match ($level) {
    'poor' => 'badge-danger',
    'fair' => 'badge-warning',
    'good' => 'badge-success',
    default => 'badge-secondary',
};

$lastPassAt = (int) ($lastPass['ts'] ?? 0) > 0
    ? date('Y-m-d H:i:s', (int) $lastPass['ts'])
    : 'never';
?>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-satellite-dish u-info"></i> Capture state
            <span class="badge <?php echo $captureBadge; ?> u-fs-11 u-ml-8"><?php echo $h($captureLabel); ?></span>
        </div>
    </div>
    <div style="padding: 16px 20px;">
        <p style="margin: 0 0 10px;"><?php echo $h($capture['detail']); ?></p>
        <table class="data-table">
            <tbody>
                <tr>
                    <th style="width: 220px;">Mode</th>
                    <td><code><?php echo $h($capture['mode']); ?></code></td>
                </tr>
                <tr>
                    <th>Asterisk sends HEP to</th>
                    <td><code><?php echo $h($capture['address'] !== '' ? $capture['address'] : '(not set)'); ?></code></td>
                </tr>
                <tr>
                    <th>aipbx-signaling listens on</th>
                    <td><code><?php echo $h($capture['listener']); ?></code></td>
                </tr>
                <tr>
                    <th>Configuration</th>
                    <td><code><?php echo $h($capture['path']); ?></code><?php if ($capture['includes']): ?>
                        <span class="u-fs-12" style="color: var(--text-muted);"> (includes <?php echo $h(implode(', ', $capture['includes'])); ?>)</span><?php endif; ?></td>
                </tr>
                <tr>
                    <th>Change to the PBX</th>
                    <td><span class="badge badge-secondary u-fs-11">none — this plugin never edits a route or a dialplan</span></td>
                </tr>
            </tbody>
        </table>
        <?php if ($summary !== null): ?>
            <p style="margin: 12px 0 0; font-size: 13px; color: var(--text-muted);">
                Last <?php echo (int) CallQualityService::WINDOW_MIN; ?> min:
                <?php echo (int) $summary['measured']; ?> call(s) with RTCP reports —
                <?php echo (int) $summary['poor']; ?> poor,
                <?php echo (int) $summary['fair']; ?> with some loss,
                <?php echo (int) $summary['good']; ?> good.
            </p>
        <?php endif; ?>
        <?php if (!$capture['local']): ?>
            <p style="margin: 10px 0 0; font-size: 13px;">
                Nothing is scored until Asterisk's <code>hep.conf</code> points at this server's daemon
                (<code>enabled = yes</code>, <code>capture_address = 127.0.0.1:9061</code>). A remote HOMER or
                VoIPmonitor collector is a valid choice — it just keeps the reports there, not here.
            </p>
        <?php elseif (!$counters): ?>
            <div class="alert alert-warning" style="margin: 10px 0 0;">
                <i class="fas fa-exclamation-triangle"></i>
                Capture reaches this server, but this AiPBX build does not store RTCP counters on
                <code>pbx_signaling_events</code> yet — the capture daemon keeps them from <strong>1.50.2</strong>.
                Until the portal is updated there is nothing to score, and this page will not call a line
                healthy on the strength of an empty measurement.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-clock u-primary"></i> Last pass
            <span class="badge badge-secondary u-fs-11 u-ml-8">every <?php echo (int) CallQualityService::PASS_MINUTES; ?> min</span>
        </div>
    </div>
    <div style="padding: 16px 20px;">
        <p style="margin: 0 0 10px; font-size: 13px;">
            The pass runs from <code>worker/tick.php</code>, polled by
            <code>aipbx-webhook-worker.service</code>. Its own clock keeps it to one pass every
            <?php echo (int) CallQualityService::PASS_MINUTES; ?> minutes; the job does nothing in between.
        </p>
        <p style="margin: 0 0 10px;">
            <strong><?php echo $h($lastPassAt); ?></strong>
            <?php if ($lastPass !== []): ?>
                — state <code><?php echo $h((string) ($lastPass['state'] ?? 'unknown')); ?></code><?php
                if (isset($lastPass['capture'])): ?>,
                    capture <code><?php echo $h((string) $lastPass['capture']); ?></code>,
                    <?php echo (int) ($lastPass['measured'] ?? 0); ?> scored,
                    <?php echo (int) ($lastPass['poor'] ?? 0); ?> poor,
                    <?php echo (int) ($lastPass['evidence'] ?? 0); ?> evidence row(s),
                    <?php echo (int) ($lastPass['mails'] ?? 0); ?> e-mail(s)<?php endif; ?>
            <?php endif; ?>
        </p>
        <?php if (!empty($lastPass['error'])): ?>
            <div class="alert alert-danger" style="margin:0 0 10px;">
                <i class="fas fa-exclamation-triangle"></i> The last pass failed: <?php echo $h((string) $lastPass['error']); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($lastPass['skipped'])): ?>
            <p style="margin: 0 0 10px; font-size: 13px; color: var(--text-muted);">
                Skipped: <?php foreach ((array) $lastPass['skipped'] as $skipped): ?><code><?php echo $h($skipped); ?></code> <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($lastPass['notes'])): ?>
            <ul style="margin: 0 0 12px 18px; padding: 0; font-size: 13px;">
                <?php foreach ((array) $lastPass['notes'] as $note): ?>
                    <li><?php echo $h((string) $note); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <input type="hidden" name="call_quality_action" value="run">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-play"></i> Run a pass now
                </button>
            </form>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <input type="hidden" name="call_quality_action" value="test_mail">
                <button type="submit" class="btn btn-secondary btn-sm" <?php echo $alertTo === '' ? 'disabled title="Set an e-mail address in the settings first."' : ''; ?>>
                    <i class="fas fa-paper-plane"></i> Send a test e-mail
                </button>
            </form>
            <span class="u-fs-12" style="color: var(--text-muted);">
                <?php if ($alertTo !== ''): ?>
                    Alerts go to <code><?php echo $h($alertTo); ?></code>, at most once per <?php echo (int) $cooldown; ?> h per trunk.
                <?php else: ?>
                    No alert address is set: findings stay on this page and nothing is mailed.
                <?php endif; ?>
            </span>
        </div>
    </div>
</div>

<?php if ($trail !== ''): ?>
    <div class="card u-mb-20">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-file-alt u-primary"></i> Evidence #<?php echo (int) $trailId; ?> — full trail
            </div>
        </div>
        <div style="padding: 16px 20px;">
            <pre style="margin:0; white-space:pre-wrap; font-size:12px; line-height:1.5;"><?php echo $h($trail); ?></pre>
            <p style="margin: 12px 0 0;"><a href="/plugins/<?php echo $h((string) ($plugin['slug'] ?? 'call-quality')); ?>" class="btn btn-secondary btn-sm">Back to the evidence table</a></p>
        </div>
    </div>
<?php endif; ?>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-lightbulb u-warning"></i> What a person could change
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo (int) $problemCount; ?></span>
        </div>
    </div>
    <div style="padding: 16px 20px;">
        <?php if ($findings === []): ?>
            <p style="margin:0; font-size: 13px; color: var(--text-muted);">
                <?php
                if (!$capture['local']) {
                    echo 'Nothing to score while RTCP does not reach this server.';
                } elseif (!$counters) {
                    echo 'Nothing to score: this AiPBX build does not store RTCP counters yet (needs 1.50.2).';
                } else {
                    echo 'No call has been at or above ' . (int) CallQualityService::POOR_PCT
                        . '% packet loss twice in the last ' . (int) CallQualityService::ALERT_WINDOW_MIN . ' minutes.';
                }
                ?>
            </p>
        <?php else: ?>
            <p style="margin: 0 0 12px; font-size: 13px; color: var(--text-muted);">
                Loss seen in RTCP is often on your own uplink, so this plugin never moves a trunk by itself.
                Each suggestion below is a change you can make on the Outbound routes page for new calls only.
            </p>
            <?php if ($multiTrunk): ?>
                <div class="alert alert-warning" style="margin:0 0 12px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong><?php echo $h(implode(', ', array_map(static fn(array $f): string => $f['trunk'], $trunkFindings))); ?></strong>
                    are losing packets at the same time. No route change is suggested for either of them:
                    moving one would blame a carrier that may not be at fault. Check the uplink and both carriers.
                </div>
            <?php endif; ?>
            <?php foreach ($findings as $finding): ?>
                <?php if ($multiTrunk && $finding['trunk'] !== '') { continue; } ?>
                <div style="border:1px solid var(--border-color, #e2e8f0); border-radius:10px; padding:14px 16px; margin:0 0 12px;">
                    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:baseline;">
                        <strong>
                            <?php if ($finding['trunk'] !== ''): ?>
                                Trunk <code><?php echo $h($finding['trunk']); ?></code>
                            <?php else: ?>
                                Peer <code><?php echo $h($finding['peer']); ?></code> (not a configured trunk)
                            <?php endif; ?>
                            <span class="badge badge-danger u-fs-11 u-ml-8"><?php echo $h(sprintf('%.2f%%', (float) $finding['loss'])); ?> loss</span>
                        </strong>
                        <span class="u-fs-12" style="color: var(--text-muted);">
                            <?php echo count($finding['calls']); ?> poor call(s),
                            <?php echo (int) $finding['reports']; ?> RTCP report(s)
                        </span>
                    </div>
                    <p style="margin: 8px 0 0; font-size: 13px;">
                        <?php
                        if ($finding['trunk'] !== '') {
                            echo $h(CallQualityService::suggestionText((string) $finding['trunk'], $finding['routes']));
                        } else {
                            echo 'No route change applies: the media peer is not a configured trunk. Check the phone, its Wi-Fi or the local switch.';
                        }
                        ?>
                    </p>
                    <?php if ($finding['routes'] !== []): ?>
                        <p style="margin: 10px 0 0;">
                            <a href="/outbound-routes" class="btn btn-secondary btn-sm">
                                <i class="fas fa-random"></i> Open Outbound routes
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-clipboard-check u-primary"></i> Evidence, last 24 h
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($evidence); ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Recorded</th>
                    <th>Level</th>
                    <th>Call</th>
                    <th>Trunk</th>
                    <th>Peer</th>
                    <th>Worst loss</th>
                    <th>Reports</th>
                    <th>Finding</th>
                    <th>E-mailed</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($evidence === []): ?>
                    <?php echo uiTableEmptyRow(11, 'No quality incident recorded in the last 24 hours.', 'fa-chart-line'); ?>
                <?php else: ?>
                    <?php foreach ($evidence as $row): ?>
                        <tr>
                            <td class="u-fs-12"><?php echo (int) $row['id']; ?></td>
                            <td class="u-fs-12"><?php echo $h((string) $row['created_at']); ?></td>
                            <td><span class="badge <?php echo $levelBadge((string) $row['level']); ?> u-fs-11"><?php echo $h((string) $row['level']); ?></span></td>
                            <td><code class="u-fs-11"><?php echo $h(mb_substr((string) $row['call_id'], 0, 32)); ?></code></td>
                            <td class="u-fs-12"><?php echo $h((string) $row['trunk_name'] !== '' ? (string) $row['trunk_name'] : '—'); ?></td>
                            <td><code class="u-fs-11"><?php echo $h((string) $row['peer']); ?></code></td>
                            <td class="u-fs-12"><?php echo $h(sprintf('%.2f%%', (float) $row['loss_pct'])); ?></td>
                            <td class="u-fs-12"><?php echo (int) $row['reports']; ?></td>
                            <td class="u-fs-11"><?php echo $h((string) $row['action'] !== 'none' ? (string) $row['action'] : 'recorded only'); ?></td>
                            <td class="u-fs-11"><?php echo !empty($row['notified_at']) ? $h((string) $row['notified_at']) : '—'; ?></td>
                            <td class="u-fs-11">
                                <a href="/plugins/<?php echo $h((string) ($plugin['slug'] ?? 'call-quality')); ?>?evidence=<?php echo (int) $row['id']; ?>">trail</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="padding: 12px 20px; font-size: 12px; color: var(--text-muted);">
        One row per incident, not per RTCP report: the worst report block of a call, deduped by call and first report.
        Evidence older than <?php echo (int) CallQualityService::evidenceDays($settings); ?> days is pruned on every pass.
    </div>
</div>
