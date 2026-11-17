#!/usr/bin/env python3
"""Stockpicker auto-scan.

Runs on GitHub Actions (free). No AI involved. Each run:
  1. builds a universe: FTSE 100/250, S&P 500, Nasdaq-100, S&P SmallCap 600,
     your watchlist.csv, and every UK ticker seen in recent RNS headlines
     (so AIM names join the scan automatically as they announce news);
  2. downloads one year of daily prices from Yahoo Finance (yfinance);
  3. flags breakouts, names testing highs, oversold bounce candidates and
     news-driven movers;
  4. reads the Takeover Panel disclosure table and reports new / removed offerees;
  5. writes data/auto.json (for the web page) and data/digest.json (a small
     shortlist that a Claude run can read cheaply).

Screening tool only - not financial advice.
"""
import csv, io, json, math, os, re, sys, time
from datetime import datetime, timezone, timedelta

import pandas as pd
import requests
from bs4 import BeautifulSoup

try:
    import yfinance as yf
except ImportError:  # allows offline tests
    yf = None

ROOT = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(ROOT, "data")
STATE_F = os.path.join(DATA, "state.json")
UA = {"User-Agent": "Mozilla/5.0 (personal stock screener; GitHub Actions)"}
NOW = datetime.now(timezone.utc)

# ---------------------------------------------------------------- settings
MIN_VALUE_UK = 25_000        # min avg daily value traded, GBP
MIN_VALUE_US = 2_000_000     # min avg daily value traded, USD
RNS_PAGES = 10               # Investegate pages (50 per page) to read per run
RNS_KEEP_DAYS = 10           # how long headlines stay in auto.json
UK_SEEN_DAYS = 120           # keep RNS-discovered tickers in the universe this long
MAX_BODY_FETCH = 40          # announcement pages opened per run (movers only)

POS = re.compile(r"ahead of (?:market |board |management )?expectations|exceed|above (?:market |board )?expectations|"
                 r"upgrade|record (?:revenue|results|profit|sales|order)|contract (?:win|award)|awarded|significant (?:order|contract)|"
                 r"raise[sd]? (?:guidance|forecast|outlook)|increase[sd]? guidance|recommended (?:cash )?(?:acquisition|offer)|"
                 r"rule 2\.7|possible offer|approach|buy-?back|share repurchase|strong (?:trading|demand|growth)|"
                 r"licen[cs]e agreement|fda approv|high[- ]grade|maiden (?:resource|profit|dividend)", re.I)
NEG = re.compile(r"profit warning|below (?:market |board )?expectations|placing|fundrais|subscription|open offer|retail offer|"
                 r"going concern|suspension|delist|cancellation of (?:admission|trading)|administration|lapse|withdraw|"
                 r"impairment|covenant|default|challenging|weaker than|lower than expected|delay|downgrade|guidance cut|"
                 r"reduce[sd]? (?:guidance|forecast)", re.I)
DILUTE = re.compile(r"placing|fundrais|subscription|open offer|retail offer|going concern|convertible|warrant", re.I)
BIDWORD = re.compile(r"offer|rule 2\.|approach|takeover|scheme of arrangement|sale process|strategic review", re.I)
NOISE = re.compile(r"form[- ]8|total[- ]voting[- ]rights|block[- ]listing|transaction[s]?[- ]in[- ]own[- ]shares|net[- ]asset[- ]value|"
                   r"holding\(?s\)?[- ]in[- ]company|final[- ]terms|director[- ]declaration|notice[- ]of[- ]|result[- ]of[- ](?:agm|gm|general)|"
                   r"publication[- ]of[- ](?:a[- ])?(?:prospectus|supplementary|final)|statement[- ]re[- ]press|price[- ]monitoring|"
                   r"redemption|annual[- ]financial[- ]report|tender[- ]offer|issue[- ]of[- ]debt|nav\b|rule[- ]2\.9|interest[- ]rate|"
                   r"employee[- ]benefit|exercise[- ]of[- ]options|grant[- ]of[- ]options", re.I)


def log(*a):
    print(*a, file=sys.stderr, flush=True)


def get(url, **kw):
    for i in range(3):
        try:
            r = requests.get(url, headers=UA, timeout=30, **kw)
            if r.status_code == 200:
                return r.text
            log("HTTP", r.status_code, url)
        except Exception as e:
            log("fetch error", url, e)
        time.sleep(2 + 3 * i)
    return None


def load_state():
    try:
        with open(STATE_F, encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {}


# ---------------------------------------------------------------- universe
def wiki_tickers(url, suffix="", min_rows=50):
    html = get(url)
    if not html:
        return []
    try:
        tables = pd.read_html(io.StringIO(html))
    except Exception as e:
        log("read_html", url, e)
        return []
    for t in tables:
        if len(t) < min_rows:
            continue
        cols = {str(c).lower().strip(): c for c in t.columns}
        name_col = next((cols[k] for k in cols if k in ("company", "security", "name")), None)
        for key in ("ticker", "symbol", "epic", "ticker symbol"):
            if key in cols:
                out = []
                for _, row in t.iterrows():
                    s = str(row[cols[key]]).strip().upper()
                    if not s or s == "NAN":
                        continue
                    s = s.split(":")[-1].strip().rstrip(".")
                    y = s.replace(".", "-") + suffix
                    out.append((s, y, str(row[name_col]) if name_col is not None else s))
                return out
    log("no ticker table found", url)
    return []


def build_universe(state):
    uni = {}  # yahoo -> dict(t, n, m)

    def add(t, y, n, m, force=False):
        if y in uni and not force:
            return
        uni[y] = {"t": t, "n": n, "m": m}

    for t, y, n in wiki_tickers("https://en.wikipedia.org/wiki/List_of_S%26P_600_companies", min_rows=300):
        add(t, y, n, "US")
    for t, y, n in wiki_tickers("https://en.wikipedia.org/wiki/List_of_S%26P_500_companies", min_rows=400):
        add(t, y, n, "US Large", force=True)
    for t, y, n in wiki_tickers("https://en.wikipedia.org/wiki/List_of_NASDAQ-100_companies", min_rows=90) or \
            wiki_tickers("https://en.wikipedia.org/wiki/Nasdaq-100", min_rows=90):
        add(t, y, n, "US Large", force=True)
    for t, y, n in wiki_tickers("https://en.wikipedia.org/wiki/FTSE_250_Index", ".L", min_rows=200):
        add(t, y, n, "FTSE", force=True)
    for t, y, n in wiki_tickers("https://en.wikipedia.org/wiki/FTSE_100_Index", ".L", min_rows=90):
        add(t, y, n, "FTSE", force=True)

    # UK tickers seen in recent RNS (mostly AIM / small caps)
    cutoff = (NOW - timedelta(days=UK_SEEN_DAYS)).isoformat()
    for t, info in state.get("uk_seen", {}).items():
        if info.get("last", "") >= cutoff:
            add(t, t.rstrip(".").replace(".", "-") + ".L", info.get("n", t), "UK small")

    # watchlist last, so its market labels win
    wl = os.path.join(ROOT, "watchlist.csv")
    if os.path.exists(wl):
        with open(wl, encoding="utf-8") as f:
            for r in csv.DictReader(f):
                if r.get("yahoo"):
                    add(r["ticker"], r["yahoo"].strip(), r.get("name") or r["ticker"], r.get("market") or "UK small", force=True)
                    uni[r["yahoo"].strip()]["wl"] = True
    bad = set(state.get("bad", []))
    for y in list(uni):
        if y in bad and not uni[y].get("wl"):
            del uni[y]
    return uni


# ---------------------------------------------------------------- RNS
def parse_investegate(html):
    out = []
    soup = BeautifulSoup(html, "lxml")
    for a in soup.find_all("a", href=True):
        m = re.search(r"/announcement/(\w+)/([a-z0-9\-]+?)--+([a-z0-9]{1,6})/([a-z0-9\-]+)/(\d+)", a["href"])
        if not m:
            continue
        src, comp, tick, slug, aid = m.groups()
        text = a.get_text(" ", strip=True)
        head = text if len(text) > 6 and not text.lower().startswith(("read", "view")) else slug.replace("-", " ").capitalize()
        href = a["href"] if a["href"].startswith("http") else "https://www.investegate.co.uk" + a["href"]
        out.append({"id": int(aid), "t": tick.upper(), "c": comp.replace("-", " ").title(), "h": head[:140],
                    "slug": slug, "u": href})
    return out


def fetch_rns(state):
    seen = set(state.get("rns_ids", []))
    new = []
    for p in range(1, RNS_PAGES + 1):
        html = get("https://www.investegate.co.uk/" + (f"?page={p}" if p > 1 else ""))
        if not html:
            break
        items = parse_investegate(html)
        if not items:
            log("investegate: no links parsed on page", p)
            break
        fresh = [i for i in items if i["id"] not in seen]
        for i in fresh:
            seen.add(i["id"])
            i["d"] = NOW.isoformat(timespec="minutes")
            new.append(i)
        if not fresh:
            break
        time.sleep(1.5)
    # dedupe within run
    uniq = {}
    for i in new:
        uniq[i["id"]] = i
    new = list(uniq.values())
    state["rns_ids"] = sorted(seen)[-6000:]
    return new


def classify(text):
    pos = sorted({m.group(0).lower() for m in POS.finditer(text)})
    neg = sorted({m.group(0).lower() for m in NEG.finditer(text)})
    return pos[:4], neg[:4]


def body_keywords(url):
    html = get(url)
    if not html:
        return [], [], False
    soup = BeautifulSoup(html, "lxml")
    for s in soup(["script", "style", "nav", "header", "footer"]):
        s.decompose()
    txt = " ".join(soup.get_text(" ").split())[:6000]
    pos, neg = classify(txt)
    return pos, neg, bool(DILUTE.search(txt))


# ---------------------------------------------------------------- takeovers
def fetch_takeovers(state):
    # The Panel publishes the table as CSV: a "changes today" block, then a
    # "DISCLOSURE TABLE" heading, then blocks of "OFFEREE: x" / "OFFEROR: y" rows.
    text = get("https://www.thetakeoverpanel.org.uk/new/disclosureTable/v3/disclosuretable.csv")
    if not text:
        return None
    by = {}
    try:
        in_table, cur_offeree = False, None
        for row in csv.reader(io.StringIO(text)):
            cell = (row[0] if row else "").strip()
            if not in_table:
                in_table = cell.upper() == "DISCLOSURE TABLE"
                continue
            if cell.upper().startswith("NOTES"):
                break
            if cell.upper().startswith("OFFEREE:"):
                cur_offeree = cell.split(":", 1)[1].strip()
                by.setdefault(cur_offeree, set())
            elif cell.upper().startswith("OFFEROR:") and cur_offeree:
                by[cur_offeree].add(cell.split(":", 1)[1].strip())
    except Exception as e:
        log("takeover parse", e)
    if not by:
        log("takeover table: no offerees parsed")
        return None
    cur = {k: sorted(x for x in v if x) for k, v in by.items()}
    prev = state.get("takeovers", {})
    added = sorted(set(cur) - set(prev)) if prev else []
    removed = sorted(set(prev) - set(cur)) if prev else []
    new_bidder = sorted(k for k in cur if k in prev and set(cur[k]) - set(prev[k]))
    hist = state.get("takeover_log", [])
    day = NOW.strftime("%Y-%m-%d")
    for k in added:
        hist.insert(0, {"d": day, "what": f"ADDED: {k}" + (f" (offeror: {', '.join(cur[k])})" if cur[k] else " (no named offeror)")})
    for k in removed:
        hist.insert(0, {"d": day, "what": f"REMOVED: {k} — offer period ended (completed, lapsed or withdrawn)"})
    for k in new_bidder:
        hist.insert(0, {"d": day, "what": f"NEW OFFEROR: {k} — {', '.join(sorted(set(cur[k]) - set(prev[k])))}"})
    state["takeovers"] = cur
    state["takeover_log"] = hist[:60]
    return {"table": [{"offeree": k, "offerors": v} for k, v in sorted(cur.items())],
            "added": added, "removed": removed, "new_offeror": new_bidder, "log": hist[:30]}


# ---------------------------------------------------------------- prices
def download(symbols):
    frames = {}
    syms = list(symbols)
    for i in range(0, len(syms), 150):
        chunk = syms[i:i + 150]
        for attempt in range(2):
            try:
                df = yf.download(chunk, period="1y", interval="1d", auto_adjust=False, group_by="ticker",
                                 threads=True, progress=False)
                break
            except Exception as e:
                log("yf chunk error", e)
                df = None
                time.sleep(5)
        if df is None or df.empty:
            continue
        for s in chunk:
            try:
                sub = df[s] if isinstance(df.columns, pd.MultiIndex) else df
                sub = sub.dropna(subset=["Close"])
                if len(sub) >= 30:
                    frames[s] = sub
            except Exception:
                pass
        time.sleep(1)
    return frames


def rsi(close, n=14):
    d = close.diff()
    up = d.clip(lower=0).ewm(alpha=1 / n, adjust=False).mean()
    dn = (-d.clip(upper=0)).ewm(alpha=1 / n, adjust=False).mean()
    rs = up / dn.replace(0, math.nan)
    return 100 - 100 / (1 + rs)


def metrics(df):
    c, h, v = df["Close"], df["High"], df["Volume"].fillna(0)
    last = float(c.iloc[-1])
    prev = float(c.iloc[-2])
    n = len(c)
    hi_prior = float(h.iloc[:-1].tail(250).max())
    lo52 = float(df["Low"].tail(250).min())
    ma50 = float(c.tail(50).mean()) if n >= 50 else None
    ma200 = float(c.tail(200).mean()) if n >= 200 else None
    av50 = float(v.iloc[:-1].tail(50).mean()) or 0.0
    rv = float(v.iloc[-1]) / av50 if av50 > 0 else None
    c5 = float(c.iloc[-6]) if n >= 6 else None
    c20 = float(c.iloc[-21]) if n >= 21 else None
    r = rsi(c)
    return {
        "px": last, "d1": (last / prev - 1) * 100 if prev else None,
        "d5": (last / c5 - 1) * 100 if c5 else None, "d20": (last / c20 - 1) * 100 if c20 else None,
        "hi": hi_prior, "fromHi": (last / hi_prior - 1) * 100 if hi_prior else None,
        "lo": lo52, "fromLo": (last / lo52 - 1) * 100 if lo52 else None,
        "ma50": ma50, "ma200": ma200, "rv": rv, "rsi": float(r.iloc[-1]) if not math.isnan(r.iloc[-1]) else None,
        "avgval": av50 * float(c.tail(50).mean()), "date": str(df.index[-1].date()),
        "newHigh": last >= hi_prior,
    }


def r1(x, n=1):
    return None if x is None or (isinstance(x, float) and math.isnan(x)) else round(x, n)


def price_label(px, uk):
    if px is None:
        return "—"
    if uk:
        s = f"{px:,.0f}" if px >= 100 else (f"{px:.2f}".rstrip("0").rstrip(".") if px >= 1 else f"{px:.3f}".rstrip("0").rstrip("."))
        return s + "p"
    return f"${px:,.2f}"


# ---------------------------------------------------------------- main
def main():
    os.makedirs(DATA, exist_ok=True)
    state = load_state()

    # 1. RNS first (feeds the universe)
    rns_new = fetch_rns(state)
    log("new RNS", len(rns_new))
    uk_seen = state.get("uk_seen", {})
    for i in rns_new:
        if NOISE.search(i["slug"]) or NOISE.search(i["h"]):
            i["noise"] = True
            continue
        uk_seen.setdefault(i["t"], {"n": i["c"]})
        uk_seen[i["t"]]["last"] = NOW.isoformat()
        uk_seen[i["t"]]["n"] = i["c"]
    state["uk_seen"] = uk_seen
    rns_log = [x for x in state.get("rns_log", []) if x["d"] >= (NOW - timedelta(days=RNS_KEEP_DAYS)).isoformat()]
    for i in rns_new:
        if i.get("noise"):
            continue
        pos, neg = classify(i["h"] + " " + i["slug"].replace("-", " "))
        rns_log.insert(0, {"d": i["d"], "id": i["id"], "t": i["t"], "c": i["c"], "h": i["h"], "u": i["u"],
                           "pos": pos, "neg": neg, "bid": bool(BIDWORD.search(i["h"] + " " + i["slug"]))})
    rns_log.sort(key=lambda x: x["id"], reverse=True)

    # 2. takeovers
    tko = fetch_takeovers(state)

    # 3. prices
    uni = build_universe(state)
    log("universe", len(uni))
    frames = download(uni.keys()) if yf else {}
    log("priced", len(frames))
    if uni and len(frames) < 0.2 * len(uni):
        log("WARNING: few prices downloaded")
    got = set(frames)
    bad = set(state.get("bad", []))
    for y in uni:
        if y not in got and y.endswith(".L") and not uni[y].get("wl"):
            bad.add(y)
    state["bad"] = sorted(bad)[-3000:]

    # RNS by ticker over the last 3 days (for "news-driven" joins)
    recent_cut = (NOW - timedelta(days=3)).isoformat()
    news_by_t = {}
    for x in rns_log:
        if x["d"] >= recent_cut:
            news_by_t.setdefault(x["t"], []).append(x)
    neg_10d = {x["t"] for x in rns_log if x["neg"]}

    rows = []
    for y, df in frames.items():
        try:
            mt = metrics(df)
        except Exception:
            continue
        info = uni[y]
        uk = y.endswith(".L")
        val = mt["avgval"] / (100 if uk else 1)
        liquid = val >= (MIN_VALUE_UK if uk else MIN_VALUE_US)
        t = info["t"]
        row = {"t": t, "y": y, "n": info["n"][:60], "m": info["m"], "wl": bool(info.get("wl")), "uk": uk,
               "px": r1(mt["px"], 4), "pxs": price_label(mt["px"], uk), "date": mt["date"],
               "d1": r1(mt["d1"]), "d5": r1(mt["d5"]), "d20": r1(mt["d20"]), "fromHi": r1(mt["fromHi"]),
               "fromLo": r1(mt["fromLo"]), "rv": r1(mt["rv"]), "rsi": r1(mt["rsi"], 0),
               "ma50": r1(mt["ma50"], 4), "ma200": r1(mt["ma200"], 4), "liquid": liquid,
               "aboveMA50": mt["ma50"] is not None and mt["px"] > mt["ma50"],
               "trendUp": mt["ma50"] is not None and mt["ma200"] is not None and mt["ma50"] > mt["ma200"],
               "newHigh": mt["newHigh"], "val": round(val)}
        if uk and t in news_by_t:
            row["news"] = [{"h": x["h"], "u": x["u"], "pos": x["pos"], "neg": x["neg"]} for x in news_by_t[t][:3]]
        rows.append(row)

    # 4. signals
    def score_break(r):
        return (r["rv"] or 0) * 10 + (r["d1"] or 0) + (5 if r["trendUp"] else 0)

    breakouts = [r for r in rows if r["liquid"] and r["newHigh"] and (r["rv"] or 0) >= 1.5 and (r["d1"] or 0) > 0
                 and r["aboveMA50"]]
    breakouts.sort(key=score_break, reverse=True)
    testing = [r for r in rows if r["liquid"] and not r["newHigh"] and r["fromHi"] is not None and r["fromHi"] >= -3
               and r["trendUp"] and (r["rsi"] or 0) < 78]
    testing.sort(key=lambda r: r["fromHi"], reverse=True)
    oversold = [r for r in rows if r["liquid"] and r["d5"] is not None and r["d5"] <= -10 and (r["rsi"] or 50) <= 35
                and r["t"] not in neg_10d and (r["fromLo"] or 0) > 5]
    oversold.sort(key=lambda r: r["d5"])
    movers = [r for r in rows if r["liquid"] and r["d1"] is not None and abs(r["d1"]) >= (5 if r["uk"] else 8)
              and (r["rv"] or 0) >= 1.5]
    movers.sort(key=lambda r: abs(r["d1"]), reverse=True)

    # open announcements for UK movers with news, to read keywords (ahead of expectations, placing...)
    fetched = 0
    for r in movers:
        if fetched >= MAX_BODY_FETCH:
            break
        if r["uk"] and r.get("news"):
            for nn in r["news"][:1]:
                pos, neg, dil = body_keywords(nn["u"])
                nn["pos"] = sorted(set(nn["pos"]) | set(pos))[:5]
                nn["neg"] = sorted(set(nn["neg"]) | set(neg))[:5]
                nn["dilution"] = dil
                fetched += 1
                time.sleep(1)

    for r in breakouts:
        r["sig"] = "Breaking out"
    for r in testing:
        r.setdefault("sig", "Testing")
    for r in oversold:
        r.setdefault("sig", "Oversold")

    wl_rows = sorted([r for r in rows if r["wl"]], key=lambda r: (r["m"], r["t"]))
    wl_alerts = [r for r in wl_rows if (r["d1"] is not None and abs(r["d1"]) >= 5) or r["newHigh"]
                 or (r["ma50"] and r["px"] < r["ma50"] and (r["d1"] or 0) <= -3)]

    def slim(lst, k):
        return lst[:k]

    auto = {
        "generated": NOW.strftime("%Y-%m-%d %H:%M UTC"),
        "counts": {"universe": len(uni), "priced": len(frames), "rns_new": len(rns_new)},
        "movers": slim(movers, 60), "breakouts": slim(breakouts, 50), "testing": slim(testing, 50),
        "oversold": slim(oversold, 40), "watchlist": wl_rows, "wlAlerts": wl_alerts,
        "rns": [x for x in rns_log][:400], "takeovers": tko or {"table": [], "added": [], "removed": [], "new_offeror": [],
                                                              "log": state.get("takeover_log", [])[:30], "error": True},
    }
    state["rns_log"] = rns_log[:1500]

    def d(r):
        o = {k: r.get(k) for k in ("t", "m", "n", "pxs", "d1", "d5", "fromHi", "rv", "rsi", "sig")}
        if r.get("news"):
            o["news"] = [{"h": x["h"], "pos": x.get("pos"), "neg": x.get("neg"), "u": x["u"]} for x in r["news"][:2]]
        return o

    rns_hits = [x for x in rns_log if x["d"] >= (NOW - timedelta(hours=26)).isoformat() and (x["pos"] or x["neg"] or x["bid"])]
    digest = {
        "generated": auto["generated"],
        "note": "Shortlist from the free auto-scan. Prices from Yahoo (delayed, end of day for the last bar). Verify before use.",
        "takeovers": {k: auto["takeovers"].get(k) for k in ("added", "removed", "new_offeror")},
        "movers": [d(r) for r in movers[:25]], "breakouts": [d(r) for r in breakouts[:20]],
        "testing": [d(r) for r in testing[:12]], "oversold": [d(r) for r in oversold[:15]],
        "watchlistAlerts": [d(r) for r in wl_alerts[:30]],
        "rnsKeywordHits": [{"t": x["t"], "h": x["h"], "pos": x["pos"], "neg": x["neg"], "bid": x["bid"], "u": x["u"]}
                           for x in rns_hits[:40]],
    }
    with open(os.path.join(DATA, "auto.json"), "w", encoding="utf-8") as f:
        json.dump(auto, f, ensure_ascii=False, separators=(",", ":"))
    with open(os.path.join(DATA, "digest.json"), "w", encoding="utf-8") as f:
        json.dump(digest, f, ensure_ascii=False, indent=1)
    with open(STATE_F, "w", encoding="utf-8") as f:
        json.dump(state, f, ensure_ascii=False, separators=(",", ":"))
    log("done", auto["counts"], "breakouts", len(breakouts), "oversold", len(oversold), "movers", len(movers))


if __name__ == "__main__":
    main()
