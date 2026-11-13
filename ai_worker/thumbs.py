"""
Advanced thumbnail maker:
 - AI se chuna hua best frame (chehra saaf/expressive) ya, AI na ho to, sharpness+color se auto-pick
 - Smart crop: chehra bada aur ek taraf, text doosri taraf
 - Color grade + spotlight (chehra roshan, baaki halka dark)
 - Bada bold text, ek 'accent' word peela, thoda tilted, thick outline + shadow
 - Optional badge/sticker (jaise "Rs35") lal rang mein
Kisi bhi problem par worker ko rokta nahi (worker isko try/except mein chalata hai).
"""
import subprocess
from pathlib import Path

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont, ImageStat, features

SIZES = {"16:9": (1280, 720), "9:16": (1080, 1920), "1:1": (1080, 1080)}
FONT_DIR = Path(__file__).resolve().parent / "fonts"
LATIN_FONTS = [str(FONT_DIR / "Anton-Regular.ttf"), "C:/Windows/Fonts/impact.ttf", "C:/Windows/Fonts/ariblk.ttf",
               "C:/Windows/Fonts/arialbd.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"]
INDIC_FONTS = [str(FONT_DIR / "Hind-Bold.ttf"), "C:/Windows/Fonts/NirmalaB.ttf", "C:/Windows/Fonts/Nirmala.ttf",
               "C:/Windows/Fonts/mangalb.ttf"]
HAS_RAQM = features.check("raqm")
YELLOW = (255, 224, 0)
WHITE = (255, 255, 255)


def has_devanagari(s):
    return any("\u0900" <= ch <= "\u097F" for ch in s)


def needs_indic_font(s):
    return has_devanagari(s) or "\u20b9" in s  # rupee sign


def pick_font(path_list, size):
    for p in path_list:
        if Path(p).is_file():
            try:
                engine = ImageFont.Layout.RAQM if HAS_RAQM else ImageFont.Layout.BASIC
                return ImageFont.truetype(p, size, layout_engine=engine)
            except Exception:
                continue
    try:
        return ImageFont.load_default(size)
    except TypeError:
        return ImageFont.load_default()


# ---------------------------------------------------------------- frames
def extract_frame(ffmpeg, video, t, out):
    r = subprocess.run([ffmpeg, "-y", "-ss", f"{t:.2f}", "-i", str(video), "-frames:v", "1", "-q:v", "2", str(out)],
                       capture_output=True, text=True)
    if r.returncode != 0 or not Path(out).exists():
        raise RuntimeError("frame nahi nikla")
    return out


def score_frame(path):
    im = Image.open(path).convert("RGB")
    im.thumbnail((400, 400))
    w, h = im.size
    center = im.crop((w // 4, h // 6, 3 * w // 4, 5 * h // 6))
    edges = center.convert("L").filter(ImageFilter.FIND_EDGES)
    sharp = ImageStat.Stat(edges).var[0] ** 0.5
    sat = ImageStat.Stat(im.convert("HSV")).mean[1]
    light = ImageStat.Stat(im.convert("L")).mean[0]
    return sharp + sat * 0.25 - abs(light - 120) * 0.35


def heuristic_best(ffmpeg, video, start, end, workdir, count=6):
    span = max(1.0, end - start)
    best, best_s = None, -1e9
    for k in range(count):
        t = start + span * (0.12 + 0.76 * k / max(1, count - 1))
        f = Path(workdir) / f"_h{k}.jpg"
        try:
            extract_frame(ffmpeg, video, t, f)
        except RuntimeError:
            continue
        sc = score_frame(f)
        if sc > best_s:
            best, best_s = t, sc
        f.unlink(missing_ok=True)
    return best if best is not None else start + span / 2


# ---------------------------------------------------------------- image effects
def face_info(face, W, H):
    """face = [ymin,xmin,ymax,xmax] (0..1000) -> (cx,cy,fh) normalized."""
    if not face or len(face) != 4:
        return None
    try:
        ymin, xmin, ymax, xmax = [float(v) / 1000.0 for v in face]
    except (TypeError, ValueError):
        return None
    if ymax <= ymin or xmax <= xmin:
        return None
    return ((xmin + xmax) / 2, (ymin + ymax) / 2, ymax - ymin)


def smart_crop(im, size, face, aspect):
    """Return (cropped_resized, face_x_in_crop 0..1 ya None, (face_cx_px, face_cy_px) target mein)."""
    W, H = im.size
    tw, th = size
    ratio = tw / th
    bw, bh = (H * ratio, H) if W / H > ratio else (W, W / ratio)
    z = 1.0
    if face:
        cx, cy, fh = face
        z = min(1.6, max(1.0, bh / max(1.0, (fh * H) / 0.38)))
    cw, ch = bw / z, bh / z
    if face:
        cx_px, cy_px = cx * W, cy * H
        # ek taraf chehra, doosri taraf text ki jagah
        if aspect == "16:9":
            fx = 0.34 if cx < 0.5 else 0.66
        elif aspect == "1:1":
            fx = 0.40 if cx < 0.5 else 0.60
        else:
            fx = 0.5
        fy = 0.33 if aspect == "9:16" else 0.42
        left = cx_px - fx * cw
        top = cy_px - fy * ch
    else:
        left, top = (W - cw) / 2, (H - ch) / 2
    left = min(max(left, 0), W - cw)
    top = min(max(top, 0), H - ch)
    out = im.crop((int(left), int(top), int(left + cw), int(top + ch))).resize(size, Image.LANCZOS)
    if face:
        fxc = (face[0] * W - left) / cw
        fyc = (face[1] * H - top) / ch
        return out, fxc, (fxc * tw, fyc * th)
    return out, None, None


def grade(im):
    im = ImageEnhance.Color(im).enhance(1.32)
    im = ImageEnhance.Contrast(im).enhance(1.14)
    im = ImageEnhance.Brightness(im).enhance(1.04)
    im = im.filter(ImageFilter.UnsharpMask(radius=2, percent=130, threshold=3))
    return im


def spotlight(im, center):
    w, h = im.size
    cx, cy = center if center else (w / 2, h / 2)
    mask = Image.new("L", (w, h), 0)
    d = ImageDraw.Draw(mask)
    rx, ry = w * 0.34, h * 0.42
    d.ellipse((cx - rx, cy - ry, cx + rx, cy + ry), fill=255)
    mask = mask.filter(ImageFilter.GaussianBlur(min(w, h) // 5))
    bright = ImageEnhance.Brightness(im).enhance(1.16)
    dark = ImageEnhance.Brightness(im).enhance(0.72)
    return Image.composite(bright, dark, mask)


def side_gradient(im, text_right, alpha=170):
    w, h = im.size
    grad = Image.new("L", (w, 1), 0)
    for x in range(w):
        f = x / w if text_right else 1 - x / w
        grad.putpixel((x, 0), int(alpha * max(0.0, (f - 0.35) / 0.65)))
    grad = grad.resize((w, h))
    return Image.composite(Image.new("RGB", (w, h), (0, 0, 0)), im, grad)


def bottom_gradient(im, frac=0.5, alpha=200):
    w, h = im.size
    top = int(h * (1 - frac))
    grad = Image.new("L", (1, h), 0)
    for y in range(top, h):
        grad.putpixel((0, y), int(alpha * (y - top) / max(1, h - top)))
    grad = grad.resize((w, h))
    return Image.composite(Image.new("RGB", (w, h), (0, 0, 0)), im, grad)


# ---------------------------------------------------------------- text
def wrap(draw, words, font, max_w):
    lines, cur = [], []
    for wd in words:
        test = " ".join(cur + [wd])
        if draw.textlength(test, font=font) <= max_w or not cur:
            cur.append(wd)
        else:
            lines.append(cur)
            cur = [wd]
    if cur:
        lines.append(cur)
    return lines


def text_layer(text, accent, box_w, box_h, align="center", max_lines=3):
    """Transparent layer par bada text (accent word peela). Return (layer, used_w, used_h)."""
    probe = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
    deva = needs_indic_font(text)
    fonts = INDIC_FONTS if deva else LATIN_FONTS
    if not deva:
        text = text.upper()
    words = text.split()
    acc = (accent or "").upper() if not deva else (accent or "")
    size = int(box_h * 0.5)
    while size > 30:
        font = pick_font(fonts, size)
        lines = wrap(probe, words, font, box_w)
        lh = int(size * 1.12)
        if len(lines) <= max_lines and lh * len(lines) <= box_h and all(
                probe.textlength(" ".join(l), font=font) <= box_w for l in lines):
            break
        size -= 4
    stroke = max(4, size // 8)
    pad = stroke * 2 + size // 6
    lh = int(size * 1.12)
    layer = Image.new("RGBA", (box_w + pad * 2, lh * len(lines) + pad * 2), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    space = probe.textlength(" ", font=font)
    if not acc:  # accent na mila: sabse lamba word
        acc = max(words, key=len)
    accented = False
    for li, line in enumerate(lines):
        total = sum(probe.textlength(w, font=font) for w in line) + space * (len(line) - 1)
        x = pad + ((box_w - total) / 2 if align == "center" else (box_w - total if align == "right" else 0))
        y = pad + li * lh
        for w in line:
            is_acc = (not accented) and (w.strip(".,!?:;।'\"").upper() == acc.strip(".,!?:;।'\"").upper())
            if is_acc:
                accented = True
            col = YELLOW if is_acc else WHITE
            off = max(3, size // 14)
            d.text((x + off, y + off), w, font=font, fill=(0, 0, 0, 200), stroke_width=stroke, stroke_fill=(0, 0, 0, 200))
            d.text((x, y), w, font=font, fill=col, stroke_width=stroke, stroke_fill=(0, 0, 0, 255))
            x += probe.textlength(w, font=font) + space
    return layer


def paste_rotated(base, layer, center, angle):
    rot = layer.rotate(angle, resample=Image.BICUBIC, expand=True)
    x = int(center[0] - rot.width / 2)
    y = int(center[1] - rot.height / 2)
    base.paste(rot, (x, y), rot)


def badge_layer(text, height):
    deva = needs_indic_font(text)
    font = pick_font(INDIC_FONTS if deva else LATIN_FONTS, int(height * 0.62))
    probe = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
    t = text if deva else text.upper()
    tw = probe.textlength(t, font=font)
    w, h = int(tw + height * 0.9), height
    pad = 12
    layer = Image.new("RGBA", (w + pad * 2, h + pad * 2), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    d.rounded_rectangle((pad, pad, pad + w, pad + h), radius=h // 4, fill=(228, 28, 36, 255),
                        outline=(255, 255, 255, 255), width=max(5, h // 14))
    d.text((pad + w / 2, pad + h / 2), t, font=font, fill=WHITE, anchor="mm",
           stroke_width=max(2, h // 30), stroke_fill=(90, 0, 0, 255))
    return layer


# ---------------------------------------------------------------- main
def render(src, size, aspect, face, text, accent, badge):
    im, fx, fpx = smart_crop(src, size, face, aspect)
    im = grade(im)
    im = spotlight(im, fpx)
    tw, th = size
    portrait = aspect == "9:16"
    text_right = (fx is None) or fx < 0.5
    im = bottom_gradient(im, 0.5, 210) if portrait else side_gradient(im, text_right, 175)

    if text:
        if portrait:
            layer = text_layer(text, accent, int(tw * 0.88), int(th * 0.26), "center", 3)
            paste_rotated(im, layer, (tw / 2, th * 0.77), -2)
        else:
            bw, bh = int(tw * 0.46), int(th * 0.62)
            layer = text_layer(text, accent, bw, bh, "center", 3)
            cx = tw * (0.745 if text_right else 0.255)
            paste_rotated(im, layer, (cx, th * 0.60), -3)
    if badge:
        bl = badge_layer(badge[:10], int(th * (0.085 if portrait else 0.15)))
        if portrait:
            pos = (tw * 0.80, th * 0.07)
        else:
            pos = (tw * (0.82 if not text_right else 0.17), th * 0.15)
        paste_rotated(im, bl, pos, 5 if not portrait else 4)
    return im


def make_thumbnails(ffmpeg, video, start, end, out_clip, out_yt, aspect, text, text_roman,
                    accent="", badge="", best_t=None, face=None):
    out_clip, out_yt = Path(out_clip), Path(out_yt)
    work = out_clip.parent
    if best_t is None:
        best_t = heuristic_best(ffmpeg, video, start, end, work)
    fr = work / "_best.png"
    extract_frame(ffmpeg, video, best_t, fr)
    src = Image.open(fr).convert("RGB")
    fr.unlink(missing_ok=True)

    use = (text or "").strip()
    if has_devanagari(use) and not HAS_RAQM:
        use = (text_roman or "").strip() or use
    fi = face_info(face, *src.size)

    img = render(src, SIZES.get(aspect, SIZES["16:9"]), aspect, fi, use, accent, badge)
    img.save(out_clip, "JPEG", quality=90)
    if aspect == "16:9":
        img.save(out_yt, "JPEG", quality=90)
    else:  # YouTube ke liye hamesha 16:9 version
        render(src, SIZES["16:9"], "16:9", fi, use, accent, badge).save(out_yt, "JPEG", quality=90)
    return out_clip, out_yt
