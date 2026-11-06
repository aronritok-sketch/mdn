# Nagyker kampány („Nyitott raktár”) AI-jelenetei a Mandala valódi termékfotóiból (Ads mb_image → SEO OS kreatívgyár).
# Használat: gen.py <kulcs> [<formátum> ...]   (formátum: portrait | story | square)
import json, os, sys, base64, urllib.request, urllib.error, time
URL = os.environ['HPV_REMOTE_CRM_URL'].rstrip('/')
AUTH = 'Basic ' + base64.b64encode((os.environ['HPV_REMOTE_CRM_USER'] + ':' + os.environ['HPV_REMOTE_CRM_PASS']).encode()).decode()
D = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
U = 'https://mandala.hu/wp-content/uploads/'
P = {
 'buddha': ('brass Shakyamuni Buddha statue seated on a lotus base', [U + '2026/09/9990000024919.webp']),
 'tara': ('brass White Tara statue seated on a lotus base, ornate crown', [U + '2026/09/9990000028665.webp']),
 'hangtal': ('hand-hammered brass singing bowl with a dark band', [U + '2026/09/9990000048809.webp']),
 'amber': ('Sandesh Holy Amber incense box: tall orange box with red mandala pattern', [U + '2026/09/8902276004367.webp']),
 'palosanto': ('Sandesh Palo Santo incense box: tall multicoloured patterned box', [U + '2026/09/8902276004183.webp']),
 'zaszlo': ('string of Tibetan prayer flags in five colours: blue, white, red, green, yellow', [U + '2026/09/9990000009831-1-scaled-1.webp']),
 'shiva': ('brass Dancing Shiva (Nataraja) statue inside a ring of flames, on a square base', [U + '2026/09/9990000041091.webp']),
 'mellszobor': ('brass Buddha bust (head and shoulders)', [U + '2026/09/9990000008629.webp']),
 'alomfogo': ('natural dreamcatcher with a wooden ring, white net and brown feathers', [U + '2026/09/9990000039739.webp']),
 'sal': ('dark brown-green paisley woven scarf with fringe', [U + '2026/09/9990000043224.webp']),
 'guru': ('brass Guru Rinpoche statue holding a staff, on a lotus base', [U + '2026/10/9990000041169.webp']),
 'gyertyatarto': ('carved brown wooden pillar candle holder with whitewashed details', [U + '2026/09/9990000014958.webp']),
}
STYLE = ('Budapest, November, honest and workmanlike, warm daylight, real wood, brown cardboard, kraft paper, plaster walls; '
         'Nepal and India origin products; documentary photography, natural colours, soft contrast. '
         'Absolutely no text, no letters on walls, no labels added, no price tags, no stickers, no signage, no people, no logos except the ones printed on the product boxes.')
S = {
 'raktar': (['buddha', 'tara', 'hangtal', 'amber', 'palosanto', 'zaszlo'],
   'Inside an old Budapest storeroom-showroom with a high ceiling. Sturdy, slightly worn wooden shelving runs across the lower half of the frame. '
   'On the shelf at eye level: the brass Shakyamuni Buddha and the brass White Tara statues stand side by side, two hand-hammered singing bowls are stacked next to them, '
   'and an open brown cardboard carton holds a neat stack of the Sandesh incense boxes (orange Holy Amber and multicoloured Palo Santo), box fronts facing the camera and legible as in the references. '
   'A short string of the five-colour prayer flags hangs loosely along the top edge of the shelving. Morning daylight from a tall window on the left, a few dust motes in the light. '
   'The upper 45% of the frame is a calm, softly lit warm plaster wall above the shelving, uncluttered and empty for a headline.'),
 'ajto': (['shiva', 'hangtal'],
   'Seen from a dim stone gateway passage of an old Budapest apartment building: a tall, heavy, old wooden double door stands half open, and warm golden light spills out of the storeroom inside. '
   'Just inside the doorway, on a low wooden table in the lower part of the frame, stand the brass Dancing Shiva statue in its ring of flames and a hand-hammered singing bowl, '
   'with the soft out-of-focus edge of wooden shelves holding brass statues further back. Cool blue-grey afternoon light in the passage, warm light inside. '
   'The upper 45% of the frame is the dark upper door frame and a quiet dark vaulted ceiling, empty for a headline.'),
 'polc': (['amber', 'palosanto', 'mellszobor', 'hangtal', 'alomfogo', 'sal'],
   'A small independent gift and yoga shop in a Hungarian town: a pale wooden wall shelf and the end of a wooden counter beside the shop window, arranged the way a good boutique displays its goods. '
   'In the lower half of the frame: the Sandesh incense boxes stand upright in a short row, the brass Buddha bust stands beside them, the singing bowl rests on a small round cushion, '
   'the paisley scarf lies folded on the counter, and the natural dreamcatcher hangs from the shelf edge. Soft daylight from the window, tidy, inviting, lived-in. '
   'The upper 45% of the frame is a calm, light, slightly textured wall, empty for a headline.'),
 'karton': (['amber', 'palosanto', 'guru', 'gyertyatarto'],
   'A worn wooden packing table in a storeroom, seen from a three-quarter high angle: an open brown cardboard shipping box half-filled with the Sandesh incense boxes (fronts visible), '
   'next to it the brass Guru Rinpoche statue standing on a sheet of loosened kraft packing paper, the carved wooden candle holder lying on its side on crumpled kraft paper, a roll of brown paper tape. '
   'Warm side light from the right, deep soft shadows, calm. All objects in the lower half; the upper 45% of the frame is the plain dark wooden tabletop and soft shadow, empty for a headline.'),
}


def call(path, data):
    req = urllib.request.Request(URL + '/?rest_route=/hpv-ads/v1' + path, method='POST', data=json.dumps(data).encode(),
                                 headers={'Authorization': AUTH, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=400) as r:
            return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        b = e.read()
        try:
            return e.code, json.loads(b)
        except Exception:
            return e.code, b[:400].decode('utf-8', 'replace')


key = sys.argv[1]
fmts = sys.argv[2:] or ['portrait']
prods, scene = S[key]
os.makedirs(os.path.join(D, 'ai'), exist_ok=True)
for fmt in fmts:
    t = time.time()
    st, r = call('/profiles/30001/conversions/step', {'step': 'mb_image', 'params': {
        'scene': scene, 'products': [{'name': P[k][0], 'images': P[k][1]} for k in prods],
        'format': fmt, 'style': STYLE, 'quality': 'high'}})
    res = r.get('result', r) if isinstance(r, dict) else r
    url = res.get('url') if isinstance(res, dict) else None
    print(key, fmt, st, round(time.time() - t), 's', res.get('cost_usd') if isinstance(res, dict) else '', url or json.dumps(r, ensure_ascii=False)[:300], flush=True)
    if url:
        n = 1
        while os.path.exists(os.path.join(D, 'ai', f'{key}-{fmt}-{n}.jpg')):
            n += 1
        urllib.request.urlretrieve(url, os.path.join(D, 'ai', f'{key}-{fmt}-{n}.jpg'))
        print('  ->', f'ai/{key}-{fmt}-{n}.jpg', flush=True)
