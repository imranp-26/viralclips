<?php
require_once __DIR__ . '/ig_lib.php';
require_login();
$uid = (int)$_SESSION['uid'];
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if (!function_exists('curl_init')) throw new Exception('PHP curl extension band hai (php.ini mein extension=curl)');
        if ($action === 'save') {
            $token = trim((string)($_POST['token'] ?? ''));
            if ($token === '') throw new Exception('Token paste kijiye');
            $me = ig_me($token);   // token sahi hai to account ki id + username mil jayega
            db()->prepare('REPLACE INTO instagram_accounts(user_id, ig_user_id, username, access_token, saved_at, refreshed_at) VALUES(?,?,?,?,NOW(),NULL)')
                ->execute([$uid, $me['user_id'], $me['username'], $token]);
            $msg = '✅ Instagram connect ho gaya: @' . $me['username'];
        } elseif ($action === 'refresh') {
            $acc = ig_account($uid);
            if (!$acc) throw new Exception('Pehle token save kijiye');
            $new = ig_refresh($acc['access_token']);
            db()->prepare('UPDATE instagram_accounts SET access_token=?, refreshed_at=NOW() WHERE user_id=?')->execute([$new, $uid]);
            $msg = '✅ Token 60 din ke liye aur badh gaya.';
        } elseif ($action === 'disconnect') {
            db()->prepare('DELETE FROM instagram_accounts WHERE user_id=?')->execute([$uid]);
            $msg = 'Instagram disconnect ho gaya.';
        }
    } catch (Exception $e) { $err = $e->getMessage(); }
}
$acc = ig_account($uid);
$base = ig_public_base();
include 'header.php'; ?>
<h2>📸 Instagram settings</h2>
<?php if ($msg): ?><p class="ok"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<div class="card">
  <h3 style="margin-top:0">Status</h3>
  <?php if ($acc): ?>
    <p class="ok">✅ Connected: <b>@<?= h($acc['username']) ?></b> <span class="muted">(id <?= h($acc['ig_user_id']) ?>)</span></p>
    <p class="muted">Token save hua: <?= h($acc['saved_at']) ?><?= $acc['refreshed_at'] ? ' · refresh: ' . h($acc['refreshed_at']) : '' ?>. Token 60 din chalta hai, upload ke waqt apne aap badhne ki koshish hoti hai.</p>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="refresh"><button class="small">🔄 Token refresh</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="disconnect"><button class="small">Disconnect</button></form>
  <?php else: ?>
    <p class="muted">Abhi connect nahi hai.</p>
  <?php endif; ?>
  <p><b>Public link:</b>
    <?php if ($base): ?><span class="ok"><?= h($base) ?></span>
    <?php else: ?><span class="error">set nahi hai</span> — <code>google_config.php</code> mein <code>define('IG_PUBLIC_BASE_URL', 'https://aapka-tunnel.example/viralclips');</code> daaliye.<?php endif; ?></p>
</div>

<div class="card">
  <h3 style="margin-top:0">Token daaliye</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="save">
    <label>Instagram access token (Meta dashboard se)</label>
    <input type="password" name="token" autocomplete="off" placeholder="IGAA...">
    <button>Save aur check karo</button>
  </form>
  <p class="muted">Poore steps: <code>INSTAGRAM_SETUP.md</code>. Token kisi ko mat bhejiye.</p>
</div>
<?php include 'footer.php'; ?>
