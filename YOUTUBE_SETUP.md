# YouTube Upload Setup (ViralClips AI)

Ye add-on clips ko seedha aapke YouTube channel par upload karta hai (YouTube Data API v3 + Google OAuth).

## Pehle samajh lijiye (zaroori)
- Google ki **unverified/unaudited app** se upload hui videos **private** hi lock ho jati hain, chahe aap Public chunein. Public karne ke liye Google ka "API Compliance Audit" pass karna padta hai (form, privacy policy, kuch hafte). College demo ke liye private/unlisted upload kaafi hai.
- Daily quota limited hoti hai (default 10,000 units/din). Upload ka cost Google ke docs mein check kijiye, kaafi jagah 1,600 units likha hai, matlab ~6 uploads/din.
- OAuth app "Testing" mode mein ho to login har ~7 din mein expire ho sakta hai, tab sirf **Disconnect -> Connect YouTube** kar dijiye.
- Apne **sirf apne channel** par upload kijiye, aur wahi videos jin par aapka haq ho (copyright).

## Step 1: Google Cloud project
1. https://console.cloud.google.com kholiye, Google account se login kijiye.
2. Upar project picker -> **New Project** -> naam `viralclips` -> Create.

## Step 2: YouTube API on kijiye
**APIs & Services > Library** -> "YouTube Data API v3" -> **Enable**.

## Step 3: OAuth consent screen
**APIs & Services > OAuth consent screen** (ya "Google Auth Platform"):
1. User type: **External**.
2. App name `ViralClips AI`, support email aur developer email apna daaliye.
3. **Audience / Test users** mein **apna Gmail** (jisse YouTube channel hai) add kijiye. Ye zaroori hai, warna "access blocked" aayega.
4. **Data access / Scopes** mein `.../auth/youtube.upload` add kijiye.

## Step 4: Credentials
1. **Credentials > Create credentials > OAuth client ID**.
2. Application type: **Web application**.
3. **Authorized redirect URIs** mein bilkul ye daaliye:
   `http://localhost/viralclips/yt_callback.php`
4. Create. **Client ID** aur **Client secret** copy kijiye.

## Step 5: Files lagaiye
1. Is zip ki saari files `C:\xampp\htdocs\viralclips\` mein copy kijiye (job.php replace hogi, baaki nayi hain). **config.php ko haath nahi lagaya gaya hai.**
2. `google_config.php` Notepad mein kholiye, Client ID aur Secret paste kijiye. Save kijiye.
3. phpMyAdmin mein `viralclips` database kholiye -> **SQL** tab -> `yt_schema.sql` ka content paste karke Go.
4. XAMPP ka Apache restart kijiye.

## Step 6: Use kijiye
1. Kisi bhi done job ka page kholiye (History se).
2. **Connect YouTube** dabaiye -> Google login -> "Google hasn't verified this app" aaye to **Advanced > Go to ViralClips AI (unsafe)** -> Allow.
3. Wapas aane par har clip ke neeche **Visibility** chuniye aur **YouTube par upload karo** dabaiye.
4. 1–3 minute baad link aa jayega. YouTube Studio mein video dekh sakte hain.

## Troubleshooting
| Problem | Solution |
|---|---|
| `redirect_uri_mismatch` | Step 4 ka URI aur `google_config.php` ka URI bilkul same hona chahiye (http, localhost, path) |
| `access_denied` / "app not verified" | Step 3 mein apna Gmail Test user mein add kijiye |
| `SSL certificate problem` | `php.ini` mein `curl.cainfo = "C:\xampp\php\extras\ssl\cacert.pem"` set kijiye, Apache restart |
| "curl extension band hai" | `php.ini` mein `extension=curl` ka `;` hataiye, Apache restart |
| Upload fail: "channel nahi hai" | Us Google account par YouTube channel banaiye |
| Upload fail: quota | Kal dobara try kijiye |
| Upload ke beech timeout | `php.ini` mein `max_execution_time = 0`, Apache restart |
