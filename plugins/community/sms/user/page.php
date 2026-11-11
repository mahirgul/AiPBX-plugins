<?php
/**
 * /apps/sms: the conversations of the user's numbers, one opened, and a reply. ?n=<number id>&peer=<+number>
 * Available: $slug, $settings, $user, $csrf_token.
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$numbers = [];
foreach (Sms::numbersFor($user) as $n) {
    $numbers[(int) $n['id']] = $n;
}
$nid = (int) ($_GET['n'] ?? array_key_first($numbers));
$number = $numbers[$nid] ?? reset($numbers);
$nid = (int) $number['id'];
$peer = SmsProvider::e164((string) ($_GET['peer'] ?? ''));
$threads = Sms::threads([$nid]);
$messages = $peer !== '' ? Sms::thread($nid, $peer) : [];
$link = static fn(int $n, string $p = ''): string => '/apps/sms?' . http_build_query(array_filter(['n' => $n, 'peer' => $p]));
$numberName = static fn(array $n): string => $n['number'] . ($n['label'] !== '' ? ' · ' . $n['label'] : '');
$contacts = Sms::contacts($nid);

echo uiPageHeader('fas fa-comment-sms', 'Messages');
?>
<style>
/* The plugin's own few rules; colours come from the portal's theme, so dark mode follows. */
.sms-msgs { display: flex; flex-direction: column; gap: 8px; max-height: 60vh; overflow-y: auto; margin-bottom: 12px; }
.sms-msg { max-width: 80%; padding: 8px 12px; border-radius: var(--radius-md); background: var(--bg-subtle); overflow-wrap: anywhere; }
.sms-out { align-self: flex-end; background: var(--info-soft); }
.sms-meta { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.sms-img { display: block; max-width: 100%; max-height: 220px; margin-top: 6px; border-radius: var(--radius-sm); }
</style>
<?php if (count($numbers) > 1): ?>
<div class="u-flex-gap u-mb-20">
    <?php foreach ($numbers as $n): ?>
        <a class="btn <?php echo (int) $n['id'] === $nid ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo $h($link((int) $n['id'])); ?>"><?php echo $h($numberName($n)); ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="u-grid-2">
    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-comments"></i> <?php echo $h($numberName($number)); ?></div></div>
        <div class="u-p-16">
            <form method="get" action="/apps/sms" class="u-flex-gap u-mb-20">
                <input type="hidden" name="n" value="<?php echo $nid; ?>">
                <input type="text" name="peer" class="form-control" placeholder="New: +15551234567" list="smsContacts" required>
                <datalist id="smsContacts"><?php foreach ($contacts as $phone => $name): ?><option value="<?php echo $h($phone); ?>"><?php echo $h($name); ?></option><?php endforeach; ?></datalist>
                <button type="submit" class="btn btn-primary"><i class="fas fa-pen"></i> Write</button>
            </form>
            <div id="smsThreads">
            <?php echo uiTableOpen([], ['search' => false]); ?>
            <?php if ($threads === []): echo uiTableEmptyRow(1, 'No messages yet.'); endif; ?>
            <?php foreach ($threads as $t): ?>
                <tr<?php echo $t['peer'] === $peer ? ' class="active"' : ''; ?>>
                    <td>
                        <a href="<?php echo $h($link($nid, (string) $t['peer'])); ?>"><strong><?php echo $h($contacts[$t['peer']] ?? $t['peer']); ?></strong></a>
                        <?php if (isset($contacts[$t['peer']])): ?><span class="u-muted"><?php echo $h($t['peer']); ?></span><?php endif; ?>
                        <?php if ((int) $t['unread'] > 0): ?><span class="badge badge-success"><?php echo (int) $t['unread']; ?></span><?php endif; ?>
                        <div class="u-muted"><?php echo $h(($t['direction'] === 'out' ? '→ ' : '') . mb_strimwidth((string) $t['body'], 0, 70, '…')); ?></div>
                    </td>
                    <td class="u-nowrap u-muted"><?php echo $h($t['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title"><i class="fas fa-user"></i> <?php echo $h($peer === '' ? 'Choose a conversation' : (isset($contacts[$peer]) ? $contacts[$peer] . ' · ' . $peer : $peer)); ?></div></div>
        <div class="u-p-16">
            <?php if ($peer !== ''): ?>
                <form method="post" class="u-flex-gap u-mb-20">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="sms_action" value="contact">
                    <input type="hidden" name="number_id" value="<?php echo $nid; ?>">
                    <input type="hidden" name="peer" value="<?php echo $h($peer); ?>">
                    <input type="text" name="name" class="form-control" maxlength="80" value="<?php echo $h($contacts[$peer] ?? ''); ?>" placeholder="Name for this number">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-address-book"></i> Save name</button>
                </form>
                <div class="sms-msgs" id="smsMsgs">
                <?php foreach ($messages as $m): ?>
                    <div class="sms-msg<?php echo $m['direction'] === 'out' ? ' sms-out' : ''; ?>">
                        <?php if ($m['body'] !== ''): ?><div><?php echo nl2br($h($m['body'])); ?></div><?php endif; ?>
                        <?php foreach ($m['media'] as $media):
                            $url = Sms::mediaUrl($media);
                            if (str_starts_with((string) $media['content_type'], 'image/')): ?>
                                <a href="<?php echo $h($url); ?>" target="_blank" rel="noopener"><img class="sms-img" src="<?php echo $h($url); ?>" alt=""></a>
                            <?php else: ?>
                                <a href="<?php echo $h($url); ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?php echo $h($media['name'] ?: $media['content_type']); ?></a>
                            <?php endif;
                        endforeach; ?>
                        <div class="sms-meta">
                            <?php echo $h($m['created_at']); ?>
                            <?php if ($m['direction'] === 'out'): ?> · <?php echo $h($m['status']); ?><?php endif; ?>
                            <?php if ($m['error'] !== ''): ?><div class="text-danger"><?php echo $h($m['error']); ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <form method="post" enctype="multipart/form-data" class="u-mt-10" id="smsSendForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="sms_action" value="send">
                    <input type="hidden" name="number_id" value="<?php echo $nid; ?>">
                    <input type="hidden" name="peer" value="<?php echo $h($peer); ?>">
                    <div class="form-group">
                        <textarea name="text" id="smsText" class="form-control" rows="3" maxlength="1600" placeholder="Message"></textarea>
                    </div>
                    <div class="form-group">
                        <input type="file" name="media[]" multiple accept="image/*,video/*,audio/*">
                        <small class="u-hint">Up to <?php echo Sms::MAX_MEDIA; ?> pictures, 5 MB each; with pictures the message goes as MMS.</small>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn btn-primary" id="smsSendButton"><i class="fas fa-paper-plane"></i> Send</button></div>
                </form>
            <?php else: ?>
                <p class="u-muted">Pick a conversation on the left, or write a new one.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
// New messages appear by themselves: every 5 s the page asks for the newest id, and when it changed
// it fetches itself and swaps in the conversation list and the messages; a reply being written stays.
(function () {
    const form = document.getElementById('smsSendForm');
    const sendButton = document.getElementById('smsSendButton');
    if (form && sendButton) {
        const idleLabel = sendButton.innerHTML;
        let sending = false;
        form.addEventListener('submit', function (event) {
            if (sending) { event.preventDefault(); return; }
            sending = true;
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Sending…';
            form.setAttribute('aria-busy', 'true');
        });
        // A failed POST renders a fresh form. Back/forward cache restores this one.
        window.addEventListener('pageshow', function () {
            sending = false;
            sendButton.disabled = false;
            sendButton.innerHTML = idleLabel;
            form.removeAttribute('aria-busy');
        });
    }
    const box = document.getElementById('smsMsgs');
    if (box) { box.scrollTop = box.scrollHeight; }
    let last = <?php echo Sms::lastId(array_keys($numbers)); ?>;
    const swap = (doc, id) => {
        const now = document.getElementById(id), fresh = doc.getElementById(id);
        if (now && fresh) { now.innerHTML = fresh.innerHTML; }
    };
    setInterval(function () {
        if (document.hidden) { return; }
        const body = new URLSearchParams({ csrf_token: <?php echo json_encode($csrf_token); ?>, sms_action: 'poll' });
        fetch(location.href, { method: 'POST', body: body, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((d) => {
                if (!(d.last > last)) { return; }
                last = d.last;
                return fetch(location.href, { credentials: 'same-origin' }).then((r) => r.text()).then((html) => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const atEnd = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 40;
                    swap(doc, 'smsThreads');
                    swap(doc, 'smsMsgs');
                    if (box && atEnd) { box.scrollTop = box.scrollHeight; }
                });
            })
            .catch(() => {});
    }, 5000);
})();
</script>
