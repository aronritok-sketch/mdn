# Mandala nagyker 2026 („Nyitott raktár”) – hirdetés-vizuálok: HTML méretenként → JPG (render.cjs). Használat: python3 ads.py [szűrő]
import json, os, sys
from texts import ADS, AD_CTA, AD_FOOT, DOTS

B = os.path.dirname(os.path.abspath(__file__))
AI = os.path.join(B, '..', 'ai')

CSS = r"""
@import url('fonts/fonts.css');
@font-face{font-family:'InterV';font-weight:100 900;src:url(fonts/inter-normal-latin.woff2) format('woff2');unicode-range:U+0000-00FF,U+2000-206F,U+2212}
@font-face{font-family:'InterV';font-weight:100 900;src:url(fonts/inter-normal-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA,U+1E00-1E9F}
:root{--night:#16120F;--paper:#F7F4EE;--sand:#EEE7DB;--ink:#1C1916;--muted:#6E6357;--saffron:#A9581A;--saffron-l:#E0A36A;--maroon:#6B2A2A;--kraft:#D9C29C;
 --g:#2F7A4D;--y:#E3B23C;--r:#B3322B}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:var(--w);height:var(--h);overflow:hidden;background:var(--night)}
body{font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision}
.ad{position:relative;width:var(--w);height:var(--h);overflow:hidden;color:var(--paper);background:var(--adbg,var(--night))}
.bg{position:absolute;left:0;right:0;bottom:0;width:100%;height:var(--bgh,100%);object-fit:cover;object-position:var(--pos,center)}
.bg.top{top:var(--bgt);bottom:auto;height:auto;-webkit-mask-image:linear-gradient(180deg,transparent 0,#000 var(--bgf,160px));mask-image:linear-gradient(180deg,transparent 0,#000 var(--bgf,160px))}
.scrim{position:absolute;inset:0;background:linear-gradient(180deg,rgba(16,12,10,var(--st,.66)) 0%,rgba(16,12,10,var(--sm,.25)) calc(var(--se,46%) * .7),rgba(16,12,10,0) var(--se,46%)),linear-gradient(0deg,rgba(16,12,10,var(--sb,.5)) 0%,rgba(16,12,10,0) 20%)}
.light .scrim{background:linear-gradient(180deg,rgba(247,244,238,var(--st,.80)) 0%,rgba(247,244,238,var(--sm,.45)) calc(var(--se,46%) * .75),rgba(247,244,238,0) var(--se,46%)),linear-gradient(0deg,rgba(16,12,10,var(--sb,.45)) 0%,rgba(16,12,10,0) 18%)}
.eyebrow{position:absolute;left:var(--px);top:var(--ey);font:600 calc(var(--u)*2.15)/1.2 'Inter';letter-spacing:.16em;text-transform:uppercase;color:var(--eyc,rgba(247,244,238,.88))}
.eyebrow i{font-style:normal;color:var(--acc,var(--saffron-l))}
h1{position:absolute;left:var(--px);top:var(--ht);width:var(--hw);font:500 var(--hs)/.92 'Cormorant Garamond',serif;letter-spacing:-.012em;color:var(--hc,var(--paper))}
h1 em{font-style:italic;font-weight:500;color:var(--acc,var(--saffron-l))}
.sub{position:absolute;left:var(--px);top:var(--st2);width:var(--sw);font:400 calc(var(--u)*2.85)/1.32 'Inter';color:var(--sc,rgba(247,244,238,.92));text-wrap:pretty}
.cta{position:absolute;left:var(--px);top:var(--ct);display:inline-flex;align-items:center;gap:calc(var(--u)*1.1);padding:calc(var(--u)*1.55) calc(var(--u)*2.7);border-radius:999px;background:var(--ctabg,var(--paper));color:var(--ctac,var(--ink));font:600 calc(var(--u)*2.05)/1 'Inter';font-variant-numeric:tabular-nums lining-nums;white-space:nowrap}
.cta svg{width:calc(var(--u)*2.1);height:calc(var(--u)*2.1)}
.addr{position:absolute;left:var(--px);top:calc(var(--ct) + var(--u)*6.8);font:500 calc(var(--u)*1.75)/1.2 'Inter';letter-spacing:.03em;color:var(--sc,rgba(247,244,238,.9));white-space:nowrap}
.logo{position:absolute;right:var(--px);top:calc(var(--ey) - var(--u)*.9);height:calc(var(--u)*3.6);width:auto}
/* a pöttyök – az aláírás */
.dots{position:absolute;left:var(--tx);top:var(--ty);width:calc(var(--d)*2.95);height:calc(var(--d)*1.9)}
.dot{position:absolute;width:calc(var(--d)*var(--s));height:calc(var(--d)*var(--s));left:calc(var(--d)*var(--x));top:calc(var(--d)*var(--y));border-radius:50%;background:var(--c);color:var(--k);
 display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotate(var(--rot));
 box-shadow:0 calc(var(--u)*.7) calc(var(--u)*1.4) rgba(0,0,0,.34),0 calc(var(--u)*.15) calc(var(--u)*.3) rgba(0,0,0,.25)}
.dot::before{content:'';position:absolute;inset:0;border-radius:50%;background:radial-gradient(circle at 32% 26%,rgba(255,255,255,.30),rgba(255,255,255,0) 46%),radial-gradient(rgba(0,0,0,.06) 1px,transparent 1.3px);background-size:auto,6px 6px}
.dot b{position:relative;font:700 calc(var(--d)*var(--s)*.40)/1 'InterV','Inter';letter-spacing:-.05em;font-variant-numeric:lining-nums;display:flex;align-items:flex-start}
.dot b span{font-size:.5em;margin-top:.12em;margin-left:.03em;letter-spacing:0}
.dot small{position:relative;margin-top:calc(var(--d)*var(--s)*.035);font:600 calc(var(--d)*var(--s)*.085)/1.15 'Inter';letter-spacing:.07em;text-transform:uppercase;text-align:center}
/* fekvő: szöveg-panel + kép */
.split .panel{position:absolute;left:0;top:0;bottom:0;width:var(--pw);background:var(--panel,var(--night))}
.split .bg{left:auto;right:0;width:calc(var(--w) - var(--pw) + 2px)}
.split .scrim{display:none}
.split .logo{left:var(--px);right:auto;top:auto;bottom:var(--fb);height:calc(var(--u)*3.2)}
"""

PHONE_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>'

# pötty-csoport: (x, y, méret, forgatás) a --d egységben; a piros a legnagyobb
LAYOUT = [(0.0, 0.62, 1.0, '-8deg'), (0.78, 0.0, 1.12, '5deg'), (1.62, 0.5, 1.33, '-4deg')]


def dots_html(sub=True):
    out = []
    for (x, y, s, rot), d in zip(LAYOUT, DOTS):
        lab = '<small>nagyker<br>árból</small>' if sub else ''
        out.append(f'<div class="dot" style="--x:{x};--y:{y};--s:{s};--rot:{rot};--c:{d["color"]};--k:{d["ink"]}"><b>−{d["pct"]}<span>%</span></b>{lab}</div>')
    return '<div class="dots">' + ''.join(out) + '</div>'


FMT = {
 'feed':  dict(w=1080, h=1350, src='portrait', u=10, px='72px', ey='76px', ht='136px', hw='940px', hs='132px', st2='392px', sw='560px', ct='540px', tx='652px', ty='392px', d='128px', fb='58px'),
 'story': dict(w=1080, h=1920, src='story', u=11, px='80px', ey='250px', ht='320px', hw='940px', hs='150px', st2='620px', sw='620px', ct='800px', tx='600px', ty='640px', d='148px', fb='380px', se='52%'),
 'square':dict(w=1080, h=1080, src='portrait', u=9.4, px='66px', ey='64px', ht='118px', hw='900px', hs='116px', st2='340px', sw='540px', ct='478px', tx='640px', ty='330px', d='118px', fb='52px', pos='center 78%', se='50%'),
 'land':  dict(w=1200, h=628, src='portrait', u=6.2, pw='600px', px='48px', ey='46px', ht='92px', hw='520px', hs='70px', st2='256px', sw='500px', ct='394px', tx='470px', ty='408px', d='84px', fb='40px', pos='center 72%'),
 'email': dict(w=1200, h=600, src='portrait', u=6.2, pw='600px', px='48px', ey='44px', ht='88px', hw='520px', hs='68px', st2='248px', sw='500px', ct='380px', tx='470px', ty='392px', d='82px', fb='38px', pos='center 72%'),
}
THEME = {'raktar': 'light', 'regi': 'dark', 'uj': 'light', 'utolso': 'dark'}
TUNE = {
 ('raktar', 'feed'): dict(bgt='250px', st='.88', sm='.62', se='44%'),
 ('raktar', 'square'): dict(bgt='130px', bgf='120px', st='.9', sm='.65', se='52%'),
 ('utolso', 'feed'): dict(bgt='210px', st='.55', se='34%'),
 ('utolso', 'square'): dict(bgt='170px', bgf='140px', st='.55', se='40%'),
 ('uj', 'feed'): dict(hs='112px', st2='370px', ct='526px', ty='380px', bgt='200px', st='.88', sm='.62', se='44%'),
 ('uj', 'story'): dict(hs='128px', st2='600px', ct='790px', bgt='260px', bgf='220px', st='.88', sm='.6', se='46%'),
 ('utolso', 'story'): dict(bgt='430px', bgf='240px', st='.6', se='44%'),
 ('uj', 'square'): dict(hs='98px', st2='316px', ct='460px', ty='314px', bgt='130px', bgf='120px', st='.9', sm='.65', se='52%'),
 ('uj', 'land'): dict(hs='60px', st2='240px'), ('uj', 'email'): dict(hs='58px', st2='232px'),
 ('utolso', 'land'): dict(hs='64px'), ('utolso', 'email'): dict(hs='62px'),
}
LIGHT = ';--hc:var(--ink);--acc:#8A4512;--eyc:rgba(28,25,22,.8);--sc:rgba(28,25,22,.9);--adbg:var(--paper);--panel:var(--paper);--ctabg:var(--ink);--ctac:var(--paper)'


def page(ad, fmt, extra=None):
    p = ADS[ad]
    f = dict(FMT[fmt]); f.update(TUNE.get((ad, fmt), {})); f.update(extra or {})
    img = f"../ai/{p['img']}-{f['src']}-1.jpg"
    vars_ = ';'.join(f'--{k}:{v}' for k, v in f.items() if k not in ('w', 'h', 'src', 'u')) + f";--w:{f['w']}px;--h:{f['h']}px;--u:{f['u']}px"
    theme = THEME[ad]
    split = fmt in ('land', 'email')
    logo = 'logo-dark.svg' if theme == 'light' else 'logo-light.svg'
    return f"""<!doctype html><html lang="hu"><head><meta charset="utf-8"><style>{CSS}</style></head>
<body style="{vars_}{LIGHT if theme == 'light' else ''}"><div class="ad {theme} {'split' if split else ''}">
{'<div class="panel"></div>' if split else ''}<img class="bg{' top' if 'bgt' in f else ''}" src="{img}" alt=""><div class="scrim"></div>
<div class="eyebrow">{p['eyebrow']}</div>
<h1>{p['h1']}</h1>
<p class="sub">{p['sub']}</p>
<div class="cta">{PHONE_SVG}{AD_CTA}</div>
<div class="addr">{AD_FOOT}</div>
{dots_html(sub=fmt not in ('land', 'email'))}
<img class="logo" src="{logo}" alt="Mandala">
</div></body></html>"""


def hero(ad='raktar', pos='center 58%'):
    """Levél-fejléc (1200 × 600): a kép, és a pöttyök egy-egy terméken – ahogy a polcon lesznek; a szöveg a levélben él."""
    p = ADS[ad]
    spots = [(150, 352, 96, '-8deg'), (706, 318, 100, '6deg'), (960, 300, 112, '-5deg')]  # zöld: Buddha talapzata, sárga: hangtál, piros: füstölő
    dots = ''.join(f'<div class="dot" style="position:absolute;left:{x}px;top:{y}px;width:{w}px;height:{w}px;transform:rotate({r});--c:{d["color"]};--k:{d["ink"]};--d:{w}px;--s:1">'
                   f'<b>−{d["pct"]}<span>%</span></b><small>nagyker<br>árból</small></div>' for (x, y, w, r), d in zip(spots, DOTS))
    return f"""<!doctype html><html lang="hu"><head><meta charset="utf-8"><style>{CSS}</style></head>
<body style="--w:1200px;--h:600px;--u:6.2px;--pos:{pos}"><div class="ad">
<img class="bg" src="../ai/{p['img']}-portrait-1.jpg" alt="">{dots}</div></body></html>"""


if __name__ == '__main__':
    open(os.path.join(B, 'ad-hero.html'), 'w').write(hero())
    jobs = []
    flt = sys.argv[1] if len(sys.argv) > 1 else ''
    for i, ad in enumerate(ADS, 1):
        for fmt in FMT:
            name = f'{i}-{ad}-{fmt}'
            if flt and flt not in name:
                continue
            src = f"{ADS[ad]['img']}-{FMT[fmt]['src']}-1.jpg"
            if not os.path.exists(os.path.join(AI, src)):
                print('nincs kép még:', src)
                continue
            open(os.path.join(B, f'ad-{name}.html'), 'w').write(page(ad, fmt))
            jobs.append({'html': f'ad-{name}.html', 'png': f'../out/kepek/{name}.jpg', 'w': FMT[fmt]['w'], 'h': FMT[fmt]['h']})
    os.makedirs(os.path.join(B, '..', 'out', 'kepek'), exist_ok=True)
    jobs.append({'html': 'ad-hero.html', 'png': '../out/kepek/level-fejlec-1200x600.jpg', 'w': 1200, 'h': 600})
    json.dump(jobs, open(os.path.join(B, 'jobs-ads.json'), 'w'))
    print(len(jobs), 'kép')
