import http.cookiejar, urllib.request, urllib.parse, json, re, sys, time
D = sys.argv[1]
cj = http.cookiejar.MozillaCookieJar(D + '/cj.txt'); cj.load(ignore_discard=True, ignore_expires=True)
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
B = 'http://127.0.0.1:8080/custom/mrpbatch/'
html = op.open(B + 'tablet.php').read().decode()
TOKEN = re.search(r'"token":"([0-9a-f]+)"', html).group(1)
fails = 0
def call(params, post=False, expect=True, label=''):
    global fails
    params = dict(params); params['token'] = TOKEN
    data = urllib.parse.urlencode(params)
    if post:
        r = op.open(B + 'ajax/app.php', data.encode())
    else:
        r = op.open(B + 'ajax/app.php?' + data)
    txt = r.read().decode()
    try:
        j = json.loads(txt)
    except Exception:
        print('!! NON JSON', label, txt[:600]); fails += 1; return {}
    ok = j.get('ok')
    flag = 'OK ' if ok == expect else '!! '
    if ok != expect: fails += 1
    print(flag + (label or params.get('what')), '->', (j.get('message') or j.get('error') or ''), )
    return j

j = call({'what': 'list'}); print('   mos', [(m['ref'], m['status'], m['workstations'], m['late']) for m in j['mos']])
j = call({'what': 'list', 'ws': 1}); print('   ws=CUIS', [m['ref'] for m in j['mos']])
j = call({'what': 'list', 'ws': 3}); print('   ws=MEL', [m['ref'] for m in j['mos']])
j = call({'what': 'list', 'ws': -1}); print('   ws=none', [m['ref'] for m in j['mos']])
j = call({'what': 'findmo', 'code': 'MO2610-0001'}); moid = j['id']
j = call({'what': 'mo', 'id': moid}); mo = j['mo']; print('   lines', [(l['product_ref'], l['qty_planned'], l['fk_workstation']) for l in mo['lines']], 'ws', mo['workstations'])
# scan composant par code-barre -> panneau consommation, lot non périmé sélectionné
j = call({'what': 'scan', 'id': moid, 'code': '3760123450011'}, label='scan composant'); print('   mode', j.get('mode'), 'selected', j.get('selected'), [(s['batch'], s['expired']) for s in j.get('stock', [])])
porc_line = j['line']['objectid']; sel = j['stock'][j['selected']]
j = call({'what': 'consume', 'id': moid, 'lineid': porc_line, 'qty': '5', 'fk_warehouse': sel['fk_warehouse'], 'batch': sel['batch']}, True, label='consume 5 L-NEW')
print('   pct', j.get('mo', {}).get('pct'), 'status', j.get('mo', {}).get('status'))
j = call({'what': 'consume', 'id': moid, 'lineid': porc_line, 'qty': '3760123450011'}, True, expect=False, label='consume qty=barcode (refus)')
# GS1 : scan produit fini -> production
j = call({'what': 'scan', 'id': moid, 'code': '3760123459991'}, label='scan produit fini'); print('   mode', j.get('mode'), 'batch', j.get('batch'), 'eatby', j.get('eatby'), 'wh', j.get('fk_warehouse'))
pl = j['line']['objectid']
j = call({'what': 'produce', 'id': moid, 'lineid': pl, 'qty': '40', 'fk_warehouse': 2, 'batch': j['batch'], 'eatby': j['eatby'], 'sellby': ''}, True, label='produce 40')
j = call({'what': 'produce', 'id': moid, 'lineid': pl, 'qty': '1', 'fk_warehouse': 2, 'batch': 'T-001', 'eatby': '2030-01-01'}, True, expect=False, label='produce lot existant date differente (refus)')
# Planning
t0 = int(time.mktime(time.strptime(time.strftime('%Y-%m-%d'), '%Y-%m-%d')))
j = call({'what': 'planning', 'from': t0, 'to': t0 + 7 * 86400}); pd = j['planning']; print('   planning', [(m['ref'], m['workstations'], m.get('end_estimated', 0)) for m in pd['mos']], 'unplanned', len(pd['unplanned']), pd['hour_start'], pd['hour_end'])
j = call({'what': 'reschedule', 'id': 2, 'start': t0 + 86400 + 13 * 3600, 'end': t0 + 86400 + 17 * 3600}, True, label='reschedule MO2')
j = call({'what': 'reschedule', 'id': 2, 'start': t0 + 13 * 3600, 'end': t0 + 3600}, True, expect=False, label='reschedule fin<debut (refus)')
# Réception
j = call({'what': 'rc_list'}); print('   orders', [(o['ref'], o['supplier'], o['pct']) for o in j['orders']])
cfid = j['orders'][0]['id']
j = call({'what': 'rc_scan', 'code': 'PO2610-0001'}, label='rc_scan ref commande'); print('   goto', j.get('goto'), j.get('id'))
j = call({'what': 'rc_scan', 'id': cfid, 'code': '0103760123450011172612311010LOT-F1'}, label='rc_scan GS1'); print('   ', j.get('mode'), j.get('batch'), j.get('eatby'), j.get('qty'), j['line'].get('objectid'))
lineid = j['line']['objectid']
j = call({'what': 'rc_receive', 'id': cfid, 'lineid': lineid, 'fk_product': 1, 'qty': '20', 'fk_warehouse': 1, 'batch': 'LOT-F1', 'eatby': '2026-12-31', 'conform': 1}, True, label='rc_receive commande 20')
print('   reçu', [(l['product_ref'], l['qty_done'], l['qty_remaining']) for l in j.get('order', {}).get('lines', [])])
j = call({'what': 'rc_receive', 'id': 0, 'fk_product': 1, 'qty': '5', 'fk_warehouse': 1, 'batch': 'LOT-F2', 'eatby': '', 'conform': 1}, True, expect=False, label='rc_receive sans DLC obligatoire (refus)')
j = call({'what': 'rc_receive', 'id': 0, 'fk_product': 2, 'qty': '12', 'fk_warehouse': 3, 'conform': 1}, True, label='rc_receive libre sel')
j = call({'what': 'rc_receive', 'id': 0, 'fk_product': 1, 'qty': '4', 'fk_warehouse': 1, 'batch': 'LOT-BAD', 'eatby': '2026-10-20', 'conform': 0, 'note': 'Température 9°C'}, True, label='rc_receive non conforme (quarantaine)')
j = call({'what': 'rc_search', 'q': 'porc'}); print('   search', [p['ref'] for p in j['products']])
# Commandes clients
j = call({'what': 'od_list'}); print('   od', [(o['ref'], o['step'], o['pct'], o['shortage'], o['due']) for o in j['orders']], [s['code'] for s in j['steps']])
coid = j['orders'][0]['id']
j = call({'what': 'od_find', 'code': 'SO2610-0001'})
j = call({'what': 'od_scan', 'id': coid, 'code': '3760123459991'}, label='od_scan produit'); print('   ', j.get('mode'), j['line']['product_ref'], [l['batch'] for l in j.get('lots', [])])
ol = j['line']['objectid']
j = call({'what': 'od_pick', 'id': coid, 'lineid': ol, 'qty': '12', 'batch': 'T-001'}, True, label='od_pick 12')
print('   step', j['order']['step'], [(l['product_ref'], l['qty_done'], l['batches']) for l in j['order']['lines']])
j = call({'what': 'od_pick', 'id': coid, 'lineid': ol, 'reset': 1}, True, label='od_pick reset')
j = call({'what': 'od_step', 'id': coid, 'step': 'packed', 'nb_packages': '3', 'weight': '12,5', 'note': 'Palette filmée'}, True, label='od_step packed')
j = call({'what': 'od_step', 'id': coid, 'step': 'ready'}, True, label='od_step ready')
print('   order', j['order']['step'], j['order']['nb_packages'], j['order']['weight'], len(j['order']['history']))
j = call({'what': 'od_step', 'id': coid, 'step': 'nope'}, True, expect=False, label='od_step inconnue (refus)')
# Clôture
j = call({'what': 'close', 'id': moid}, True, label='close MO1')
j = call({'what': 'consume', 'id': 999}, True, expect=False, label='OF inexistant')
j = call({'what': 'list'}, True); 
print('FAILS', fails)
