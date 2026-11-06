# Mandala karácsony 2026 – hirdetések szakaszonként (Hirdetéskezelő, profil 30001). Használat: python3 ads_phase.py <szakasz> [--dry]
# Szakaszok: fust (nov. 13.), bw (nov. 23.), bf (nov. 27.), bw2 (nov. 28.), mikulas (dec. 1.), karacsony (dec. 3. 12:00),
# utalvany (dec. 18. 12:00), vege (dec. 24. 12:00). Kreatívcsere az A1 és A3 hirdetésen (v4), napi keretek a terv szerint
# (docs/kampany/2026-karacsony, 9. oldal). Ha a remarketing az elmúlt 3 napban nem költött, a keretét a v4 kapja.
import base64, json, os, sys, time, datetime, urllib.request, urllib.error

# A Hirdetéskezelő REST-je a mi CRM-ünkön (a munkamenet környezeti változóiból: HPV_REMOTE_CRM_URL / _USER / _PASS).
URL = os.environ['HPV_REMOTE_CRM_URL'].rstrip('/')
AUTH = 'Basic ' + base64.b64encode((os.environ['HPV_REMOTE_CRM_USER'] + ':' + os.environ['HPV_REMOTE_CRM_PASS']).encode()).decode()


def call(method, path, data=None):
    req = urllib.request.Request(URL + '/?rest_route=/hpv-ads/v1' + path, method=method, data=json.dumps(data).encode() if data is not None else None,
                                 headers={'Authorization': AUTH, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        b = e.read()
        try:
            return e.code, json.loads(b)
        except Exception:
            return e.code, b[:400].decode('utf-8', 'replace')

K = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'kepek') + '/'
P = 30001
A1, A3 = '120251392830710052', '120251392834870052'
V4, RT = '120251392827160052', '120251392904710052'
PMAX, SEARCH = '24313899581', '24311488236'
SITE = 'https://mandala.hu'
FUST, AKC, HANG, AJ, UTALV = '/kategoria/szakralis-targyak/fustolok/', '/termekek/?allapot=akcios', '/kategoria/szakralis-targyak/hangtalak/', '/termekek/?szandek=ajandek', '/ajandekcsomag/'


def link(path, content):
    sep = '&' if '?' in path else '?'
    return f'{SITE}{path}{sep}utm_source=facebook&utm_medium=paid_social&utm_campaign=2026-karacsony&utm_content={content}'


# szakasz → kreatívok (hirdetés → kép, szöveg, cím, leírás, cél) és keretek (Ft/nap)
PH = {
    'fust': dict(ads={
        A3: ('1-fustolo-het-hirfolyam-1080x1350.jpg', 'Egyet fizetsz, kettőt viszel – minden füstölőre. Két csomagból az olcsóbbat mi álljuk, november 22-ig.', '1+1 füstölő – november 22-ig', 'Mandala · Budapest', FUST, 'xmas-fust-a3'),
        A1: ('1-fustolo-het-negyzet-1080x1080.jpg', 'Nag Champa, Palo Santo, fehér zsálya, szantálfa… Több mint 400 illat közül választhatsz, és minden második csomag a miénk.', 'Egyet fizetsz, kettőt viszel', 'November 22-ig', FUST, 'xmas-fust-a1'),
    }, budget={'v4': 5000, 'rt': 2000, 'pmax': 7000, 'search': 2000}),
    'bw': dict(ads={
        A3: ('2-black-week-hirfolyam-1080x1350.jpg', 'Black Week a Mandalában: szinte mindenre 20%, a hangtálakra 10%. Kézzel válogatott darabok Nepálból és Indiából.', 'Black Week: −20% szinte mindenre', 'November 30-ig', AKC, 'xmas-bw-a3'),
        A1: ('2-black-week-negyzet-1080x1080.jpg', 'Hangtál, mala, szobor, füstölőtartó – a Black Week alatt 20%-kal olcsóbban. A füstölőkre marad az egyet fizetsz, kettőt viszel.', 'Hangtálak most −10%', 'November 30-ig', AKC, 'xmas-bw-a1'),
    }, budget={'v4': 8000, 'rt': 5000, 'pmax': 14000, 'search': 3000}),
    'bf': dict(ads={
        A1: ('3-black-friday-hirfolyam-1080x1350.jpg', 'Csak ma: bármekkora rendelésnél ingyenes a szállítás – a Black Week kedvezményei mellé. Éjfélig.', 'Ma ingyenes a szállítás', 'Black Friday · éjfélig', AKC, 'xmas-bf-a1'),
    }, budget={'v4': 8000, 'rt': 5000, 'pmax': 14000, 'search': 3000}),
    'bw2': dict(ads={
        A1: ('2-black-week-negyzet-1080x1080.jpg', 'Ha karácsonyra keresel valami különlegeset, most érdemes: november 30-ig szinte mindenre 20% kedvezmény.', 'Black Week: −20% szinte mindenre', 'November 30-ig', AKC, 'xmas-bw2-a1'),
    }, budget={'v4': 8000, 'rt': 5000, 'pmax': 14000, 'search': 3000}),
    'mikulas': dict(ads={
        A3: ('4-mikulas-hirfolyam-1080x1350.jpg', 'Mikulásra december 3-án 12 óráig rendelj: díszcsomagolásban, kézzel írt kártyával küldjük – ez most nem kerül semmibe.', 'Ajándék a csizmába', 'Rendelj dec. 3., 12 óráig', AJ, 'xmas-mikulas-a3'),
        A1: ('4-mikulas-negyzet-1080x1080.jpg', 'Apró ajándék, ami jelent is valamit: mala karkötő, füstölő, kis szobor. 15 000 Ft felett egy füstölőt mi teszünk mellé.', 'Díszcsomagolás ajándékba', 'Rendelj dec. 3., 12 óráig', AJ, 'xmas-mikulas-a1'),
    }, budget={'v4': 10000, 'rt': 6000, 'pmax': 16500, 'search': 3500}),
    'karacsony': dict(ads={
        A3: ('5-karacsony-hirfolyam-1080x1350.jpg', 'Ajándék, ami jelent is valamit. Füstölő, mala, hangtál, réz kulacs – díszcsomagolásban, kézzel írt kártyával.', 'Karácsonyra december 18-ig', 'Díszcsomagolás ajándékba', AJ, 'xmas-karacsony-a3'),
        A1: ('5-karacsony-negyzet-1080x1080.jpg', 'Ami december 18-án 12 óráig beérkezik, karácsonyig odaér. A díszcsomagolás ajándék, 15 000 Ft felett egy füstölő is.', 'Ajándék, ami jelent is valamit', 'Rendelj dec. 18., 12 óráig', AJ, 'xmas-karacsony-a1'),
    }, budget={'v4': 10000, 'rt': 6000, 'pmax': 16500, 'search': 3500}),
    'utalvany': dict(ads={
        A3: ('6-utalvany-hirfolyam-1080x1350.jpg', 'Elfogyott az idő? Az ajándékutalvány e-mailben érkezik, nyomtatható is – és ő választ a teljes kínálatból.', 'Ajándékutalvány e-mailben', 'Részletekben is beváltható', UTALV, 'xmas-utalvany-a3'),
    }, budget={'v4': 5000, 'rt': 1500, 'pmax': 2000, 'search': 1000}),
    # a kampány vége: a két szezonális hirdetés szünetel, a keretek a kampány előtti szintre
    'vege': dict(ads={}, pause=[A1, A3], budget={'v4': 3500, 'rt': 1500, 'pmax': 10000, 'search': 2500}),
}


def job(st, r):
    jid = (r or {}).get('job', {}).get('id') if isinstance(r, dict) else None
    jid = jid or ((r or {}).get('id') if isinstance(r, dict) and r.get('status') in ('queued', 'running') else None)
    if not jid:
        return st, r
    for _ in range(60):
        time.sleep(5)
        s2, j = call('GET', f'/jobs/{jid}')
        if isinstance(j, dict) and j.get('status') in ('done', 'error', 'failed'):
            return s2, j
    return 0, {'error': 'időtúllépés', 'job': jid}


def rt_spends():
    to = datetime.date.today()
    fr = to - datetime.timedelta(days=3)
    st, r = call('GET', f'/profiles/{P}/campaigns&platform=meta&preset=custom&from={fr}&to={to}')
    for c in (r or {}).get('campaigns', []) if isinstance(r, dict) else []:
        if c.get('ext_id') == RT:
            return float(c.get('cost') or 0)
    return 0.0


def main(phase, dry=False):
    ph = PH[phase]
    log = {'phase': phase, 'at': datetime.datetime.now().isoformat(timespec='seconds'), 'ads': {}, 'budget': {}, 'pause': {}}
    for ad, (img, primary, headline, desc, path, content) in ph['ads'].items():
        b64 = base64.b64encode(open(K + img, 'rb').read()).decode()
        body = {'ad': ad, 'creative': {'name': f'MAN | Karácsony 2026 | {phase} | {content}', 'link': link(path, content), 'cta': 'SHOP_NOW',
                                       'primary_text': primary, 'headline': headline, 'description': desc, 'image': 'img'},
                'images': {'img': {'base64': b64, 'name': img}}}
        if dry:
            log['ads'][ad] = {'dry': True, 'img': img, 'headline': headline, 'link': body['creative']['link']}
            continue
        log['ads'][ad] = job(*call('POST', f'/profiles/{P}/meta-service/ad-creative', body))
    for ad in ph.get('pause', []):
        log['pause'][ad] = 'dry' if dry else call('POST', f'/profiles/{P}/action', {'action': {'type': 'meta_status', 'object': ad, 'status': 'PAUSED'}})
    b = dict(ph['budget'])
    rt = rt_spends()
    log['rt_spend_3d'] = rt
    if rt < 300 and phase != 'vege':  # a remarketing nem tud költeni → a v4 kapja a keretét (a remarketing marad minimumon)
        b['v4'] += b['rt'] - 1000
        b['rt'] = 1000
    for key, obj, typ, fld in [('v4', V4, 'meta_budget', 'object'), ('rt', RT, 'meta_budget', 'object'), ('pmax', PMAX, 'google_budget', 'campaign'), ('search', SEARCH, 'google_budget', 'campaign')]:
        act = {'type': typ, fld: obj, 'amount': b[key]}
        log['budget'][key] = {'amount': b[key], 'dry': True} if dry else {'amount': b[key], 'result': call('POST', f'/profiles/{P}/action', {'action': act})}
    logdir = os.environ.get('XMAS_ADS_LOG', '/tmp')
    json.dump(log, open(os.path.join(logdir, f'xmas-ads-{phase}{"-dry" if dry else ""}.json'), 'w'), ensure_ascii=False, indent=1, default=str)
    print(json.dumps(log, ensure_ascii=False, default=str)[:3000])


if __name__ == '__main__':
    main(sys.argv[1], '--dry' in sys.argv)
