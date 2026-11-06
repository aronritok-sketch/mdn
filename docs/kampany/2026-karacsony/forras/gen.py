# Karácsonyi kampány AI-jelenetek a Mandala termékeivel (Ads mb_image → SEO OS kreatívgyár). Használat: gen.py <kulcs> [<formátum> ...]
import json, os, sys, base64, urllib.request, urllib.error, time
sys.path.insert(0, '/tmp/claude-0/-home-user/3e932e69-9eed-538b-a286-f947fe941585/scratchpad')
URL = os.environ['HPV_REMOTE_CRM_URL'].rstrip('/')
AUTH = 'Basic ' + base64.b64encode((os.environ['HPV_REMOTE_CRM_USER'] + ':' + os.environ['HPV_REMOTE_CRM_PASS']).encode()).decode()
D = '/tmp/claude-0/-home-user/3e932e69-9eed-538b-a286-f947fe941585/scratchpad/xmas/'
U = 'https://mandala.hu/wp-content/uploads/'
P = {
 'nagchampa': ('Satya Nag Champa incense, blue box with red label', [U+'2026/09/8904234400037.webp']),
 'palosanto': ('Green Tree Palo Santo incense, golden-yellow box', [U+'2026/09/3112000000038.webp']),
 'lotusz': ('black-and-copper lotus flower incense holder', [U+'2026/09/99900000063112.webp']),
 'hangtal': ('hand-made brass singing bowl with dark band', [U+'2026/09/9990000048809.webp']),
 'babybuddha': ('golden laughing Baby Buddha statue with white robe', [U+'2026/09/9990000014309-1.webp']),
 'backflow': ('ceramic backflow incense burner: grey-brown little Buddha with celadon robe beside a lotus waterfall', [U+'2026/09/9990000007417.webp']),
 'mala': ('rose quartz chakra mala bracelet with seven coloured chakra beads', [U+'2026/10/9990000050864.webp']),
 'kulacs': ('polished copper bottle with copper cup on top', [U+'2026/09/9990000042289.webp']),
 'olaj': ('sandalwood perfume oil: small glass bottle with gold cap and its red Ganesha box', [U+'2026/09/9990000034321.webp']),
 'eletvirag': ('round light-blue flower-of-life stone incense plate', [U+'2026/09/9990000006182.webp']),
}
STYLE = 'Hungarian winter, Advent, calm and warm, candlelight, handcrafted, Nepal and India origin, natural props only (pine sprigs, dried orange slices, cinnamon, linen, kraft paper, twine, wood), no Christmas ornaments, no Santa, no snowflake decorations'
S = {
 'fust': (['nagchampa', 'palosanto', 'lotusz'],
   'A dark oiled walnut table in a warm old Budapest apartment on a December evening. In the lower half of the frame, the blue Satya Nag Champa incense box and the golden Green Tree Palo Santo incense box lie side by side at a slight angle, labels facing the camera and fully legible as in the references. Just behind them stands the black-and-copper lotus incense holder with exactly two lit incense sticks; two thin, separate smoke trails rise and gently twist around each other. A small pine sprig and one dried orange slice beside the boxes, a single beeswax candle far behind out of focus. Warm candlelight from the left, deep soft shadows. The upper 45% of the frame is a calm, dark, slightly textured plaster wall with only the smoke in it – empty space for a headline.'),
 'bw': (['hangtal', 'babybuddha'],
   'Low-key night photograph: a hand-made brass singing bowl and a golden laughing Baby Buddha statue on a dark slate surface, lower third of the frame, Baby Buddha slightly left, the bowl right and a little behind. A single warm golden rim light from behind-right draws their outlines and reflects in the brass; a faint wisp of incense smoke drifts across. The background is almost black, warm charcoal, very quiet. The upper half of the frame is dark and empty for text.'),
 'backflow': (['backflow'],
   'Dramatic but calm low-key photograph: the ceramic backflow incense burner with the little Buddha, placed on a dark wooden shelf, lower half of the frame. Thick white smoke flows down the lotus waterfall like a slow river and pools at the base, lit by a warm side light from the left; the background is deep warm black. The upper 45% of the frame is dark and empty for a headline.'),
 'mikulas': (['mala', 'nagchampa'],
   'A soft morning scene by a frosted window in early December: on a natural linen cloth lies an open small kraft-paper gift box tied with loosened jute twine; the rose quartz chakra mala bracelet rests across the open box, the blue Satya Nag Champa incense box leans inside it. A pine sprig, two cinnamon sticks and a handwritten blank kraft gift tag beside it. Cool daylight from the window mixed with warm light from a candle off-frame. Lower half of the frame; the upper 45% is a calm, softly lit, out-of-focus window and wall for a headline.'),
 'karacsony': (['kulacs', 'olaj', 'eletvirag'],
   'A calm festive evening on a wooden sideboard: the polished copper bottle with its cup stands left, the red Ganesha sandalwood oil box with its small gold-capped bottle in front, and the light-blue flower-of-life incense plate with one burning incense stick to the right. Pine branches along the back edge, a few warm out-of-focus string lights as soft bokeh far behind, a roll of kraft paper and twine. Warm, golden light. Products in the lower half; the upper 45% is a warm dark wall with soft bokeh, empty for a headline.'),
}
def call(path, data):
    req = urllib.request.Request(URL + '/?rest_route=/hpv-ads/v1' + path, method='POST', data=json.dumps(data).encode(), headers={'Authorization': AUTH, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=400) as r: return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        b = e.read()
        try: return e.code, json.loads(b)
        except Exception: return e.code, b[:400].decode('utf-8', 'replace')
key = sys.argv[1]; fmts = sys.argv[2:] or ['portrait']
prods, scene = S[key]
for fmt in fmts:
    t = time.time()
    st, r = call('/profiles/30001/conversions/step', {'step': 'mb_image', 'params': {'scene': scene, 'products': [{'name': P[k][0], 'images': P[k][1]} for k in prods], 'format': fmt, 'style': STYLE, 'quality': 'high'}})
    res = r.get('result', r) if isinstance(r, dict) else r
    url = res.get('url') if isinstance(res, dict) else None
    print(key, fmt, st, round(time.time() - t), 's', res.get('cost_usd') if isinstance(res, dict) else '', url or json.dumps(r, ensure_ascii=False)[:300])
    if url:
        n = 1
        while os.path.exists(f'{D}ai/{key}-{fmt}-{n}.jpg'): n += 1
        urllib.request.urlretrieve(url, f'{D}ai/{key}-{fmt}-{n}.jpg')
        print('  ->', f'ai/{key}-{fmt}-{n}.jpg')
