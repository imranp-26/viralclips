# Instagram Reels upload (ViralClips AI)

Job page par har clip ke neeche **"Instagram Reel post karo"** button aata hai. Caption + hashtags aur thumbnail (cover) apne aap lagte hain.

## Pehle ye samajh lijiye
- Sirf **Instagram Business ya Creator (professional)** account par chalta hai (Instagram app > Settings > Account type and tools > Switch to professional account).
- Instagram clip ko **aapke computer se nahi, ek public https link se khud download** karta hai. `localhost` kaam nahi karega, isliye ek **tunnel** chahiye (neeche Step 1).
- Ek account se roz kuch hi (lagbhag 100) API posts allowed hain. Instagram API se **schedule** nahi hota.
- Meta ka dashboard aksar badalta hai, isliye button ke naam thode alag ho sakte hain.

## Step 1: Public link (tunnel)
**Option A: ngrok (free static domain, link badalta nahi)**
1. https://ngrok.com par free account banaiye, ngrok install kijiye aur authtoken lagaiye (dashboard mein steps hain).
2. Dashboard > Domains se apna free static domain lijiye (jaise `naam.ngrok-free.app`).
3. Command Prompt mein: `ngrok http --url=naam.ngrok-free.app 80`

**Option B: Cloudflare quick tunnel (free, par har baar link badalta hai)**
`cloudflared tunnel --url http://localhost:80`

Jo https link mile, use `google_config.php` mein daaliye (apne folder ke naam ke saath):
```php
define('IG_PUBLIC_BASE_URL', 'https://naam.ngrok-free.app/viralclips');
```
XAMPP (Apache) chalta rehna chahiye aur tunnel ki window band nahi honi chahiye.

## Step 2: Meta app aur token
1. https://developers.facebook.com par login karke **My Apps > Create app** kijiye.
2. App mein product **Instagram** jodiye, phir **API setup with Instagram login** kholiye.
3. **Generate access tokens > Add account**: apne professional Instagram account se login karke allow kijiye, phir **Generate token** dabaiye aur token copy kijiye.
   - Zaroori permissions: `instagram_business_basic` aur `instagram_business_content_publish`.
   - Agar poochhe to apne Instagram account ko app mein **Instagram tester / role** de dijiye.
4. Token kisi ko mat bhejiye.

## Step 3: ViralClips mein jodiye
1. Website mein upar **Instagram** menu kholiye.
2. Token paste karke **Save aur check karo** dabaiye. Sahi hai to `@username` dikhega.
3. Kisi done job ke clip ke neeche **Instagram Reel post karo** dabaiye. Instagram video process karta hai (1–5 minute), phir link aa jata hai.

Token 60 din chalta hai. **Token refresh** button se badha sakte hain, upload ke waqt bhi apne aap koshish hoti hai.

## Agar error aaye
| Error | Matlab / solution |
|---|---|
| "Public link check fail" | Tunnel band hai, ya `IG_PUBLIC_BASE_URL` galat. Browser mein `.../storage/clips/job1/clip1.mp4` kholkar dekhiye |
| "download nahi kar paya" | Tunnel beech mein band hua, ya link redirect/login page par ja raha hai |
| "token galat ya expire" | Meta dashboard se naya token banakar save kijiye |
| "Permission nahi mili" | Account Business/Creator hona chahiye aur token mein publish permission |
| "video reject" | Reel ke liye 9:16, H.264 + AAC. Hamare 9:16 clips aise hi hote hain, 16:9 clips mein dikkat ho sakti hai |
