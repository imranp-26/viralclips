"""
ViralClips AI - background worker
Usage: python worker.py <job_id>

Steps:
 1. Video download (yt-dlp) + YouTube "Most replayed" heatmap
 2. Transcript (youtube-transcript-api, fallback: faster-whisper)
 3. AI analysis (Google Gemini - free tier) -> viral moments + captions + hashtags (JSON)
 4. Clips cut (ffmpeg), optional 9:16 vertical
 5. Results MySQL mein save
"""
import base64
import faulthandler
import json
import os
import re
import subprocess
import sys
import time
import traceback
import urllib.error
import urllib.request
from pathlib import Path

import mysql.connector
import yt_dlp
from dotenv import load_dotenv

faulthandler.enable()  # agar Python crash ho to wajah screen par dikhe

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
load_dotenv(HERE / ".env")

FFMPEG = os.getenv("FFMPEG_BIN", "ffmpeg")
# Comma se alag kiye gaye models: pehla fail ho (404/quota) to agla try hoga
GEMINI_MODELS = os.getenv("GEMINI_MODEL", "gemini-3.8-flash,gemini-3.6-flash,gemini-3.5-flash-lite")
MIN_CLIP, MAX_CLIP = 15, 75  # seconds


# ---------- DB helpers ----------
def get_db():
    return mysql.connector.connect(
        host=os.getenv("DB_HOST", "localhost"),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASS", ""),
        database=os.getenv("DB_NAME", "viralclips"),
        use_pure=True,  # C extension naye Python par crash kar sakta hai
    )


def update(job_id, **fields):
    db = get_db()
    cur = db.cursor()
    sets = ", ".join(f"{k}=%s" for k in fields)
    cur.execute(f"UPDATE jobs SET {sets} WHERE id=%s", (*fields.values(), job_id))
    db.commit()
    db.close()


# ---------- Step 1: download ----------
def pick_hindi_audio(info):
    """Hindi audio track ka exact format_id (m4a ko priority). Na mile to None."""
    best = None
    for f in info.get("formats") or []:
        lang = str(f.get("language") or "").lower()
        if f.get("vcodec") == "none" and f.get("acodec") not in (None, "none") and lang.startswith("hi"):
            key = (f.get("ext") == "m4a", f.get("abr") or f.get("tbr") or 0)
            if best is None or key > best[0]:
                best = (key, f["format_id"])
    return best[1] if best else None


def download(url, job_id, audio_lang="hi"):
    """Return: info, video_path, hindi_missing (True agar Hindi chahiye thi par video mein nahi mili)"""
    out_tpl = str(ROOT / "storage" / "videos" / f"job{job_id}.%(ext)s")
    hindi_id = None
    if audio_lang == "hi":
        with yt_dlp.YoutubeDL({"noplaylist": True, "quiet": True}) as ydl:
            probe = ydl.extract_info(url, download=False)
        hindi_id = pick_hindi_audio(probe)
        print("[worker] Hindi audio format:", hindi_id or "video mein nahi mili", flush=True)

    if hindi_id:
        fmt = (f"bestvideo[height<=720][ext=mp4]+{hindi_id}/bestvideo[height<=720]+{hindi_id}/"
               "bestvideo[height<=720][ext=mp4]+bestaudio[ext=m4a]/best[height<=720]")
    else:
        fmt = "bestvideo[height<=720][ext=mp4]+bestaudio[ext=m4a]/best[height<=720][ext=mp4]/best[height<=720]"

    opts = {
        "format": fmt,
        "outtmpl": out_tpl,
        "merge_output_format": "mp4",
        "noplaylist": True,
        "quiet": True,
    }
    with yt_dlp.YoutubeDL(opts) as ydl:
        info = ydl.extract_info(url, download=True)
    video_path = ROOT / "storage" / "videos" / f"job{job_id}.mp4"
    if not video_path.exists():  # koi aur extension aaya ho to
        cands = list((ROOT / "storage" / "videos").glob(f"job{job_id}.*"))
        if not cands:
            raise RuntimeError("Video download nahi ho payi")
        video_path = cands[0]
    return info, video_path, (audio_lang == "hi" and not hindi_id)


def heatmap_peaks(info, top_n=8):
    """YouTube ka 'Most replayed' graph - jahan log sabse zyada dobara dekhte hain."""
    hm = info.get("heatmap") or []
    if not hm:
        return []
    ranked = sorted(hm, key=lambda x: x["value"], reverse=True)
    peaks = []
    for seg in ranked:
        mid = (seg["start_time"] + seg["end_time"]) / 2
        if all(abs(mid - p["time"]) > 25 for p in peaks):
            peaks.append({"time": int(mid), "intensity": round(seg["value"], 2)})
        if len(peaks) >= top_n:
            break
    return sorted(peaks, key=lambda p: p["time"])


# ---------- Step 2: transcript ----------
def get_transcript(video_id, video_path):
    try:
        from youtube_transcript_api import YouTubeTranscriptApi

        api = YouTubeTranscriptApi()
        try:
            tr = api.fetch(video_id, languages=["en", "hi", "en-IN", "hi-IN"])
        except Exception:
            tlist = api.list(video_id)
            tr = next(iter(tlist)).fetch()
        return [(float(s.start), s.text.replace("\n", " ")) for s in tr]
    except Exception as e:
        print("Transcript API fail:", e)

    # Fallback: faster-whisper (local speech-to-text)
    try:
        from faster_whisper import WhisperModel

        model = WhisperModel("base", compute_type="int8")
        segs, _ = model.transcribe(str(video_path))
        return [(float(s.start), s.text.strip()) for s in segs]
    except Exception as e:
        raise RuntimeError(
            "Transcript nahi mil paya. Video mein subtitles nahi hain aur faster-whisper installed nahi hai "
            "(pip install faster-whisper). Detail: " + str(e)
        )


def transcript_to_text(segments, limit=70000):
    lines, last_bucket = [], -1
    for start, text in segments:
        bucket = int(start // 10)  # har ~10 sec ka ek line
        stamp = f"[{int(start)//60:02d}:{int(start)%60:02d}|{int(start)}s] "
        if bucket != last_bucket:
            lines.append(stamp + text)
            last_bucket = bucket
        else:
            lines[-1] += " " + text
    txt = "\n".join(lines)
    return txt[:limit]


# ---------- Step 3: AI analysis ----------
PROMPT = """Aap ek expert short-form video editor aur social media strategist ho (Instagram Reels, YouTube Shorts).

Video title: {title}
Duration: {duration} seconds
YouTube "Most replayed" peaks (audience ne sabse zyada dobara dekha): {peaks}

Transcript (timestamps ke saath):
{transcript}

TASK: Is video ke sabse {n} viral-potential wale moments chuno jo Instagram Reels aur YouTube Shorts par chalenge.

Rules:
- Har clip {minc}-{maxc} seconds ka ho, aur ek complete thought/story par khatam ho (beech vaakya mein na kaato).
- Pehle 3 second mein strong hook ho (funny, shocking, emotional, surprising fact, strong opinion, ya cliffhanger).
- Agar "Most replayed" peaks diye hain to unke aaspaas ke moments ko priority do.
- Clips overlap na karein. start/end seconds mein do aur 0 <= start < end <= {duration}.
- {lang_rule}

SIRF valid JSON return karo, koi extra text ya markdown nahi:
{{"clips":[{{
  "start": <int seconds>, "end": <int seconds>,
  "title": "<catchy clip title, max 60 chars>",
  "viral_score": <int 0-100>,
  "reason": "<1-2 line: ye moment kyun viral hoga>",
  "caption_instagram": "<hook line + 2-3 line caption + CTA + emojis>",
  "caption_youtube": "<Shorts title (max 70 chars) newline phir 1-2 line description>",
  "hashtags": ["#tag1", "#tag2", "... 8-12 relevant tags"],
  "thumbnail_text": "<thumbnail par bada text: MAX 4 shabd, dhamakedaar hook, upar di gayi bhasha mein>",
  "thumbnail_text_roman": "<wahi thumbnail text Roman/English letters mein, max 4 words>",
  "thumbnail_accent": "<thumbnail_text ka sabse zaroori EK word (jo peela dikhega), text mein jaisa likha hai waisa>",
  "thumbnail_badge": "<clip mein bola gaya koi bada number/price/score jaise Rs35 ya 40,000 (max 8 characters), na ho to khali string>"
}}]}}"""


THINK_OPTS = [{"thinkingBudget": 0}, {"thinkingLevel": "minimal"}, None]


def call_gemini(prompt, audio_b64=None, audio_mime="audio/wav", temperature=0.7, images=None,
                max_tokens=None, fast=False, timeout=240):
    """Gemini ko call. fast=True: 'thinking' band (jaldi jawab, time-out kam). images = [(label, jpeg_b64)]."""
    key = os.getenv("GEMINI_API_KEY", "").strip()
    if not key:
        raise RuntimeError("GEMINI_API_KEY .env file mein nahi mili")
    models = [m.strip() for m in GEMINI_MODELS.split(",") if m.strip()]
    parts = []
    for label, b64 in (images or []):
        parts += [{"text": label}, {"inline_data": {"mime_type": "image/jpeg", "data": b64}}]
    if audio_b64:
        parts.append({"inline_data": {"mime_type": audio_mime, "data": audio_b64}})
    parts.append({"text": prompt})

    last_err = "koi model nahi mila"
    for model in models:
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
        think_i = 0 if fast else len(THINK_OPTS) - 1
        attempt = 0
        while attempt < 3:
            gen = {"temperature": temperature, "responseMimeType": "application/json"}
            if max_tokens:
                gen["maxOutputTokens"] = max_tokens
            if THINK_OPTS[think_i]:
                gen["thinkingConfig"] = THINK_OPTS[think_i]
            payload = json.dumps({"contents": [{"parts": parts}], "generationConfig": gen}).encode("utf-8")
            req = urllib.request.Request(
                url, data=payload,
                headers={"Content-Type": "application/json", "x-goog-api-key": key},
            )
            try:
                with urllib.request.urlopen(req, timeout=timeout) as resp:
                    data = json.loads(resp.read().decode("utf-8"))
                out_parts = data["candidates"][0]["content"]["parts"]
                return "".join(p.get("text", "") for p in out_parts)
            except urllib.error.HTTPError as e:
                detail = e.read().decode("utf-8", errors="ignore")[:300]
                last_err = f"{model}: HTTP {e.code} {detail}"
                print(last_err, flush=True)
                if e.code == 400 and think_i < len(THINK_OPTS) - 1 and "think" in detail.lower():
                    think_i += 1          # is model par ye thinking setting nahi chalti, agli try
                    continue
                if e.code in (429, 503) and attempt < 2:
                    time.sleep(15 * (attempt + 1))   # free tier limit: ruk kar dobara
                    attempt += 1
                    continue
                break                     # agla model
            except (TimeoutError, urllib.error.URLError, ConnectionError) as e:
                last_err = f"{model}: network/time-out ({e})"
                print(last_err, flush=True)
                attempt += 1
                if attempt < 2:
                    continue
                break
            except (KeyError, IndexError):
                last_err = f"{model}: AI ne khali jawab diya"
                break
    raise RuntimeError("Gemini API fail ho gayi. " + last_err)


def loose_json(raw):
    m = re.search(r"\{.*\}", raw or "", re.S)
    if not m:
        return None
    try:
        return json.loads(m.group(0))
    except Exception:
        return None


def parse_words_loose(raw):
    """Poora JSON kharab/kata hua ho tab bhi jitne words mil sakein utne nikaalta hai."""
    data = loose_json(raw)
    if isinstance(data, dict) and isinstance(data.get("words"), list):
        return data["words"]
    out = []
    for m in re.finditer(r'"w"\s*:\s*"((?:[^"\\]|\\.)*)"\s*,\s*"s"\s*:\s*(-?[0-9.]+)\s*,\s*"e"\s*:\s*(-?[0-9.]+)', raw or ""):
        try:
            out.append({"w": json.loads('"' + m.group(1) + '"'), "s": float(m.group(2)), "e": float(m.group(3))})
        except Exception:
            continue
    return out


LANG_RULES = {
    "hindi": "BHASHA: Video chahe kisi bhi bhasha mein ho (English/multi-language), title, reason, caption_instagram aur caption_youtube SABHI shuddh Hindi mein, Devanagari script (देवनागरी) mein likho. Hashtags Hindi ya English dono ho sakte hain.",
    "hinglish": "BHASHA: Video chahe kisi bhi bhasha mein ho, title, reason, caption_instagram aur caption_youtube SABHI Hinglish (Hindi Roman script/English letters mein, jaise 'Ye moment dekh kar hairan reh jaoge') mein likho.",
    "english": "BHASHA: Video chahe kisi bhi bhasha mein ho, title, reason aur captions SABHI simple English mein likho.",
    "same": "BHASHA: Captions video ki apni bhasha aur tone se match karein.",
}


def analyze(title, duration, peaks, transcript_text, n, language="hindi"):
    prompt = PROMPT.format(
        title=title, duration=duration, peaks=json.dumps(peaks) if peaks else "available nahi",
        transcript=transcript_text, n=n, minc=MIN_CLIP, maxc=MAX_CLIP,
        lang_rule=LANG_RULES.get(language, LANG_RULES["hindi"]),
    )
    raw = call_gemini(prompt)
    m = re.search(r"\{.*\}", raw, re.S)
    if not m:
        raise RuntimeError("AI ne valid JSON nahi diya")
    return json.loads(m.group(0))["clips"]


def sanitize(clips, duration, n):
    good, used = [], []
    for c in sorted(clips, key=lambda x: -int(x.get("viral_score", 0))):
        s = max(0, int(c["start"]))
        e = min(duration, int(c["end"]))
        if e - s < MIN_CLIP:
            e = min(duration, s + MIN_CLIP)
        if e - s > MAX_CLIP:
            e = s + MAX_CLIP
        if e - s < 8 or any(s < ue and e > us for us, ue in used):
            continue
        used.append((s, e))
        c["start"], c["end"] = s, e
        good.append(c)
        if len(good) >= n:
            break
    return good


# ---------- Step 4: cut clips ----------
CAPTION_PROMPT = """Ye ek video clip ki audio hai. Isme jo bola ja raha hai use WORD-BY-WORD transcribe karo, usi bhasha aur script mein jo bolne wala bol raha hai (Hindi ho to Devanagari, English ho to English).
Har word ke saath uska start aur end time seconds mein do, audio ke bilkul shuru (0.0) se, decimals ke saath (jaise 1.24). Time sahi aur badhte hue hone chahiye, words overlap na karein.
Background music, gaane ya shor ko ignore karo. Agar koi bol hi nahi raha to words khali list rakho.
SIRF valid JSON do: {"words":[{"w":"word","s":0.00,"e":0.00}]}"""


CAPTION_SEG = 25.0   # chhote tukde = tez jawab, kam time-out, behtar timing


def transcribe_words(video_path, start, end, work_dir, idx):
    """Clip ki apni audio ko 25-second ke tukdon mein Gemini se word-timestamps ke saath transcribe karta hai."""
    from captions import clean_words
    total = end - start
    allw, last_err, seg_start = [], None, 0.0
    while seg_start < total - 0.4:
        seg_len = min(CAPTION_SEG, total - seg_start)
        if total - (seg_start + seg_len) < 6.0:
            seg_len = total - seg_start      # aakhir ka chhota tukda alag na karo
        wav = Path(work_dir) / f"audio{idx}.wav"
        try:
            r = subprocess.run([FFMPEG, "-y", "-ss", str(start + seg_start), "-i", str(video_path), "-t", str(seg_len),
                                "-vn", "-ac", "1", "-ar", "16000", "-c:a", "pcm_s16le", str(wav)],
                               capture_output=True, text=True)
            if r.returncode != 0 or not wav.exists():
                raise RuntimeError("audio nahi nikal payi: " + r.stderr[-150:])
            raw = call_gemini(CAPTION_PROMPT, audio_b64=base64.b64encode(wav.read_bytes()).decode("ascii"),
                              temperature=0, fast=True, max_tokens=6000, timeout=120)
            seg_words = clean_words(parse_words_loose(raw), seg_len)
            for x in seg_words:
                x["s"] += seg_start
                x["e"] += seg_start
            allw += seg_words
        except Exception as e:
            last_err = e
            print(f"[worker] clip {idx}: tukda {seg_start:.0f}s ka transcript nahi mila: {e}", flush=True)
        finally:
            wav.unlink(missing_ok=True)
        seg_start += seg_len
        time.sleep(2)
    if not allw and last_err:
        raise RuntimeError(str(last_err))
    return clean_words(allw, total)


VISION_PROMPT = """Tumhe ek video clip ke {n} frames diye gaye hain (Frame 0 se shuru, time ke order mein).
Har frame ke liye batao:
- x: jo insaan bol raha hai (ya frame mein sabse mukhya hai) uske chehre ka horizontal center, 0 (bilkul left) se 1000 (bilkul right) ke beech integer. Koi insaan na dikhe to null.
- box: us chehre ka bounding box [ymin, xmin, ymax, xmax], 0 se 1000 ke scale mein. Na dikhe to null.
- score: 0 se 10 ke beech, thumbnail ke liye ye frame kitna achha hai (expressive chehra, saaf, achhi roshni, aankhen khuli, blur nahi).
Agar do log hain to us ko chuno jo bol raha hai (jiska muh khula ya jo zyada active hai).
SIRF valid JSON do: {{"frames":[{{"i":0,"x":512,"box":[100,400,350,600],"score":7}}]}}"""


def analyze_frames(video_path, start, end, work_dir, idx):
    """Clip ke kuch frames Gemini ko dikha kar: bolne wale ki position (smart reframing) + best thumbnail frame."""
    dur = end - start
    n = max(6, min(14, int(round(dur / 4))))
    images, times = [], []
    for k in range(n):
        t = start + 0.6 + max(0.1, dur - 1.2) * k / max(1, n - 1)
        f = Path(work_dir) / f"_v{idx}_{k}.jpg"
        r = subprocess.run([FFMPEG, "-y", "-ss", f"{t:.2f}", "-i", str(video_path), "-frames:v", "1",
                            "-vf", "scale=512:-2", "-q:v", "5", str(f)], capture_output=True, text=True)
        if r.returncode == 0 and f.exists():
            images.append((f"Frame {len(images)}:", base64.b64encode(f.read_bytes()).decode("ascii")))
            times.append(t)
        f.unlink(missing_ok=True)
    if len(images) < 3:
        raise RuntimeError("frames nahi nikle")
    raw = call_gemini(VISION_PROMPT.format(n=len(images)), images=images, temperature=0, fast=True,
                      max_tokens=3000, timeout=120)
    data = loose_json(raw) or {}
    out = []
    for it in data.get("frames") or []:
        try:
            i = int(it.get("i"))
            if not 0 <= i < len(times):
                continue
            x = it.get("x")
            box = it.get("box")
            out.append({"t": times[i],
                        "x": (min(max(float(x), 0), 1000) / 1000.0) if x is not None else None,
                        "box": box if isinstance(box, list) and len(box) == 4 else None,
                        "score": float(it.get("score") or 0)})
        except (TypeError, ValueError):
            continue
    if not out:
        raise RuntimeError("AI se frames ki jaankari nahi mili")
    return out


def probe_size(video_path):
    r = subprocess.run([FFMPEG, "-hide_banner", "-i", str(video_path)], capture_output=True, text=True)
    m = re.search(r"Video:[^\n]*?[ ,](\d{3,5})x(\d{3,5})", r.stderr)
    return (int(m.group(1)), int(m.group(2))) if m else (None, None)


def crop_width(aspect, fit, src_w, src_h):
    """Jis mode mein video ko 'crop' kiya jata hai wahan crop ki chaudai (pixels), warna None."""
    if not src_w or not src_h:
        return None
    if aspect == "9:16" and fit == "bars":
        return min(src_w, int(src_h * 0.72)) // 2 * 2
    if aspect == "9:16" and fit == "crop":
        return min(src_w, int(src_h * 9 / 16)) // 2 * 2
    if aspect == "1:1" and fit == "crop":
        return min(src_w, src_h) // 2 * 2
    return None


def build_vf(aspect, fit, rx=None):
    """(mode, filter). rx = (x_expr, src_w, src_h, crop_w): bolne wale ko follow karne wala smart crop."""
    if aspect == "9:16":
        if fit == "bars":   # reference video jaisa: upar-neeche kali patti
            if rx:
                expr, sw, sh, cw = rx
                return "vf", f"crop=w={cw}:h={sh}:x='{expr}':y=0,scale=1080:1500,pad=1080:1920:0:186:black"
            return "vf", "crop='min(iw,ih*0.72)':ih,scale=1080:1500,pad=1080:1920:0:186:black"
        if fit == "blur":
            return "complex", ("[0:v]split[a][b];"
                               "[a]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=25:5[bg];"
                               "[b]scale=1080:1920:force_original_aspect_ratio=decrease[fg];"
                               "[bg][fg]overlay=(W-w)/2:(H-h)/2")
        if rx:
            expr, sw, sh, cw = rx
            return "vf", f"crop=w={cw}:h={sh}:x='{expr}':y=0,scale=1080:1920"
        return "vf", "crop='min(iw,ih*9/16)':ih,scale=1080:1920"
    if aspect == "1:1":
        if fit == "blur":
            return "complex", ("[0:v]split[a][b];"
                               "[a]scale=1080:1080:force_original_aspect_ratio=increase,crop=1080:1080,boxblur=25:5[bg];"
                               "[b]scale=1080:1080:force_original_aspect_ratio=decrease[fg];"
                               "[bg][fg]overlay=(W-w)/2:(H-h)/2")
        if rx:
            expr, sw, sh, cw = rx
            return "vf", f"crop=w={cw}:h={cw}:x='{expr}':y='(ih-{cw})/2',scale=1080:1080"
        return "vf", "crop='min(iw,ih)':'min(iw,ih)',scale=1080:1080"
    return "vf", "scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2"


def cut_clip(video_path, start, end, out_path, aspect="9:16", fit="crop", ass_rel=None, rx=None):
    mode, f = build_vf(aspect, fit, rx)
    sub = f"ass=filename={ass_rel}:fontsdir=fonts:shaping=complex" if ass_rel else None
    cmd = [FFMPEG, "-y", "-ss", str(start), "-i", str(video_path), "-t", str(end - start)]
    if mode == "complex":
        fc = f + (f"[v0];[v0]{sub}[v]" if sub else "[v]")
        cmd += ["-filter_complex", fc, "-map", "[v]", "-map", "0:a?"]
    else:
        cmd += ["-vf", f + ("," + sub if sub else "")]
    cmd += ["-c:v", "libx264", "-preset", "fast", "-crf", "23", "-c:a", "aac", "-movflags", "+faststart", str(out_path)]
    # cwd = ai_worker folder, taaki fonts/ aur subtitle ka relative path (bina drive letter ke) chal jaye
    r = subprocess.run(cmd, capture_output=True, text=True, cwd=str(HERE))
    if r.returncode != 0:
        raise RuntimeError("ffmpeg error: " + r.stderr[-400:])


# ---------- main ----------
def check_extras():
    try:
        import PIL  # noqa: F401
    except ImportError:
        print("[worker] WARNING: Pillow install nahi hai, thumbnails nahi banenge. Chalaiye: python -m pip install Pillow", flush=True)
        return "Thumbnails nahi bane: Pillow install nahi hai (python -m pip install Pillow)"
    return ""


def main(job_id):
    print(f"[worker] shuru: job {job_id}", flush=True)
    notes = []
    pil_note = check_extras()
    if pil_note:
        notes.append(pil_note)
    try:
        db = get_db()
    except Exception as e:
        sys.exit(f"[worker] MySQL se connect nahi hua: {e}")
    print("[worker] database connect ho gaya", flush=True)
    cur = db.cursor(dictionary=True)
    cur.execute("SELECT * FROM jobs WHERE id=%s", (job_id,))
    job = cur.fetchone()
    db.close()
    if not job:
        sys.exit(f"Job {job_id} database mein nahi mila")
    print("[worker] job mil gayi:", job["youtube_url"], flush=True)

    try:
        update(job_id, status="downloading", progress=10, message="Video download ho rahi hai...")
        info, video_path, hindi_missing = download(job["youtube_url"], job_id, job.get("audio_lang") or "hi")
        print("[worker] download ho gayi:", video_path, flush=True)
        title, duration, vid = info.get("title", ""), int(info.get("duration") or 0), info.get("id")
        update(job_id, title=title[:300], video_id=vid)
        peaks = heatmap_peaks(info)

        update(job_id, status="transcribing", progress=35, message="Transcript nikala ja raha hai...")
        segs = get_transcript(vid, video_path)
        text = transcript_to_text(segs)
        print("[worker] transcript mila, lines:", len(segs), flush=True)

        update(job_id, status="analyzing", progress=55, message="AI viral moments dhoondh raha hai...")
        raw_clips = analyze(title, duration, peaks, text, job["max_clips"], job.get("language") or "hindi")
        clips = sanitize(raw_clips, duration, job["max_clips"])
        print("[worker] AI ne clips diye:", len(clips), flush=True)
        if not clips:
            raise RuntimeError("AI se koi usable clip nahi mila")

        out_dir = ROOT / "storage" / "clips" / f"job{job_id}"
        out_dir.mkdir(parents=True, exist_ok=True)
        db = get_db()
        cur = db.cursor()
        aspect = job.get("aspect") or ("9:16" if job.get("vertical") else "16:9")
        fit = job.get("fit_mode") or "crop"
        want_caps = job.get("captions") in (None, 1)
        smart = job.get("smart_frame") in (None, 1)
        want_hook = job.get("title_hook") in (None, 1)
        credit_name = ""
        if job.get("credit_line") in (None, 1):
            credit_name = str(info.get("channel") or info.get("uploader") or "").strip()
        src_w, src_h = probe_size(video_path)
        cw = crop_width(aspect, fit, src_w, src_h)
        print(f"[worker] source {src_w}x{src_h}, smart reframing: {'haan' if (smart and cw) else 'nahi'}", flush=True)

        for i, c in enumerate(clips, 1):
            update(job_id, status="clipping", progress=65 + int(30 * i / len(clips)),
                   message=f"Clip {i}/{len(clips)}: AI frames dekh raha hai...")
            out = out_dir / f"clip{i}.mp4"
            dur = c["end"] - c["start"]

            # ---- 1) AI frames: bolne wale ki position + best thumbnail frame ----
            vis = None
            try:
                vis = analyze_frames(video_path, c["start"], c["end"], out_dir, i)
                print(f"[worker] clip {i}: AI ne {len(vis)} frames samjhe", flush=True)
                time.sleep(2)
            except Exception as e:
                print(f"[worker] clip {i}: frame analysis nahi hui:", e, flush=True)
                notes.append(f"clip {i}: smart framing/thumbnail frame AI nahi chuna ({str(e)[:60]})")

            # ---- 2) smart reframing: crop bolne wale ko follow kare ----
            rx = None
            if smart and cw and vis:
                try:
                    from reframe import x_expression
                    kf = [(f["t"] - c["start"], f["x"]) for f in vis]
                    expr = x_expression(kf, src_w, cw)
                    if expr:
                        rx = (expr, src_w, src_h, cw)
                except Exception as e:
                    print(f"[worker] clip {i}: reframing nahi bani:", e, flush=True)

            # ---- 3) captions + hook title + credit line ----
            ass_rel = None
            hook = (c.get("title") or "") if want_hook else ""
            if want_caps or credit_name or hook:
                try:
                    from captions import build_ass
                    words = []
                    if want_caps:
                        update(job_id, message=f"Clip {i}/{len(clips)}: captions ban rahe hain...")
                        try:
                            words = transcribe_words(video_path, c["start"], c["end"], out_dir, i)
                            print(f"[worker] clip {i}: {len(words)} words transcribe hue", flush=True)
                        except Exception as e:
                            print(f"[worker] clip {i}: captions ke liye transcribe nahi hua:", e, flush=True)
                            notes.append(f"clip {i}: captions nahi bane ({str(e)[:70]})")
                    ass_path = out_dir / f"subs{i}.ass"
                    ass_path.write_text(build_ass(words, dur, aspect, credit_name, bool(words), hook or None,
                                                  aspect == "9:16" and fit == "bars"), encoding="utf-8")
                    ass_rel = os.path.relpath(str(ass_path), str(HERE)).replace("\\", "/")
                except Exception as e:
                    print(f"[worker] clip {i}: captions file nahi bani:", e, flush=True)
                    ass_rel = None

            # ---- 4) clip kaato (fail ho to ek-ek feature hata kar dobara) ----
            update(job_id, message=f"Clip {i}/{len(clips)}: video ban rahi hai...")
            for attempt_ass, attempt_rx in ((ass_rel, rx), (ass_rel, None), (None, None)):
                try:
                    cut_clip(video_path, c["start"], c["end"], out, aspect, fit, attempt_ass, attempt_rx)
                    break
                except RuntimeError as e:
                    if attempt_ass is None and attempt_rx is None:
                        raise
                    print(f"[worker] clip {i}: ffmpeg fail ({str(e)[:120]}); simple tareeke se dobara", flush=True)
                    notes.append(f"clip {i}: editing effects hata kar bani") if attempt_ass is None else None

            # ---- 5) thumbnail ----
            thumb_rel = None
            try:
                from thumbs import make_thumbnails
                best_t, face = None, None
                if vis:
                    pool = [f for f in vis if f.get("box")] or vis
                    best = max(pool, key=lambda f: f["score"])
                    best_t, face = best["t"], best.get("box")
                make_thumbnails(FFMPEG, video_path, c["start"], c["end"], out_dir / f"thumb{i}.jpg",
                                out_dir / f"thumb{i}_yt.jpg", aspect,
                                c.get("thumbnail_text") or c.get("title", ""), c.get("thumbnail_text_roman") or "",
                                c.get("thumbnail_accent") or "", str(c.get("thumbnail_badge") or "")[:10],
                                best_t, face)
                thumb_rel = f"storage/clips/job{job_id}/thumb{i}.jpg"
            except Exception as e:
                print("[worker] thumbnail nahi bana:", e, flush=True)
                if not pil_note:
                    notes.append(f"clip {i}: thumbnail nahi bana ({str(e)[:70]})")

            cur.execute(
                "INSERT INTO clips(job_id,start_sec,end_sec,title,viral_score,reason,caption_instagram,"
                "caption_youtube,hashtags,file_path,thumb_path) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                (job_id, c["start"], c["end"], c.get("title", "")[:200], int(c.get("viral_score", 0)),
                 c.get("reason", ""), c.get("caption_instagram", ""), c.get("caption_youtube", ""),
                 " ".join(c.get("hashtags", []))[:500], f"storage/clips/job{job_id}/clip{i}.mp4", thumb_rel),
            )
            db.commit()
        db.close()
        if hindi_missing:
            notes.append("is video mein Hindi audio nahi thi, original awaaz use hui")
        done_msg = ("Note: " + "; ".join(notes))[:290] if notes else "Ho gaya!"
        update(job_id, status="done", progress=100, message=done_msg)
        print("[worker] POORA HO GAYA", flush=True)
    except Exception as e:
        traceback.print_exc()
        update(job_id, status="failed", message="Fail", error=str(e)[:1000])


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit("Usage: python worker.py <job_id>")
    main(int(sys.argv[1]))
