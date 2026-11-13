<?php
require_once 'config.php';
require_login();
include 'header.php'; ?>
<div class="tabs">
  <button type="button" class="tab active" data-tab="link">🔗 Link daaliye</button>
  <button type="button" class="tab" data-tab="trending">🔥 Trending videos</button>
</div>

<div id="panel-link">
<div class="card">
  <h1>YouTube video ka link daaliye</h1>
  <p class="muted">AI us video ke sabse viral moments dhoondhega, clips kaat dega, aur Instagram + YouTube Shorts ke liye captions bhi dega.</p>
  <?php if (isset($_GET['err'])): ?><p class="error"><?= h($_GET['err']) ?></p><?php endif; ?>
  <form method="post" action="submit.php">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <label>YouTube URL</label>
    <input type="url" id="urlInput" name="url" placeholder="https://www.youtube.com/watch?v=..." required>
    <div class="row">
      <div><label>Kitne clips chahiye?</label>
        <select name="max_clips"><?php for ($i = 3; $i <= MAX_CLIPS_LIMIT; $i++): ?><option value="<?= $i ?>" <?= $i == 5 ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
      <div><label>Clip ka size</label>
        <select name="aspect">
          <option value="9:16">Vertical 9:16 (Reels / Shorts)</option>
          <option value="1:1">Square 1:1 (Instagram / Facebook post)</option>
          <option value="16:9">Original 16:9 (YouTube)</option>
        </select></div>
    </div>
    <label>Framing (9:16 aur 1:1 ke liye)</label>
    <select name="fit_mode">
      <option value="bars" selected>Reference style (upar-neeche kali patti, 9:16 ke liye)</option>
      <option value="crop">Crop (screen bhar jaye, side kat sakti hai)</option>
      <option value="blur">Blur background (poori video dikhe)</option>
    </select>
    <label style="display:flex;gap:10px;align-items:center;margin-top:14px">
      <input type="checkbox" name="captions" value="1" checked style="width:auto"> Video par captions (bolte waqt screen par text, jis word par bol rahe wo orange)
    </label>
    <label style="display:flex;gap:10px;align-items:center">
      <input type="checkbox" name="smart_frame" value="1" checked style="width:auto"> Smart framing (AI bolne wale ko follow kare, do log hon to speaker par cut)
    </label>
    <label style="display:flex;gap:10px;align-items:center">
      <input type="checkbox" name="title_hook" value="1" checked style="width:auto"> Upar hook title dikhao
    </label>
    <label style="display:flex;gap:10px;align-items:center">
      <input type="checkbox" name="credit_line" value="1" checked style="width:auto"> Neeche "CREDIT:- original channel ka naam" dikhao
    </label>
    <label>Caption ki bhasha</label>
    <select name="language">
      <option value="hindi" selected>हिन्दी (Devanagari)</option>
      <option value="hinglish">Hinglish (Roman)</option>
      <option value="english">English</option>
      <option value="same">Video jaisi hi</option>
    </select>
    <label>Video ki awaaz (audio)</label>
    <select name="audio_lang">
      <option value="hi" selected>Hindi (agar video mein Hindi audio ho)</option>
      <option value="original">Original awaaz</option>
    </select>
    <button>Viral Clips Banao 🚀</button>
  </form>
</div>
</div>

<div id="panel-trending" style="display:none">
  <div class="card">
    <h2 style="margin-top:0">🔥 Famous YouTubers ke latest aur trending videos</h2>
    <p class="muted">Kisi bhi video par <b>"Clips banao"</b> dabaiye, uska link apne aap form mein bhar jayega.</p>
    <div class="row3">
      <div><label>Kaise dikhayein</label>
        <select id="trSort">
          <option value="trending">🔥 Trending (views per hour)</option>
          <option value="latest">🆕 Latest (sabse naye)</option>
          <option value="views">👁️ Sabse zyada views</option>
        </select></div>
      <div><label>YouTuber</label><select id="trCreator"><option value="all">Sab</option></select></div>
      <div><label style="display:flex;gap:8px;align-items:center;margin-top:34px"><input type="checkbox" id="trShorts" style="width:auto"> Shorts bhi dikhao</label></div>
    </div>
    <button type="button" class="small" id="trRefresh">🔄 Refresh</button>
    <span class="muted" id="trInfo" style="margin-left:10px"></span>
    <p id="trMsg" class="muted"></p>
    <div id="trKeyHelp" class="card" style="display:none">
      <b>YouTube API key lagani hai (ek baar, free):</b>
      <ol>
        <li>Google Cloud Console mein wahi <b>viralclips</b> project kholiye (jisme YouTube Data API v3 Enable hai).</li>
        <li><b>APIs &amp; Services &gt; Credentials &gt; Create credentials &gt; API key</b>. Key copy kijiye.</li>
        <li><code>C:\xampp\htdocs\viralclips\google_config.php</code> mein ek line jodiye:<br>
          <code>define('YOUTUBE_API_KEY', 'aapki-key');</code></li>
        <li>Page refresh kijiye. Key kisi ko mat bhejiye.</li>
      </ol>
    </div>
    <div id="trGrid" class="vgrid"></div>
  </div>
</div>

<script>
(function () {
  const tabs = document.querySelectorAll('.tab');
  const panels = { link: document.getElementById('panel-link'), trending: document.getElementById('panel-trending') };
  let loaded = false;
  function showTab(name) {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    Object.keys(panels).forEach(k => panels[k].style.display = (k === name ? 'block' : 'none'));
    if (name === 'trending' && !loaded) { loaded = true; load(false); }
  }
  tabs.forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab)));

  const grid = document.getElementById('trGrid'), msg = document.getElementById('trMsg'), info = document.getElementById('trInfo');
  const sortEl = document.getElementById('trSort'), creatorEl = document.getElementById('trCreator'), shortsEl = document.getElementById('trShorts');
  let creatorsFilled = false;

  const fmtNum = n => n >= 1e7 ? (n / 1e7).toFixed(1) + ' Cr' : n >= 1e5 ? (n / 1e5).toFixed(1) + ' L' : n >= 1e3 ? (n / 1e3).toFixed(1) + 'K' : String(n);
  const fmtAge = h => h < 1 ? 'abhi abhi' : h < 24 ? Math.round(h) + ' ghante pehle' : Math.round(h / 24) + ' din pehle';
  const fmtDur = s => { const m = Math.floor(s / 60), r = s % 60; return m >= 60 ? Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0') + ':' + String(r).padStart(2, '0') : m + ':' + String(r).padStart(2, '0'); };

  function card(v) {
    const d = document.createElement('div'); d.className = 'vcard';
    const img = document.createElement('img'); img.loading = 'lazy'; img.alt = '';
    if (/^https:\/\/i\.ytimg\.com\//.test(v.thumb)) img.src = v.thumb;
    const wrap = document.createElement('div'); wrap.className = 'vthumb'; wrap.appendChild(img);
    const dur = document.createElement('span'); dur.className = 'vdur'; dur.textContent = fmtDur(v.duration); wrap.appendChild(dur);
    const t = document.createElement('div'); t.className = 'vtitle'; t.textContent = v.title;
    const m = document.createElement('div'); m.className = 'muted vmeta';
    m.textContent = v.creator + ' · ' + fmtNum(v.views) + ' views · ' + fmtAge(v.age_hours);
    const hot = document.createElement('div'); hot.className = 'vhot'; hot.textContent = '🔥 ' + fmtNum(v.vph) + ' views/ghanta';
    const b = document.createElement('button'); b.type = 'button'; b.textContent = '✂️ Clips banao';
    b.addEventListener('click', () => {
      document.getElementById('urlInput').value = 'https://www.youtube.com/watch?v=' + v.id;
      showTab('link'); window.scrollTo({ top: 0, behavior: 'smooth' }); document.getElementById('urlInput').focus();
    });
    d.append(wrap, t, m, hot, b);
    return d;
  }

  async function load(refresh) {
    msg.textContent = '⏳ Videos la rahe hain...'; grid.innerHTML = '';
    document.getElementById('trKeyHelp').style.display = 'none';
    const qs = new URLSearchParams({ sort: sortEl.value, creator: creatorEl.value, shorts: shortsEl.checked ? 1 : 0 });
    if (refresh) qs.set('refresh', 1);
    try {
      const r = await fetch('trending.php?' + qs.toString()); const d = await r.json();
      if (d.needs_key) { msg.textContent = '⚠️ ' + d.error; document.getElementById('trKeyHelp').style.display = 'block'; return; }
      if (!d.ok) { msg.textContent = '❌ ' + (d.error || 'Error'); return; }
      if (!creatorsFilled) {
        d.creators.forEach(c => { const o = document.createElement('option'); o.value = c.name; o.textContent = c.name + ' (' + c.group + ')'; creatorEl.appendChild(o); });
        creatorsFilled = true;
      }
      msg.textContent = d.warnings.length ? '⚠️ ' + d.warnings.join(' | ') : '';
      info.textContent = d.fetched_at ? 'Data: ' + new Date(d.fetched_at * 1000).toLocaleString() : '';
      if (!d.videos.length) { msg.textContent += ' Koi video nahi mila.'; return; }
      d.videos.forEach(v => grid.appendChild(card(v)));
    } catch (e) { msg.textContent = '❌ Network error'; }
  }
  [sortEl, creatorEl, shortsEl].forEach(el => el.addEventListener('change', () => load(false)));
  document.getElementById('trRefresh').addEventListener('click', () => load(true));
})();
</script>
<?php include 'footer.php'; ?>
