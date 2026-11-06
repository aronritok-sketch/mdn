# Mandala karácsony 2026 – hirdetés-vizuálok: HTML méretenként → PNG (render.mjs). Használat: python3 ads.py [szűrő]
import json, os, sys, html
B = os.path.dirname(os.path.abspath(__file__))
AI = os.path.join(B, '..', 'ai')
OUT = os.path.join(B, '..', 'out')

CSS = r"""
@import url('fonts/fonts.css');
:root{--night:#16120F;--paper:#F7F4EE;--sand:#EEE7DB;--ink:#1C1916;--muted:#6E6357;--saffron:#A9581A;--saffron-l:#E0A36A;--maroon:#6B2A2A;--kraft:#D9C29C;--kraft-d:#B89668;--twine:#8C6A43}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:var(--w);height:var(--h);overflow:hidden;background:var(--night)}
body{font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision}
.ad{position:relative;width:var(--w);height:var(--h);overflow:hidden;color:var(--paper)}
.bg{position:absolute;left:0;right:0;bottom:0;width:100%;height:var(--bgh,100%);object-fit:cover;object-position:var(--pos,center);-webkit-mask-image:linear-gradient(180deg,transparent 0,#000 var(--bgf,0%));mask-image:linear-gradient(180deg,transparent 0,#000 var(--bgf,0%))}
.ad{background:var(--adbg,var(--night))}
.light .scrim{background:linear-gradient(180deg,rgba(247,244,238,var(--st,.78)) 0%,rgba(247,244,238,.35) calc(var(--se,42%) * .8),rgba(247,244,238,0) var(--se,42%))}
.light h1{text-shadow:0 1px 0 rgba(255,255,255,.25)}
.light .url{color:var(--ink)}
.scrim{position:absolute;inset:0;background:linear-gradient(180deg,rgba(16,12,10,var(--st,.62)) 0%,rgba(16,12,10,0) var(--se,42%)),linear-gradient(0deg,rgba(16,12,10,var(--sb,.55)) 0%,rgba(16,12,10,0) 22%)}
.eyebrow{position:absolute;left:var(--px);top:var(--ey);font:600 calc(var(--u)*2.15)/1.2 'Inter';letter-spacing:.16em;text-transform:uppercase;color:var(--eyc,rgba(247,244,238,.86))}
.eyebrow i{font-style:normal;color:var(--acc,var(--saffron-l))}
h1{position:absolute;left:var(--px);top:var(--ht);width:var(--hw);font:500 var(--hs)/.92 'Cormorant Garamond',serif;letter-spacing:-.012em;text-wrap:balance;color:var(--hc,var(--paper))}
h1 em{font-style:italic;font-weight:500;color:var(--acc,var(--saffron-l))}
.sub{position:absolute;left:var(--px);top:var(--st2);width:var(--sw);font:400 calc(var(--u)*2.85)/1.32 'Inter';color:var(--sc,rgba(247,244,238,.9));text-wrap:pretty}
.tag{position:absolute;left:var(--tx);top:var(--ty);width:var(--tw);transform:rotate(var(--tr,-5deg));transform-origin:50% 0;filter:drop-shadow(0 calc(var(--u)*1.2) calc(var(--u)*1.6) rgba(0,0,0,.45))}
.tag .card{position:relative;background:var(--tagbg,var(--kraft));color:var(--tagc,var(--ink));padding:calc(var(--u)*5.2) calc(var(--u)*2.4) calc(var(--u)*2.4);text-align:center;clip-path:polygon(18% 0,82% 0,100% 12%,100% 100%,0 100%,0 12%);background-image:radial-gradient(rgba(0,0,0,.06) 1px,transparent 1.2px),radial-gradient(rgba(255,255,255,.08) 1px,transparent 1.2px);background-size:7px 7px,11px 11px}
.tag .hole{position:absolute;left:50%;top:calc(var(--u)*1.6);width:calc(var(--u)*2.2);height:calc(var(--u)*2.2);margin-left:calc(var(--u)*-1.1);border-radius:50%;background:var(--holebg,#2a2119);box-shadow:inset 0 0 0 calc(var(--u)*.35) var(--kraft-d)}
.tag b{display:block;font:500 var(--tbs)/0.9 'Cormorant Garamond',serif;letter-spacing:-.02em;font-variant-numeric:lining-nums;font-feature-settings:'lnum' 1}
.tag small{display:block;margin-top:calc(var(--u)*1.2);font:600 calc(var(--u)*1.7)/1.22 'Inter';letter-spacing:.08em;text-transform:uppercase}
.tag svg.twine{position:absolute;left:50%;bottom:calc(100% - var(--u)*2.75);width:calc(var(--u)*6);height:calc(var(--u)*9);margin-left:calc(var(--u)*-3);overflow:visible}
.logo{position:absolute;right:var(--px);top:var(--logot,calc(var(--ey) - var(--u)*.9));height:calc(var(--u)*3.6);width:auto}
.url{position:absolute;left:var(--px);top:var(--ct);font:600 calc(var(--u)*2.1)/1 'Inter';letter-spacing:.02em;color:var(--paper);padding-bottom:calc(var(--u)*.5);border-bottom:2px solid var(--acc,var(--saffron-l))}
.foot{position:absolute;left:var(--px);right:var(--px);bottom:var(--fb);display:flex;align-items:center;justify-content:space-between}
.foot img{height:calc(var(--u)*3.4);width:auto}
.foot span{font:500 calc(var(--u)*1.7)/1 'Inter';letter-spacing:.04em;color:rgba(247,244,238,.92)}
.cta{position:absolute;left:var(--px);top:var(--ct);display:inline-flex;align-items:center;gap:calc(var(--u)*1);padding:calc(var(--u)*1.5) calc(var(--u)*2.6);border-radius:999px;background:var(--paper);color:var(--ink);font:600 calc(var(--u)*1.8)/1 'Inter'}
.cta::after{content:'→';font-weight:400}
/* utalvány-kártya */
.vbg{position:absolute;inset:0;background:radial-gradient(120% 80% at 70% 85%,#7d3434 0%,var(--maroon) 45%,#3b1716 100%)}
.voucher{position:absolute;left:var(--vx);top:var(--vy);width:var(--vw);aspect-ratio:1.75;transform:rotate(-6deg);background:var(--paper);color:var(--ink);border-radius:calc(var(--u)*.6);box-shadow:0 calc(var(--u)*3) calc(var(--u)*6) rgba(0,0,0,.45);padding:calc(var(--u)*4.5) calc(var(--u)*5);background-image:radial-gradient(rgba(0,0,0,.035) 1px,transparent 1.2px);background-size:6px 6px}
.voucher::before{content:'';position:absolute;inset:calc(var(--u)*1.6);border:1.5px solid rgba(169,88,26,.55);border-radius:calc(var(--u)*.4)}
.voucher .vl{height:calc(var(--u)*4.2);width:auto;display:block}
.voucher .vk{margin-top:calc(var(--u)*3.4);font:600 calc(var(--u)*1.7)/1 'Inter';letter-spacing:.2em;text-transform:uppercase;color:var(--saffron)}
.voucher .va{margin-top:calc(var(--u)*1.2);font:500 calc(var(--u)*9)/1 'Cormorant Garamond',serif;font-variant-numeric:lining-nums;font-feature-settings:'lnum' 1}
.voucher .vf{position:absolute;left:calc(var(--u)*5);bottom:calc(var(--u)*4);font:500 calc(var(--u)*1.6)/1.3 'Inter';color:var(--muted)}
.voucher .ribbon{position:absolute;top:0;bottom:0;right:22%;width:calc(var(--u)*1.1);background:var(--twine);opacity:.9}
.voucher .ribbon2{position:absolute;left:0;right:0;top:80%;height:calc(var(--u)*1.1);background:var(--twine);opacity:.9}
.voucher .bow{position:absolute;right:calc(22% - var(--u)*3.3);top:calc(80% - var(--u)*3.3);width:calc(var(--u)*7.7);height:calc(var(--u)*7.7);border-radius:50%;border:calc(var(--u)*1.1) solid var(--twine);background:transparent}
/* fekvő: szöveg-panel + kép */
.split .logo{left:var(--px);right:auto;top:auto;bottom:var(--fb);height:calc(var(--u)*3.2)}
.split .url{top:auto;bottom:calc(var(--fb) + var(--u)*.2);left:auto;right:calc(var(--w) - var(--pw) + var(--px))}
.split h1{line-height:.95}
.split .panel{position:absolute;left:0;top:0;bottom:0;width:var(--pw);background:var(--panel,var(--night))}
.split .bg{left:auto;right:0;width:calc(var(--w) - var(--pw) + 2px)}
.split .scrim{display:none}
.grain::before{content:'';position:absolute;inset:0;z-index:0;opacity:.18;background-image:radial-gradient(rgba(255,255,255,.07) 1px,transparent 1px);background-size:5px 5px;pointer-events:none}
"""

TWINE = '<svg class="twine" viewBox="0 0 60 90"><path d="M30 88 C 14 70, 12 40, 26 6" fill="none" stroke="var(--twine)" stroke-width="3.2" stroke-linecap="round"/><path d="M30 88 C 46 70, 44 38, 28 4" fill="none" stroke="var(--twine)" stroke-width="3.2" stroke-linecap="round"/><circle cx="27" cy="5" r="3.4" fill="var(--twine)"/></svg>'

PH = {
 'fust': dict(img='fust', eyebrow='Füstölő-hét · <i>november 13–22.</i>', h1='Egyet fizetsz,<br><em>kettőt</em> viszel.',
              sub='Minden füstölőre: két csomagból az olcsóbbat mi álljuk. Vasárnap éjfélig.', tb='1+1', ts='minden<br>füstölőre'),
 'bw': dict(img='bw', eyebrow='Black Week · <i>november 23–30.</i>', h1='Black Week<br>a <em>Mandalában</em>.',
            sub='Szinte mindenre 20%, a hangtálakra 10%. A füstölőkre marad az egyet fizetsz, kettőt viszel.', tb='−20%', ts='szinte<br>mindenre', tagbg='var(--saffron)', tagc='var(--paper)'),
 'bf': dict(img='backflow', eyebrow='Black Friday · <i>csak november 27-én</i>', h1='Ma a szállítás<br><em>ránk</em> jön.',
            sub='Bármekkora rendelésnél, a Black Week kedvezményei mellé. Éjfélig.', tb='0 Ft', ts='szállítás<br>ma éjfélig', tagbg='var(--saffron)', tagc='var(--paper)'),
 'mikulas': dict(img='mikulas', theme='light', eyebrow='Mikulás · <i>rendelj dec. 3., 12 óráig</i>', h1='Ajándék<br>a <em>csizmába</em>.',
                 sub='Díszcsomagolásban, kézzel írt kártyával – ez most nem kerül semmibe.', tb='0 Ft', ts='díszcsomagolás<br>és kártya'),
 'utalvany': dict(img=None, eyebrow='Az utolsó napokban · <i>december 24-ig</i>', h1='Utolsó pillanat?<br><em>Utalvány</em>.',
                  sub='Ajándékutalvány e-mailben, nyomtatható is – és ő választ, több mint 3 000 termék közül.', tb='3&#8239;000+', ts='termék közül<br>választhat', tbscale=.72),
 'karacsony': dict(img='karacsony', eyebrow='Karácsony · <i>rendelj dec. 18., 12 óráig</i>', h1='Ajándék, ami<br><em>jelent is</em> valamit.',
                   sub='Díszcsomagolás és kézzel írt kártya ajándékba, 15 000 Ft felett egy füstölő is.', tb='dec. 18.', ts='12 óráig rendelj,<br>karácsonyra ott van'),
}

# formátumonként a rács (u = alapegység px), és fázisonként a finomhangolás
FMT = {
 'feed':  dict(w=1080, h=1350, src='portrait', u=10, px='72px', ey='76px', ht='136px', hw='940px', hs='132px', st2='392px', sw='620px', ct='520px', tx='76px', ty='640px', tw='300px', tbs='112px', fb='58px'),
 'story': dict(w=1080, h=1920, src='story', u=11, px='80px', ey='270px', ht='340px', hw='940px', hs='150px', st2='640px', sw='700px', ct='800px', tx='84px', ty='930px', tw='320px', tbs='122px', fb='380px', se='50%'),
 'land':  dict(w=1200, h=628, src='portrait', u=6.2, pw='600px', px='48px', ey='46px', ht='92px', hw='520px', hs='70px', st2='262px', sw='480px', ct='0', tx='470px', ty='300px', tw='190px', tbs='64px', fb='40px', pos='center 70%'),
 'email': dict(w=1200, h=600, src='portrait', u=6.2, pw='600px', px='48px', ey='46px', ht='90px', hw='520px', hs='68px', st2='256px', sw='480px', ct='0', tx='470px', ty='300px', tw='190px', tbs='64px', fb='38px', pos='center 70%'),
 'square':dict(w=1080, h=1080, src='portrait', u=9.4, px='66px', ey='64px', ht='118px', hw='900px', hs='116px', st2='344px', sw='600px', ct='462px', tx='66px', ty='560px', tw='260px', tbs='96px', fb='52px', pos='center 82%'),
}
TUNE = {  # fázis × formátum felülírások
 ('bw','feed'): dict(tx='720px', ty='470px', tr='5deg'),
 ('bw','story'): dict(tx='660px', ty='820px', tr='5deg'),
 ('bf','feed'): dict(tx='720px', ty='470px', tr='5deg'),
 ('bf','story'): dict(tx='660px', ty='820px', tr='5deg'),
 ('bf','feed'): dict(tx='740px', ty='520px', tr='5deg', bgh='78%', bgf='22%', pos='center bottom'),
 ('bf','story'): dict(tx='690px', ty='860px', tr='5deg', bgh='70%', bgf='18%', pos='center bottom'),
 ('bf','square'): dict(tx='760px', ty='330px', tr='5deg', bgh='70%', bgf='22%', pos='center 80%'),
 ('bw','square'): dict(tx='770px', ty='300px', tr='5deg'),
 ('karacsony','feed'): dict(tx='770px', ty='470px', tr='5deg', bgh='68%', bgf='18%', pos='center bottom'),
 ('karacsony','story'): dict(tx='700px', ty='900px', tr='5deg', bgh='64%', bgf='16%', pos='center bottom'),
 ('karacsony','square'): dict(tx='790px', ty='420px', tr='5deg', bgh='62%', bgf='20%', pos='center 88%'),
 ('karacsony','land'): dict(pos='center 80%'), ('karacsony','email'): dict(pos='center 80%'),
 ('mikulas','land'): dict(pos='center 75%'), ('mikulas','email'): dict(pos='center 75%'),
 ('bw','land'): dict(pos='center 85%'), ('bw','email'): dict(pos='center 85%'),
 ('mikulas','feed'): dict(st='.82', se='40%'),
 ('utalvany','feed'): dict(tx='80px', ty='640px'), ('utalvany','story'): dict(tx='90px', ty='900px'), ('utalvany','square'): dict(tx='80px', ty='560px'),
 ('utalvany','land'): dict(tx='470px', ty='320px'), ('utalvany','email'): dict(tx='470px', ty='320px'),
 ('mikulas','square'): dict(tx='70px', ty='520px', st='.85', se='44%'),
 ('mikulas','story'): dict(st='.82', se='46%'),
}

def page(ph, fmt, extra=None):
    p = PH[ph]; f = dict(FMT[fmt]); f.update(TUNE.get((ph, fmt), {})); f.update(extra or {})
    src = f.get('src'); img = f"../ai/{p['img']}-{src}-1.jpg" if p['img'] else ''
    if ph == 'bf' and src == 'portrait':
        img = "../ai/backflow-square-1.jpg"
    vars_ = ';'.join(f'--{k}:{v}' for k, v in f.items() if k not in ('w', 'h', 'src', 'u')) + f";--w:{f['w']}px;--h:{f['h']}px;--u:{f['u']}px"
    tagvars = ''.join(f';--{k}:{p[k]}' for k in ('tagbg', 'tagc') if k in p)
    if 'tbscale' in p:
        tagvars += f";--tbs:calc({f['tbs']} * {p['tbscale']})"
    theme = p.get('theme', 'dark')
    themevars = ';--hc:var(--ink);--acc:#8A4512;--eyc:rgba(28,25,22,.82);--sc:rgba(28,25,22,.9);--adbg:var(--paper);--panel:var(--paper)' if theme == 'light' else ''
    logo = 'logo-dark.svg' if theme == 'light' else 'logo-light.svg'
    split = fmt in ('land', 'email')
    if p['img'] is None:
        vpos = {'feed': ('350px', '720px', '660px'), 'story': ('280px', '1100px', '720px'), 'square': ('430px', '560px', '590px'),
                'land': ('640px', '200px', '500px'), 'email': ('640px', '190px', '500px')}[fmt]
        art = f'<div class="vbg"></div><div class="voucher" style="--vx:{vpos[0]};--vy:{vpos[1]};--vw:{vpos[2]}"><span class="ribbon"></span><span class="ribbon2"></span><span class="bow"></span><img class="vl" src="logo-dark.svg" alt=""><div class="vk">Ajándékutalvány</div><div class="va">10 000 Ft</div><div class="vf">mandala.hu · részletekben is beváltható</div></div>'
    else:
        art = f'<img class="bg" src="{img}" alt=""><div class="scrim"></div>'
    panel = '<div class="panel"></div>' if split else ''
    return f"""<!doctype html><html lang="hu"><head><meta charset="utf-8"><style>{CSS}</style></head>
<body style="{vars_}{tagvars}{themevars}"><div class="ad {theme} {'split' if split else ''}">
{panel if p['img'] else ''}{art}
<div class="eyebrow">{p['eyebrow']}</div>
<h1>{p['h1']}</h1>
<p class="sub">{p['sub']}</p>
<div class="tag"><div class="card"><span class="hole"></span><b>{p['tb']}</b><small>{p['ts']}</small></div>{TWINE}</div>
<img class="logo" src="{logo}" alt="Mandala">
<div class="url">mandala.hu</div>
</div></body></html>"""

jobs = []
flt = sys.argv[1] if len(sys.argv) > 1 else ''
for ph in PH:
    for fmt in FMT:
        name = f'{ph}-{fmt}'
        if flt and flt not in name:
            continue
        f = FMT[fmt]
        src = f"{PH[ph]['img']}-{f['src']}-1.jpg" if PH[ph]['img'] else None
        if ph == 'bf' and f['src'] == 'portrait':
            src = 'backflow-square-1.jpg'
        if src and not os.path.exists(os.path.join(AI, src)):
            print('nincs kép még:', src); continue
        open(os.path.join(B, f'{name}.html'), 'w').write(page(ph, fmt))
        jobs.append({'html': f'{name}.html', 'png': f'../out/{name}.png', 'w': f['w'], 'h': f['h']})
json.dump(jobs, open(os.path.join(B, 'jobs.json'), 'w'))
print(len(jobs), 'oldal')
