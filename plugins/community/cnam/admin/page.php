<?php

declare(strict_types=1);

/**
 * /plugins/cnam management page:
 * - Cache statistics & cleanup
 * - Interactive test lookup
 * - Provider status overview
 */
require_once dirname(__DIR__) . '/lib/Cnam.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$db = getDB();
$stats = Cnam::getStats($db);

$providerId = (string) ($settings['provider'] ?? 'bulkvs');
$provider = Cnam::provider($providerId, $settings);
$isConfigured = $provider ? $provider->configured() : ($providerId === 'phonebook_only');
?>

<div class="row u-mb-20">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-database"></i> CNAM Cache & Statistics</div>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Inbound lookups are cached locally to minimize external API requests and reduce provider costs.
                </p>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span><strong>Active Cached Numbers:</strong></span>
                    <span class="badge bg-success fs-6"><?php echo number_format($stats['active']); ?></span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span><strong>Total Cached Records:</strong></span>
                    <span><?php echo number_format($stats['total']); ?></span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span><strong>Cache Hits (Saved API Calls):</strong></span>
                    <span class="badge bg-primary fs-6"><?php echo number_format($stats['total_hits']); ?></span>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="cnam_action" value="purge_expired">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-broom"></i> Purge Expired
                        </button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Clear entire CNAM cache? Subsequent lookups will query external APIs again.');">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="cnam_action" value="clear_cache">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-trash"></i> Clear All Cache
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-vial"></i> Test CNAM Lookup</div>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Test your provider credentials and phonebook resolution with a sample phone number.
                </p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="cnam_action" value="test_lookup">
                    <div class="mb-3">
                        <label class="form-label" for="test_number">Phone Number (10/11 digits or E.164):</label>
                        <input type="text" id="test_number" name="test_number" class="form-control" placeholder="+12125551234 or 12125551234" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Test Lookup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-network-wired"></i> Provider Status & Dialplan Integration</div>
    </div>
    <div class="card-body">
        <div class="d-flex align-items-center mb-3">
            <span class="me-3"><strong>Active Provider:</strong> <?php echo $h(ucfirst($providerId)); ?></span>
            <?php if ($isConfigured): ?>
                <span class="badge bg-success"><i class="fas fa-check-circle"></i> Configured & Ready</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> Credentials Required</span>
            <?php endif; ?>
        </div>
        <p class="small text-muted mb-1">
            <strong>Inbound Dialplan Hook:</strong> When enabled, AiPBX automatically invokes <code>sub-cnam-lookup</code>
            on all inbound trunk calls. The lookup runs with a strict 2-second timeout and fails open so calls are never delayed or dropped.
        </p>
        <p class="small text-muted mb-0">
            <strong>Internal HTTP Endpoint:</strong> <code>http://127.0.0.1/api/plugins/cnam/lookup?num=${CALLERID(num)}</code>
        </p>
    </div>
</div>
