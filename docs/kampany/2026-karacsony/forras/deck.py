# szovegek.md – a kampány minden szövege másolható formában (texts.py-ból)
from texts import PHASES, TOPBAR, BANNER, CART, META, GOOGLE_HEAD, GOOGLE_DESC, GOOGLE_PROMO, EMAILS, SOCIAL
N = {'fust': 'Füstölő-hét', 'bw': 'Black Week', 'bf': 'Black Friday (nov. 27.)', 'mikulas': 'Mikulás', 'karacsony': 'Karácsony', 'utalvany': 'Utalvány (dec. 19–24.)'}
o = ['# Mandala – karácsonyi kampány 2026: szövegek', '', 'Minden szöveg egy helyen, másolható formában. A képek a `kepek/` mappában, a terv a PDF-ben.', '']
o += ['## Ajánlatok', '']
for p in PHASES:
    o += [f"### {p['name']} – {p['dates']}", f"**{p['offer']}.** {p['rule']}", f"Nem jár / nem adódik össze: {p['excl']}", '']
o += ['## Webshop', '', '### Felső sáv', '']
o += [f'- **{a}:** {b}' for a, b in TOPBAR] + ['', '### Főoldali banner (felirat / cím / szöveg / gomb)', '']
for a, b, c, d in BANNER:
    o += [f'- {a}', f'  - **{b}**', f'  - {c}', f'  - Gomb: {d}']
o += ['', '### Kosár-üzenetek', ''] + [f'- {a}: „{b}”' for a, b in CART]
o += ['', '## Meta (Facebook, Instagram)', '']
for k, m in META.items():
    o += [f'### {N[k]}', '', 'Elsődleges szöveg:'] + [f'- {x}' for x in m['primary']]
    o += ['', 'Cím: ' + ' · '.join(m['headline']), '', 'Leírás: ' + ' · '.join(m['desc']), '']
o += ['## Google Ads', '', '### Címek (≤ 30 karakter)', ''] + [f'- {h} ({len(h)})' for h in GOOGLE_HEAD]
o += ['', '### Leírások (≤ 90 karakter)', ''] + [f'- {h} ({len(h)})' for h in GOOGLE_DESC]
o += ['', '### Akciók', ''] + [f'- **{a}:** {b}' for a, b in GOOGLE_PROMO]
o += ['', '## Hírlevél (MailerLite)', '']
for e in EMAILS:
    o += [f"### {e[0]}{'' if e[1] == 'alap' else ' – választható'}", f'- Tárgy A: {e[2]}', f'- Tárgy B: {e[3]}', f'- Előfejléc: {e[4]}', f'- Tartalom: {e[5]}', '']
o += ['## Közösségi posztok', ''] + [f'- **{a} – {b}:** {c}' for a, b, c in SOCIAL]
o += ['', 'Árak: 2026. november 6. A szövegek kiindulópontok, a saját hangotokon nyugodtan átírhatók.', '']
open('../out/szovegek.md', 'w').write('\n'.join(o))
print(len(o), 'sor')
