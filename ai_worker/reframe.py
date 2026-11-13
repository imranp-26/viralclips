"""
Smart reframing: AI se mile 'speaker ka x-position' (alag-alag time par) se ffmpeg crop ka x-expression banata hai.
Do log baat kar rahe ho to crop bolne wale par 'cut' hota hai (chhota smooth ramp), reference video jaisa.
"""


def _median3(v):
    if len(v) < 3:
        return list(v)
    out = [v[0]]
    for i in range(1, len(v) - 1):
        out.append(sorted(v[i - 1:i + 2])[1])
    out.append(v[-1])
    return out


def x_expression(keyframes, src_w, crop_w, shift_thresh=0.10, ramp=0.30):
    """
    keyframes: [(t_sec, face_cx 0..1 ya None)]. Return: (expr_str ya None).
    expr crop ke x ke liye hai (pixels), t = clip ka time.
    """
    pts = [(float(t), c) for t, c in keyframes]
    valid = [c for _, c in pts if c is not None]
    if not valid or src_w <= crop_w:
        return None
    # None ko aas-paas ki value se bharo
    last = valid[0]
    filled = []
    for t, c in pts:
        if c is None:
            c = last
        last = c
        filled.append((t, c))
    xs = [min(max(c * src_w - crop_w / 2.0, 0.0), src_w - crop_w) for _, c in filled]
    xs = _median3(xs)
    times = [t for t, _ in filled]

    # shots: jab tak position kaafi nahi badli, wahi shot
    shots = [[times[0], times[0], xs[0], 1]]   # [t_first, t_last, mean_x, count]
    for t, x in zip(times[1:], xs[1:]):
        cur = shots[-1]
        if abs(x - cur[2]) <= shift_thresh * crop_w:
            cur[1] = t
            cur[2] = (cur[2] * cur[3] + x) / (cur[3] + 1)
            cur[3] += 1
        else:
            shots.append([t, t, x, 1])

    base = shots[0][2]
    terms = []
    for a, b in zip(shots, shots[1:]):
        delta = b[2] - a[2]
        tm = (a[1] + b[0]) / 2.0
        t0 = max(0.0, tm - ramp / 2.0)
        terms.append("(%.1f)*clip((t-%.2f)/%.2f,0,1)" % (delta, t0, ramp))
    expr = "%.1f" % base + ("+" + "+".join(terms) if terms else "")
    return expr
