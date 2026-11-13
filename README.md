# ViralClips AI 🎬

YouTube video ka link do → AI viral moments dhoondhta hai → clips kat kar deta hai →
Instagram Reels + YouTube Shorts ke captions aur hashtags bhi deta hai.

**Stack:** PHP + MySQL (frontend/login/history) + Python (AI worker) + yt-dlp + ffmpeg + Google Gemini API (free)

## Flow
1. User login karke YouTube URL dalta hai (`submit.php`)
2. PHP job DB mein save karke `ai_worker/worker.py` background mein chalata hai
3. Worker: download -> transcript -> YouTube "Most replayed" heatmap -> Gemini AI analysis -> ffmpeg se clips
4. `job.php` har 3 second mein progress poll karta hai, aur ready hone par clips + captions dikhata hai

## Setup (Windows / XAMPP)
1. **XAMPP** start karke Apache + MySQL on kijiye. Is folder ko `C:\xampp\htdocs\viralclips` mein rakhiye.
2. phpMyAdmin mein `schema.sql` import kijiye.
3. **Python 3.10+** install kijiye, phir:
   ```
   cd ai_worker
   pip install -r requirements.txt
   ```
4. **ffmpeg** install kijiye (https://ffmpeg.org) aur PATH mein add kijiye. Check: `ffmpeg -version`
5. `ai_worker/.env.example` ko copy karke `ai_worker/.env` banaiye, aur apni **Gemini API key** (https://aistudio.google.com/apikey) daliye.
6. `config.php` mein DB user/password check kijiye (XAMPP default: root, khali password).
7. Browser mein kholiye: `http://localhost/viralclips/register.php`

## Troubleshooting
- **Job "queued" par atka hai:** `storage/worker_<id>.log` kholiye, wahan Python ka error milega. Aksar `python` PATH mein nahi hota -> `config.php` mein `PYTHON_BIN` ka full path daliye.
- **"Transcript nahi mila":** video mein subtitles nahi hain -> `pip install faster-whisper` kijiye (local speech-to-text).
- **yt-dlp error / 403:** `pip install -U yt-dlp` (YouTube baar baar badalta hai).
- **Bahut lambi video (2+ ghante):** transcript bahut bada ho jayega; pehle 1 ghante tak ki videos se test kijiye.

## Limitations (viva mein bata sakte hain)
- Instagram ka koi public "trending clips" API nahi hai. Isliye "viral" ka andaza **YouTube Most-Replayed data + transcript par AI ke analysis** se lagaya jata hai - yani ye *prediction* hai, guarantee nahi.
- Copyright: sirf apni ya permission wali videos ke clips post kijiye.
- API key ko kabhi GitHub par upload mat kijiye (`.env` private rakhiye).

## Folder structure
```
viralclips/
 ├─ index.php, login.php, register.php, logout.php   # UI + auth
 ├─ submit.php, job.php, status.php, history.php     # job flow
 ├─ config.php, header.php, footer.php, schema.sql
 ├─ assets/style.css
 ├─ storage/            # downloaded videos + final clips
 └─ ai_worker/          # Python AI pipeline
```

## Android app ke liye API
`api/` folder mein JSON API hai (login, register, submit, status, history). Token header: `X-Auth-Token`.
Agar `schema.sql` pehle import kiya tha to `schema_update_for_android.sql` bhi chalaiye.

## Naye features (v7)
- **Trending videos tab** (index page): PDF ke YouTubers ke latest/trending videos. Ek baar YouTube API key chahiye:
  Google Cloud > Credentials > Create credentials > API key, phir `google_config.php` mein
  `define('YOUTUBE_API_KEY', 'aapki-key');`
- **Instagram upload:** `INSTAGRAM_SETUP.md` dekhiye (tunnel + Meta token).
- Naye install par `google_config.php.sample` ko copy karke `google_config.php` banaiye.
- `database_all.sql` dobara chalana mat bhooliye (nayi table: instagram_accounts).
