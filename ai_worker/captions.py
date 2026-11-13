"""
On-screen captions (reference video jaisa): peela text, jis word ko bola ja raha hai wo orange,
neeche "CREDIT:- <channel>" line. ASS subtitle file banata hai, ffmpeg (libass) video par chhap deta hai.
"""
import re

YELLOW = "&H0000E4FF&"   # (255,228,0)
ORANGE = "&H00008AFF&"   # (255,138,0)
GREY = "&H00F0F0F0&"

# 9:16 ke liye numbers reference video ke naap se liye gaye hain (canvas 1080x1920)
LAYOUTS = {
    "9:16": dict(W=1080, H=1920, size=170, marginv=450, credit_y=1575, csize=120, side=50),
    "1:1": dict(W=1080, H=1080, size=140, marginv=200, credit_y=975, csize=96, side=50),
    "16:9": dict(W=1280, H=720, size=96, marginv=100, credit_y=668, csize=56, side=60),
}


def has_devanagari(s):
    return any("\u0900" <= ch <= "\u097F" for ch in s)


def ass_time(t):
    t = max(0.0, t)
    return f"{int(t // 3600)}:{int(t % 3600 // 60):02d}:{t % 60:05.2f}"


def clean_words(data, dur):
    """Gemini ke jawab se words ki saaf list: [{'w','s','e'}], time sahi order mein."""
    raw = data.get("words") if isinstance(data, dict) else data
    words = []
    for it in raw or []:
        try:
            w = str(it.get("w") or it.get("word") or "").strip()
            s = float(it.get("s", it.get("start")))
            e = float(it.get("e", it.get("end")))
        except (TypeError, ValueError, AttributeError):
            continue
        if w:
            words.append({"w": w, "s": s, "e": e})
    if not words:
        return []
    top = max(x["e"] for x in words)
    if top > dur + 2:                      # time galat unit mein aaye to dur ke hisaab se scale
        k = dur / top
        for x in words:
            x["s"] *= k
            x["e"] *= k
    prev_e = 0.0
    for x in words:
        x["s"] = min(max(x["s"], prev_e), dur)
        if x["e"] <= x["s"]:
            x["e"] = x["s"] + 0.18
        x["e"] = min(x["e"], dur)
        prev_e = x["e"]
    return [x for x in words if x["e"] > x["s"]]


def chunk_words(words, max_words=5, max_chars=26, gap=0.6):
    chunks, cur, chars = [], [], 0
    for w in words:
        if cur and (len(cur) >= max_words or chars + len(w["w"]) + 1 > max_chars or w["s"] - cur[-1]["e"] > gap):
            chunks.append(cur)
            cur, chars = [], 0
        cur.append(w)
        chars += len(w["w"]) + 1
        if re.search(r"[.!?।]$", w["w"]) and len(cur) >= 2:
            chunks.append(cur)
            cur, chars = [], 0
    if cur:
        chunks.append(cur)
    return chunks


def split_lines(n_words, lens):
    """Chunk ko 2 line mein baantne ki jagah (word index), ya None agar ek line kaafi hai."""
    if n_words < 3 or sum(lens) + n_words - 1 <= 18:
        return None
    best, best_diff = None, 10 ** 9
    for k in range(1, n_words):
        a = sum(lens[:k]) + k - 1
        b = sum(lens[k:]) + (n_words - k) - 1
        if abs(a - b) < best_diff:
            best, best_diff = k, abs(a - b)
    return best


def style_word(word, color):
    if has_devanagari(word):
        return "{\\fnHind\\b1\\fscx100\\fs%d\\c%s}%s" % (0, color, word)  # fs placeholder replaced below
    return "{\\fnAnton\\b0\\fscx116\\c%s}%s" % (color, word.upper())


def wrap_hook(text, max_chars=20, max_lines=2):
    words, lines, cur = text.split(), [], ""
    for w in words:
        if cur and len(cur) + 1 + len(w) > max_chars:
            lines.append(cur)
            cur = w
        else:
            cur = (cur + " " + w).strip()
    if cur:
        lines.append(cur)
    if len(lines) > max_lines:
        lines = lines[:max_lines]
        lines[-1] = lines[-1].rstrip(" ,.!?") + "..."
    return lines


def build_ass(words, dur, aspect, credit_name=None, show_captions=True, hook_text=None, bars=False):
    L = LAYOUTS.get(aspect, LAYOUTS["16:9"])
    size, csize = L["size"], L["csize"]
    hsize = {"9:16": 64 if bars else 74, "1:1": 64, "16:9": 46}.get(aspect, 50)
    hmargin = 52 if bars else {"9:16": 110, "1:1": 50, "16:9": 40}.get(aspect, 40)
    head = f"""[Script Info]
ScriptType: v4.00+
PlayResX: {L['W']}
PlayResY: {L['H']}
WrapStyle: 2
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Cap,Anton,{size},{YELLOW},&H000000FF&,&H00000000&,&H6000508C&,0,0,0,0,100,100,0,0,1,{max(4, size // 14)},{max(3, size // 22)},2,{L['side']},{L['side']},{L['marginv']},1
Style: Hook,Anton,{hsize},&H00FFFFFF&,&H000000FF&,&H00000000&,&H6000508C&,0,0,0,0,100,100,0,0,1,{max(4, hsize // 12)},{max(2, hsize // 22)},8,40,40,{hmargin},1
Style: Credit,Anton,{csize},{GREY},&H000000FF&,&H00000000&,&H6000508C&,0,0,0,0,100,100,0,0,1,{max(3, csize // 14)},{max(2, csize // 22)},5,20,20,0,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""
    lines = []
    if show_captions and words:
        chunks = chunk_words(words)
        for ci, chunk in enumerate(chunks):
            lens = [len(x["w"]) for x in chunk]
            brk = split_lines(len(chunk), lens)
            next_start = chunks[ci + 1][0]["s"] if ci + 1 < len(chunks) else dur
            for k, w in enumerate(chunk):
                start = w["s"]
                end = chunk[k + 1]["s"] if k + 1 < len(chunk) else min(w["e"] + 0.15, next_start)
                end = max(end, start + 0.08)
                parts = []
                for j, x in enumerate(chunk):
                    color = ORANGE if j == k else YELLOW
                    seg = style_word(x["w"], color).replace("\\fs0", "\\fs%d" % int(size * 1.0))
                    parts.append(seg)
                    if j + 1 < len(chunk):
                        parts.append("\\N" if brk is not None and j + 1 == brk else " ")
                lines.append(f"Dialogue: 0,{ass_time(start)},{ass_time(end)},Cap,,0,0,0,,{''.join(parts)}")
    if hook_text:
        hl = wrap_hook(hook_text.strip(), 20 if aspect == "9:16" else 26)
        parts = []
        for li, line in enumerate(hl):
            for wi, wd in enumerate(line.split()):
                if has_devanagari(wd):
                    parts.append("{\\fnHind\\b1\\fscx100\\fs%d\\c&H00FFFFFF&}%s " % (int(hsize * 0.95), wd))
                else:
                    parts.append("{\\fnAnton\\b0\\fscx110\\c&H00FFFFFF&}%s " % wd.upper())
            if li + 1 < len(hl):
                parts.append("\\N")
        hook_end = dur if bars else min(dur, 4.0)
        lines.append(f"Dialogue: 2,{ass_time(0)},{ass_time(hook_end)},Hook,,0,0,0,,{''.join(parts).rstrip()}")
    if credit_name:
        name = credit_name.upper().replace("{", "").replace("}", "")
        txt = "{\\fscx116\\c%s}CREDIT:-  {\\c%s}%s" % (GREY, YELLOW, name)
        lines.append(f"Dialogue: 1,{ass_time(0)},{ass_time(dur)},Credit,,0,0,0,,{{\\an5\\pos({L['W'] // 2},{L['credit_y']})}}{txt}")
    return head + "\n".join(lines) + "\n"
