<?php
require_once 'yt_lib.php';
require_once 'ig_lib.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT * FROM jobs WHERE id=? AND user_id=?'); $q->execute([$id, $_SESSION['uid']]);
$job = $q->fetch();
if (!$job) { http_response_code(404); exit('Job nahi mila'); }
$clips = [];
if ($job['status'] === 'done') {
    $c = db()->prepare('SELECT * FROM clips WHERE job_id=? ORDER BY viral_score DESC'); $c->execute([$id]);
    $clips = $c->fetchAll();
}
$ytReady = yt_configured();
$ytConnected = $ytReady && yt_connected((int)$_SESSION['uid']);
$igAcc = ig_account((int)$_SESSION['uid']);
$igBase = ig_public_base();
$back = 'job.php?id=' . $id;
include 'header.php'; ?>
<h2><?= h($job['title'] ?: 'Video process ho rahi hai...') ?></h2>
<p class="muted"><?= h($job['youtube_url']) ?></p>

<?php if (in_array($job['status'], ['done'])): ?>
  <p class="ok"><?= count($clips) ?> viral clips ready hain 🎉</p>
  <?php if (strpos((string)$job['message'], 'Note:') === 0): ?><p class="muted">ℹ️ <?= h($job['message']) ?></p><?php endif; ?>

  <?php if (($_GET['yt'] ?? '') === 'ok'): ?><p class="ok">✅ YouTube connect ho gaya!</p>
  <?php elseif (($_GET['yt'] ?? '') === 'denied'): ?><p class="error">YouTube connect nahi hua (permission nahi di gayi).</p><?php endif; ?>

  <div class="card">
    <?php if (!$ytReady): ?>
      <b>▶️ YouTube upload</b> <span class="muted">— setup baaki hai. <code>google_config.php</code> mein Client ID/Secret bhariye (YOUTUBE_SETUP.md dekhiye).</span>
    <?php elseif (!$ytConnected): ?>
      <b>▶️ YouTube upload</b> — apna channel connect kijiye, phir clips seedha upload ho sakenge.<br>
      <a class="btn" href="yt_connect.php?back=<?= urlencode($back) ?>">Connect YouTube</a>
    <?php else: ?>
      <b>✅ YouTube connected</b>
      <form method="post" action="yt_disconnect.php" style="display:inline;margin-left:12px">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="back" value="<?= h($back) ?>">
        <button class="small" type="submit">Disconnect</button>
      </form>
      <p class="muted" style="margin-bottom:0">Dhyan rakhiye: Google ki unverified app se upload hui videos aksar <b>private</b> hi rehti hain, chahe aap Public chunein.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if (!$igAcc): ?>
      <b>📸 Instagram upload</b> — setup baaki hai. <a href="instagram.php">Instagram settings kholiye</a> (token daalna hai).
    <?php else: ?>
      <b>✅ Instagram connected: @<?= h($igAcc['username']) ?></b> · <a href="instagram.php">settings</a>
      <?php if ($igBase === ''): ?>
        <p class="error" style="margin-bottom:0">Public link set nahi hai: <code>google_config.php</code> mein <code>IG_PUBLIC_BASE_URL</code> daaliye (Instagram ko video ka public https link chahiye).</p>
      <?php else: ?>
        <p class="muted" style="margin-bottom:0">Reels 9:16 mein sabse achhi aati hain. Public link: <?= h($igBase) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="grid">
  <?php foreach ($clips as $i => $c): $ytId = $c['youtube_video_id'] ?? null; ?>
    <div class="card clip">
      <div class="badge">🔥 Viral Score: <?= (int)$c['viral_score'] ?>/100</div>
      <h3>#<?= $i + 1 ?> <?= h($c['title']) ?></h3>
      <p class="muted">Original video mein: <?= fmt_time($c['start_sec']) ?> – <?= fmt_time($c['end_sec']) ?></p>
      <video controls preload="metadata" src="<?= h($c['file_path']) ?>"></video>
      <?php if (!empty($c['thumb_path'])): ?>
        <label>🖼️ Thumbnail</label>
        <img src="<?= h($c['thumb_path']) ?>" alt="thumbnail" style="width:100%;border-radius:8px;background:#000">
        <a class="btn" href="<?= h($c['thumb_path']) ?>" download>⬇ Thumbnail download</a>
      <?php endif; ?>
      <p><b>Kyun viral?</b> <?= h($c['reason']) ?></p>
      <label>📸 Instagram Reels caption</label>
      <textarea readonly rows="4" id="ig<?= $c['id'] ?>"><?= h($c['caption_instagram']) ?></textarea>
      <button type="button" class="small" onclick="copyText('ig<?= $c['id'] ?>')">Copy</button>
      <label>▶️ YouTube Shorts title + description</label>
      <textarea readonly rows="4" id="yt<?= $c['id'] ?>"><?= h($c['caption_youtube']) ?></textarea>
      <button type="button" class="small" onclick="copyText('yt<?= $c['id'] ?>')">Copy</button>
      <p class="tags"><?= h($c['hashtags']) ?></p>
      <a class="btn" href="<?= h($c['file_path']) ?>" download>⬇ Download clip</a>

      <?php if ($ytConnected): ?>
        <div style="margin-top:14px;border-top:1px solid #262b36;padding-top:10px">
          <?php if ($ytId): ?>
            <p class="ok">✅ YouTube par upload hai: <a target="_blank" href="https://youtu.be/<?= h($ytId) ?>">https://youtu.be/<?= h($ytId) ?></a></p>
            <?php if (!empty($c['thumb_path'])): ?>
              <button type="button" class="small" onclick="ytThumb(<?= (int)$c['id'] ?>, this)">🖼️ Thumbnail YouTube par lagao</button>
              <p id="yt<?= $c['id'] ?>msg" class="muted"></p>
            <?php endif; ?>
          <?php else: ?>
            <label>Visibility</label>
            <select id="pv<?= $c['id'] ?>" onchange="toggleSchedule(<?= (int)$c['id'] ?>)">
              <option value="private">Private (safe)</option>
              <option value="unlisted">Unlisted</option>
              <option value="public">Public</option>
              <option value="schedule">⏰ Schedule (baad mein publish)</option>
            </select>
            <input type="datetime-local" id="sc<?= $c['id'] ?>" style="display:none;margin-top:8px">
            <button type="button" id="ub<?= $c['id'] ?>" onclick="ytUpload(<?= (int)$c['id'] ?>, this)">⬆ YouTube par upload karo</button>
            <p id="ys<?= $c['id'] ?>" class="muted"></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($igAcc): $igUrl = $c['instagram_url'] ?? null; $igId = $c['instagram_media_id'] ?? null; ?>
        <div style="margin-top:14px;border-top:1px solid #262b36;padding-top:10px">
          <?php if ($igId): ?>
            <p class="ok">✅ Instagram par post hai<?php if ($igUrl): ?>: <a target="_blank" href="<?= h($igUrl) ?>"><?= h($igUrl) ?></a><?php endif; ?></p>
          <?php else: ?>
            <button type="button" onclick="igUpload(<?= (int)$c['id'] ?>, this)">📸 Instagram Reel post karo</button>
            <p id="is<?= $c['id'] ?>" class="muted"></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php elseif ($job['status'] === 'failed'): ?>
  <p class="error">Process fail ho gaya: <?= h($job['error']) ?></p>
  <a class="btn" href="index.php">Dobara try kijiye</a>
<?php else: ?>
  <div class="card">
    <div class="bar"><div class="fill" id="fill" style="width:<?= (int)$job['progress'] ?>%"></div></div>
    <p id="msg"><?= h($job['message']) ?></p>
    <p class="muted">Video lambi ho to 5–15 minute lag sakte hain. Page band mat kijiye.</p>
  </div>
  <script>
  const id = <?= $id ?>;
  async function poll() {
    try {
      const r = await fetch('status.php?id=' + id); const d = await r.json();
      document.getElementById('fill').style.width = d.progress + '%';
      document.getElementById('msg').textContent = d.message || '';
      if (d.status === 'done' || d.status === 'failed') { location.reload(); return; }
    } catch (e) {}
    setTimeout(poll, 3000);
  }
  poll();
  </script>
<?php endif; ?>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
function copyText(id){const t=document.getElementById(id);t.select();navigator.clipboard.writeText(t.value);}
async function igUpload(clipId, btn) {
  const st = document.getElementById('is' + clipId);
  btn.disabled = true;
  st.textContent = '⏳ Instagram par ja raha hai... (Instagram video process karta hai, 1–5 minute lag sakte hain, page band mat kijiye)';
  const fd = new FormData(); fd.append('clip_id', clipId); fd.append('csrf', CSRF);
  try {
    const r = await fetch('ig_upload.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      st.textContent = '✅ Instagram par post ho gaya';
      if (d.url) { st.appendChild(document.createTextNode(': ')); const a = document.createElement('a'); a.href = d.url; a.target = '_blank'; a.textContent = d.url; st.appendChild(a); }
      btn.style.display = 'none';
    } else { st.textContent = '❌ ' + (d.error || 'Error'); btn.disabled = false; }
  } catch (e) { st.textContent = '❌ Network error, dobara try kijiye'; btn.disabled = false; }
}
async function ytThumb(clipId, btn) {
  const out = document.getElementById('yt' + clipId + 'msg');
  btn.disabled = true; out.textContent = '⏳ Thumbnail upload ho raha hai...';
  const fd = new FormData(); fd.append('clip_id', clipId); fd.append('csrf', CSRF);
  try {
    const r = await fetch('yt_thumbnail.php', { method: 'POST', body: fd });
    const d = await r.json();
    out.textContent = d.message ? ((d.ok ? '✅ ' : '❌ ') + d.message.replace(/^(OK|FAIL): /, '')) : ('❌ ' + (d.error || 'Error'));
  } catch (e) { out.textContent = '❌ Network error'; }
  btn.disabled = false;
}
function toggleSchedule(clipId) {
  const show = document.getElementById('pv' + clipId).value === 'schedule';
  document.getElementById('sc' + clipId).style.display = show ? 'block' : 'none';
}
async function ytUpload(clipId, btn) {
  const st = document.getElementById('ys' + clipId);
  const privacy = document.getElementById('pv' + clipId).value;
  const fd = new FormData();
  if (privacy === 'schedule') {
    const v = document.getElementById('sc' + clipId).value;
    if (!v) { st.textContent = '⚠️ Pehle schedule ki date aur time chuniye'; return; }
    fd.append('publish_at', new Date(v).toISOString());  // aapke local time se UTC
  }
  btn.disabled = true;
  st.textContent = '⏳ Upload ho raha hai... (1–3 minute lag sakte hain, page band mat kijiye)';
  fd.append('clip_id', clipId); fd.append('privacy', privacy); fd.append('csrf', CSRF);
  try {
    const r = await fetch('yt_upload.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      st.textContent = '✅ Upload ho gaya (' + d.privacy + '): ';
      const a = document.createElement('a');
      a.href = d.url; a.target = '_blank'; a.textContent = d.url;
      st.appendChild(a);
      if (d.thumbnail) st.appendChild(document.createTextNode('  |  🖼️ ' + d.thumbnail.replace(/^(OK|FAIL): /, (m, k) => k === 'OK' ? '✅ ' : '❌ ')));
      btn.style.display = 'none';
      document.getElementById('pv' + clipId).style.display = 'none';
      document.getElementById('sc' + clipId).style.display = 'none';
    } else {
      st.textContent = '❌ ' + (d.error || 'Error');
      btn.disabled = false;
    }
  } catch (e) {
    st.textContent = '❌ Network error, dobara try kijiye';
    btn.disabled = false;
  }
}
</script>
<?php include 'footer.php'; ?>
