(function () {
  var now = Math.floor(Date.now() / 1000);
  var d0 = new Date(); d0.setHours(0,0,0,0); var T = Math.floor(d0.getTime()/1000);
  function at(day, h) { return T + day*86400 + h*3600; }
  function fmtd(ts) { var d = new Date(ts*1000); return d.toLocaleDateString('fr-FR') + ' ' + d.toTimeString().slice(0,5); }
  var WS = [{id:1,ref:'CUIS',label:'Cuisson',type:'MACHINE'},{id:2,ref:'COND',label:'Conditionnement',type:'BOTH'},{id:3,ref:'MEL',label:'Mélange',type:'HUMAN'}];
  window.MRPBATCH_CFG.workstations = WS;
  function mo(id, ref, st, prod, qty, s, e, ws, late, pct, ppct, tp) {
    return {id:id, ref:ref, label:'', product_ref:prod, product_label:{'TERR-CAMP':'Terrine de campagne 180 g','RILL-OIE':"Rillettes d'oie 120 g",'PATE-CRO':'Pâté croûte 1 kg','SOUP-POT':'Soupe potiron 1 L'}[prod]||'', thirdparty:tp||'', qty:qty, produced: Math.round(qty*ppct/100), status:st, start:s, end:e, start_iso:'', end_iso:'', date_start:s?fmtd(s):'', date_end:e?fmtd(e):'', late:late, pct:pct, produce_pct:ppct, workstations:ws};
  }
  var MOS = [
    mo(11,'MO2610-0011',2,'TERR-CAMP',240,at(0,7),at(0,11),[1,3],0,65,40,'Épicerie Martin'),
    mo(12,'MO2610-0012',1,'RILL-OIE',120,at(0,13),at(0,16),[1],0,0,0),
    mo(13,'MO2610-0013',1,'PATE-CRO',30,at(-1,8),at(-1,12),[3],1,0,0,'Traiteur Dubois'),
    mo(14,'MO2610-0014',2,'SOUP-POT',400,at(0,9),at(0,14),[2],0,100,75),
    mo(15,'MO2610-0015',1,'TERR-CAMP',240,at(1,7),0,[1],0,0,0),
    mo(16,'MO2610-0016',1,'RILL-OIE',80,at(2,10),at(2,15),[],0,0,0),
    mo(17,'MO2610-0017',3,'SOUP-POT',200,at(-1,13),at(-1,17),[2],0,100,100)
  ];
  var MO11 = {id:11, ref:'MO2610-0011', label:'', status:2, consumable:true, product_ref:'TERR-CAMP', product_label:'Terrine de campagne 180 g', thirdparty:'Épicerie Martin', qty:240,
    date_start: fmtd(at(0,7)), date_end: fmtd(at(0,11)), workstations:[1,3], pct:65, produced:96, produce_planned:240, produce_pct:40, url:'#',
    lines:[
      {objectid:101,fk_product:5,product_ref:'MP-PORC-GORGE',product_label:'Gorge de porc',barcode:'3760123450011',status_batch:1,disable_stock_change:0,fk_workstation:3,qty_planned:30,qty_consumed:30,qty_remaining:0},
      {objectid:102,fk_product:6,product_ref:'MP-FOIE-VOL',product_label:'Foie de volaille',barcode:'3760123450028',status_batch:1,disable_stock_change:0,fk_workstation:3,qty_planned:12,qty_consumed:6,qty_remaining:6},
      {objectid:103,fk_product:7,product_ref:'MP-SEL-NIT',product_label:'Sel nitrité',barcode:'',status_batch:0,disable_stock_change:0,fk_workstation:0,qty_planned:0.8,qty_consumed:0,qty_remaining:0.8},
      {objectid:104,fk_product:8,product_ref:'EMB-BOCAL-180',product_label:'Bocal verre 180 g + capsule',barcode:'3760123450042',status_batch:0,disable_stock_change:0,fk_workstation:0,qty_planned:240,qty_consumed:100,qty_remaining:140},
      {objectid:105,fk_product:9,product_ref:'OP-CUISSON',product_label:'Cuisson autoclave',barcode:'',status_batch:0,disable_stock_change:1,fk_workstation:1,qty_planned:3,qty_consumed:0,qty_remaining:3}
    ],
    produce_lines:[{objectid:110,fk_product:1,product_ref:'TERR-CAMP',product_label:'Terrine de campagne 180 g',barcode:'3760123459991',status_batch:1,disable_stock_change:0,qty_planned:240,qty_produced:96,qty_remaining:144}],
    history:[{date:fmtd(now-3600),product_ref:'MP-PORC-GORGE',batch:'L2610-88',warehouse:'CF1',qty:30},{date:fmtd(now-3000),product_ref:'EMB-BOCAL-180',batch:'',warehouse:'MAG',qty:100}],
    history_produced:[{date:fmtd(now-1800),product_ref:'TERR-CAMP',batch:'LOT261014-0001',warehouse:'CF2',qty:96,eatby:'14/01/2027',sellby:''}]
  };
  var CONSUME = {mode:'consume', line: MO11.lines[1], selected:1, warning:'', stock:[
    {fk_warehouse:1,warehouse_ref:'CF1',batch:'FV-2610-12',qty:4,eatby:'12/10/2026',sellby:'',expired:1},
    {fk_warehouse:1,warehouse_ref:'CF1',batch:'FV-2610-19',qty:18,eatby:'19/10/2026',sellby:'',expired:0},
    {fk_warehouse:2,warehouse_ref:'CF2',batch:'FV-2610-21',qty:25,eatby:'21/10/2026',sellby:'',expired:0}]};
  var WHS = [{id:1,ref:'CF1',label:'Chambre froide 1'},{id:2,ref:'CF2',label:'Chambre froide 2'},{id:3,ref:'MAG',label:'Magasin sec'},{id:4,ref:'QUAR',label:'Quarantaine'}];
  var today = new Date().toISOString().slice(0,10);
  var PRODUCE = {mode:'produce', line: MO11.produce_lines[0], batch:'LOT261014-0002', qty:144, eatby:'2027-01-14', sellby:'', eatby_days:92, sellby_days:0, eatby_mandatory:true, sellby_mandatory:false, fk_warehouse:2, warehouses:WHS, today:today};
  var RCORDERS = [
    {id:31,ref:'CF2610-0031',ref_supplier:'BL-88412',supplier:'Salaisons du Lot',status:3,delivery:'14/10/2026',delivery_iso:today,late:0,nblines:3,pct:0},
    {id:32,ref:'CF2610-0032',ref_supplier:'',supplier:'Verallia',status:4,delivery:'10/10/2026',late:1,nblines:2,pct:50},
    {id:33,ref:'CF2610-0035',ref_supplier:'CMD-778',supplier:'Volailles de Bresse',status:3,delivery:'16/10/2026',late:0,nblines:1,pct:0}];
  var RCHIST = [
    {date:fmtd(now-600),element_ref:'CF2610-0029',thirdparty:'Salaisons du Lot',product_ref:'MP-PORC-GORGE',batch:'L2610-88',qty:60,eatby:'24/10/2026',warehouse:'CF1',status:1,user:'Julie M.'},
    {date:fmtd(now-5600),element_ref:'MP-FOIE-VOL',thirdparty:'',product_ref:'MP-FOIE-VOL',batch:'FV-2610-21',qty:25,eatby:'21/10/2026',warehouse:'',status:0,note:'Température 9 °C',user:'Julie M.'}];
  var RCORDER = {id:31,ref:'CF2610-0031',ref_supplier:'BL-88412',supplier:'Salaisons du Lot',fk_soc:4,status:3,receivable:true,delivery:'14/10/2026',stock_on_dispatch:true,url:'#',
    lines:[{objectid:301,fk_product:5,product_ref:'MP-PORC-GORGE',product_label:'Gorge de porc',barcode:'3760123450011',ref_supplier:'SL-GORGE',status_batch:1,qty_planned:60,qty_done:0,qty_remaining:60},
      {objectid:302,fk_product:10,product_ref:'MP-PORC-EPAULE',product_label:'Épaule de porc désossée',barcode:'',ref_supplier:'SL-EPA',status_batch:1,qty_planned:40,qty_done:0,qty_remaining:40},
      {objectid:303,fk_product:11,product_ref:'MP-BARDE',product_label:'Barde de lard',barcode:'',ref_supplier:'',status_batch:0,qty_planned:5,qty_done:0,qty_remaining:5}],
    history:[]};
  var RECEIVE = {mode:'receive', line: RCORDER.lines[0], batch:'L2610-97', lot_known:false, qty:60, eatby:'2026-10-27', sellby:'', eatby_mandatory:true, sellby_mandatory:false, fk_warehouse:1, warehouses:WHS, quarantine:4, min_days:5, today:today, gs1:{gtin:'03760123450011',batch:'L2610-97',eatby:'2026-10-27'}};
  var STEPS = [{code:'toprepare',label:'À préparer'},{code:'preparing',label:'En préparation'},{code:'prepared',label:'Préparée'},{code:'packed',label:'Conditionnée'},{code:'ready',label:'Prête à expédier'}];
  function od(id, ref, cust, step, due, dl, nbl, pct, short, mos, pk) { return {id:id,ref:ref,ref_client:'',customer:cust,status:1,step:step,nb_packages:pk||0,delivery:dl,delivery_iso:'',due:due,nblines:nbl,pct:pct,mos:mos||[],shortage:short||0}; }
  var ODS = [
    od(41,'CO2610-0041','Épicerie Martin','toprepare','today','14/10/2026',4,0,1,[{id:11,ref:'MO2610-0011',status:2}]),
    od(42,'CO2610-0042','Traiteur Dubois','toprepare','late','12/10/2026',2,0,0,[{id:13,ref:'MO2610-0013',status:1}]),
    od(43,'CO2610-0043','Biocoop Cahors','preparing','','16/10/2026',6,45),
    od(44,'CO2610-0044','La Grande Épicerie','preparing','today','14/10/2026',3,80),
    od(45,'CO2610-0045','Maison Ferrand','prepared','','17/10/2026',2,100),
    od(46,'CO2610-0046','Comptoir des Saveurs','packed','','15/10/2026',5,100,0,[],3),
    od(47,'CO2610-0047','Épicerie Martin','ready','','15/10/2026',1,100,0,[],1)];
  var ODORDER = {id:43,ref:'CO2610-0043',ref_client:'BC-5521',customer:'Biocoop Cahors',status:1,delivery:'16/10/2026',step:'preparing',nb_packages:0,weight:0,note:'',note_public:'Livrer avant 10 h, quai n° 2',url:'#',shipping_url:'#',mos:[{id:14,ref:'MO2610-0014',status:2}],
    lines:[
      {objectid:401,fk_product:1,product_ref:'TERR-CAMP',product_label:'Terrine de campagne 180 g',barcode:'3760123459991',status_batch:1,qty_planned:48,qty_done:24,qty_remaining:24,stock:96,batches:{'LOT261014-0001':24}},
      {objectid:402,fk_product:2,product_ref:'RILL-OIE',product_label:"Rillettes d'oie 120 g",barcode:'',status_batch:1,qty_planned:36,qty_done:36,qty_remaining:0,stock:50,batches:{'LOT261002-0004':36}},
      {objectid:403,fk_product:3,product_ref:'SOUP-POT',product_label:'Soupe potiron 1 L',barcode:'',status_batch:1,qty_planned:60,qty_done:0,qty_remaining:60,stock:20,batches:{}}],
    history:[{date:fmtd(now-900),label:'Ligne préparée',element_ref:'CO2610-0043',product_ref:'RILL-OIE',batch:'LOT261002-0004',qty:36,status:1,user:'Karim B.'}]};
  var PICK = {mode:'pick', line: ODORDER.lines[0], batch:'', lots:[{fk_warehouse:2,warehouse_ref:'CF2',batch:'LOT261014-0001',qty:72,eatby:'14/01/2027'},{fk_warehouse:2,warehouse_ref:'CF2',batch:'LOT261015-0002',qty:24,eatby:'15/01/2027'}]};

  window.__calls = [];
  window.fetch = function (url, opt) {
    var q = opt && opt.method === 'POST' ? opt.body : url.split('?')[1];
    var p = new URLSearchParams(q), w = p.get('what'), r;
    window.__calls.push(w + (opt && opt.method === 'POST' ? ' POST ' + q : ''));
    var wsf = parseInt(p.get('ws') || '0', 10);
    function fws(list) { return list.filter(function (m) { return !wsf || (wsf === -1 ? !m.workstations.length : m.workstations.indexOf(wsf) >= 0); }); }
    switch (w) {
      case 'list': r = {ok:true, mos: fws(MOS.filter(function(m){return m.status<3;}))}; break;
      case 'mo': r = {ok:true, mo: MO11}; break;
      case 'line': r = p.get('lineid') === '110' ? Object.assign({ok:true}, PRODUCE) : Object.assign({ok:true}, CONSUME); break;
      case 'scan': r = p.get('code').indexOf('999') >= 0 ? Object.assign({ok:true}, PRODUCE) : Object.assign({ok:true}, CONSUME); break;
      case 'findmo': r = {ok:true, id:11}; break;
      case 'consume': case 'produce': r = {ok:true, kind:w, message:'MP-FOIE-VOL × 6 — lot FV-2610-19', mo: MO11}; break;
      case 'planning': r = {ok:true, planning:{from:+p.get('from'), to:+p.get('to'), hour_start:6, hour_end:20, workstations:WS,
        mos: fws(MOS).map(function(m){ m = Object.assign({}, m); if(!m.end){m.end=m.start+7200; m.end_estimated=1;} return m; }),
        unplanned: [mo(18,'MO2610-0018',1,'PATE-CRO',20,0,0,[3],0,0,0,'Traiteur Dubois')]}}; break;
      case 'rc_list': r = {ok:true, orders:RCORDERS, history:RCHIST}; break;
      case 'rc_order': r = {ok:true, order:RCORDER}; break;
      case 'rc_scan': case 'rc_line': r = Object.assign({ok:true}, RECEIVE); break;
      case 'rc_search': r = {ok:true, products:[{id:5,ref:'MP-PORC-GORGE',label:'Gorge de porc',barcode:'3760123450011',status_batch:1},{id:10,ref:'MP-PORC-EPAULE',label:'Épaule de porc désossée',barcode:'',status_batch:1}]}; break;
      case 'rc_receive': r = {ok:true, message:'MP-PORC-GORGE × 60 — lot L2610-97 — DLC 27/10/2026', conform:true, order:RCORDER}; break;
      case 'od_list': r = {ok:true, orders:ODS, steps:STEPS, pack_step:'packed'}; break;
      case 'od_order': r = {ok:true, order:ODORDER}; break;
      case 'od_find': r = {ok:true, id:43}; break;
      case 'od_scan': case 'od_line': r = Object.assign({ok:true}, PICK); break;
      case 'od_pick': case 'od_step': r = {ok:true, message:'TERR-CAMP : 48 / 48 préparé(s)', order:ODORDER}; break;
      case 'reschedule': r = {ok:true, message:'OF replanifié'}; break;
      default: r = {ok:false, error:'mock: '+w};
    }
    return Promise.resolve({ text: function () { return Promise.resolve(JSON.stringify(r)); } });
  };
})();
