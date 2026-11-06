"""A karácsonyi posztnaptár (kozossegi/naptar.md) feltöltése a SEO OS Növekedési gépére: Facebook + Instagram, időre,
kész posztként (a gép nem írja át a szöveget). Áron, 2026-11-06: „A helloprovisionnel megy a posztolás is automatikusan.
Csináld meg ide is.”

Futtatás: HPV_REMOTE_CRM_URL / _USER / _PASS (a CRM, amelyen át a SEO OS elérhető), majd
    python3 social_schedule.py --project <SEO OS projekt id> [--dry] [--only 1,2]
Újrafuttatható: ugyanaz a poszt (szám + csatorna + időpont) felülíródik és újra előkészül; ami már kiment, azt nem bántja.
Ha a naptar.md szövege változik, futtasd újra.

Kézzel marad: a story (a gép nem tud storyt), a 12. (csapatfotó, nevekkel) és a Cégprofil. A lapozós posztokból (3., 10.)
a borítókártya megy ki egy képként.
"""

import argparse
import base64
import json
import os
import re
import sys
import urllib.error
import urllib.request
from datetime import datetime, timedelta

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.join(HERE, '..')
SITE = 'https://mandala.hu'
FUST, AKC, HANG, AJ, UTALV = '/kategoria/szakralis-targyak/fustolok/', '/termekek/?allapot=akcios', '/kategoria/szakralis-targyak/hangtalak/', '/termekek/?szandek=ajandek', '/ajandekcsomag/'

# szám: (helyi idő, Budapest, kép a kampánymappától, link útvonala)
POSTS = {
    1: ('2026-11-12 19:00', 'kepek/1-fustolo-het-negyzet-1080x1080.jpg', FUST),
    2: ('2026-11-13 09:00', 'kepek/1-fustolo-het-hirfolyam-1080x1350.jpg', FUST),
    3: ('2026-11-16 19:00', 'kozossegi/kepek/illatok-0.jpg', FUST),
    4: ('2026-11-20 19:00', 'kepek/1-fustolo-het-negyzet-1080x1080.jpg', FUST),
    5: ('2026-11-23 09:00', 'kepek/2-black-week-hirfolyam-1080x1350.jpg', AKC),
    6: ('2026-11-25 19:00', 'kepek/2-black-week-negyzet-1080x1080.jpg', HANG),
    7: ('2026-11-27 08:00', 'kepek/3-black-friday-hirfolyam-1080x1350.jpg', AKC),
    8: ('2026-11-30 12:00', 'kepek/2-black-week-negyzet-1080x1080.jpg', AKC),
    9: ('2026-12-01 09:00', 'kepek/4-mikulas-hirfolyam-1080x1350.jpg', AJ),
    10: ('2026-12-02 19:00', 'kozossegi/kepek/ajandekok-0.jpg', AJ),
    11: ('2026-12-07 09:00', 'kepek/5-karacsony-hirfolyam-1080x1350.jpg', AJ),
    13: ('2026-12-16 19:00', 'kepek/5-karacsony-negyzet-1080x1080.jpg', AJ),
    14: ('2026-12-18 12:30', 'kepek/6-utalvany-hirfolyam-1080x1350.jpg', UTALV),
    15: ('2026-12-21 19:00', 'kepek/6-utalvany-negyzet-1080x1080.jpg', UTALV),
}


def link(path, n, source):
    sep = '&' if '?' in path else '?'
    return f'{SITE}{path}{sep}utm_source={source}&utm_medium=social&utm_campaign=2026-karacsony&utm_content={n}'


def texts():
    """A naptar.md posztszövegei számonként (a „## N.” fejezet első ```text blokkja)."""
    md = open(os.path.join(ROOT, 'kozossegi', 'naptar.md'), encoding='utf-8').read()
    out = {}
    for m in re.finditer(r'^## (\d+)\. .*?\n```text\n(.*?)\n```', md, re.S | re.M):
        out[int(m.group(1))] = m.group(2).strip()
    return out


def utc(local):
    # Budapest novemberben–decemberben téli időben van (UTC+1).
    return (datetime.strptime(local, '%Y-%m-%d %H:%M') - timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M:00Z')


def variants(n, text):
    """Facebook: a link a gép teszi a szöveg végére (👉), ezért a rövid link sor nélkül. Instagram: a link nem
    kattintható – a rövid link marad, különben „A link a bióban.”"""
    fb = re.sub(r':\s*mandala\.hu/\S+\s*$', '.', text)
    ig = text if 'mandala.hu/' in text else text + '\n\nA link a bióban.'
    return fb, ig


def call(method, path, data=None):
    url = os.environ['HPV_REMOTE_CRM_URL'].rstrip('/')
    auth = 'Basic ' + base64.b64encode((os.environ['HPV_REMOTE_CRM_USER'] + ':' + os.environ['HPV_REMOTE_CRM_PASS']).encode()).decode()
    req = urllib.request.Request(url + '/?rest_route=/hpv-seo/v1' + path, method=method, data=json.dumps(data).encode() if data is not None else None,
                                 headers={'Authorization': auth, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=180) as r:
            return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        b = e.read()
        try:
            return e.code, json.loads(b)
        except Exception:
            return e.code, b[:400].decode('utf-8', 'replace')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--project', type=int, required=True)
    ap.add_argument('--dry', action='store_true')
    ap.add_argument('--only', default='')
    ap.add_argument('--channels', default='facebook,instagram')
    a = ap.parse_args()
    only = {int(x) for x in a.only.split(',') if x.strip()}
    tx = texts()
    bad = 0
    for n, (when, img, path) in POSTS.items():
        if only and n not in only:
            continue
        fb, ig = variants(n, tx[n])
        b64 = base64.b64encode(open(os.path.join(ROOT, img), 'rb').read()).decode()
        for ch in a.channels.split(','):
            text = fb if ch == 'facebook' else ig
            body = {'channel': ch, 'scheduled_at': utc(when), 'format': 'fixed', 'key': f'xmas26-{n:02d}',
                    'source': {'text': text, 'link': link(path, n, ch), 'image_b64': b64, 'title': f'Karácsony 2026 – {n}. ' + text.split('\n', 1)[0][:80]}}
            if a.dry:
                print(f'{n:>2} {ch:<9} {when} {img}  {len(text)} kar.  {body["source"]["link"]}')
                continue
            st, res = call('POST', f'/projects/{a.project}/growth/extra', body)
            ok = st == 200
            bad += not ok
            print(f'{n:>2} {ch:<9} {when}  {st}  ' + (f'#{res.get("id")} {res.get("status")}' if ok else str(res)[:300]))
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
