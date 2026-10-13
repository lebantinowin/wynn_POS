import {OfflineStore} from './offline-store.js';

const $ = id => document.getElementById(id);
const amount = value => (value / 100).toFixed(2);
const node = (tag, text = '', className = '') => { const element = document.createElement(tag); element.textContent = text; if (className) element.className = className; return element; };
const button = (text, action) => { const element = node('button', text); element.type = 'button'; element.addEventListener('click', () => run(action)); return element; };
let store, state, busy = false, online = false, csrf = '', signedIn = false, currentReceipt = null;
let syncPromise = null;
const editingFields = new Map();
const notify = text => { $('notice').textContent = text; };

async function request(path, data) {
    let response;
    try { response = await fetch(path, {method:data === undefined ? 'GET' : 'POST', cache:'no-store', credentials:'same-origin',
        headers:{Accept:'application/json', 'Content-Type':'application/json', ...(data === undefined ? {} : {'X-CSRF-TOKEN':csrf})},
        body:data === undefined ? undefined : JSON.stringify(data), signal:AbortSignal.timeout(15000)}); }
    catch { online = false; throw new Error('Shop connection unavailable. Saved sales remain on this browser; reconnect and synchronize.'); }
    online = true;
    if (response.status === 401 || response.redirected) { signedIn = false; localStorage.setItem('wynn-offline-signed-out','1'); throw new Error('Sign in online, then return here and synchronize. Saved sales are retained.'); }
    if (response.status === 419) throw new Error('Your sign-in token expired. Sign in online, then retry synchronization.');
    if (!response.ok) {
        if (response.status === 422) { const result = await response.json(); throw new Error(Object.values(result.errors || {}).flat()[0] || 'This sale needs review. Its local record has been kept.'); }
        throw new Error('The shop server could not confirm this request. Keep the saved records and retry.');
    }
    return response.json();
}
async function connect() {
    const status = await request('/offline/status');
    csrf = status.csrfToken; signedIn = true;
    localStorage.removeItem('wynn-offline-signed-out');
}
async function run(action) {
    if (busy || !store) return;
    busy = true; toggleButtons();
    try { await action(); }
    catch (error) { notify(error.message || 'The action could not be completed. Saved records are retained.'); }
    finally { busy = false; await render(); }
}
function toggleButtons() {
    for (const id of ['prepare','sync','finish','export','complete','import']) $(id).disabled = busy || !store;
    if (store && !busy) {
        $('prepare').disabled = Boolean(state?.snapshot);
        $('finish').disabled = !state?.snapshot;
        $('complete').disabled = !state?.snapshot || !Object.keys(state?.draft?.cart || {}).length || state?.sealed || Date.now() + (state?.clockOffset || 0) >= Date.parse(state?.snapshot?.validUntil) || Boolean(localStorage.getItem('wynn-offline-signed-out'));
    }
}
async function render() {
    if (!store) return;
    state = await store.state();
    const records = await store.allSales(), pending = records.filter(record => record.status !== 'Synced');
    const locked = Boolean(localStorage.getItem('wynn-offline-signed-out'));
    $('connection').textContent = online ? (signedIn ? 'Connected · Owner signed in' : 'Connected · Sign-in required for uploads') : 'Shop connection unavailable · Local records retained';
    $('session-status').textContent = !state.snapshot ? 'Not prepared' : state.sealed ? 'Finishing · New sales paused' : Date.now() + (state.clockOffset || 0) >= Date.parse(state.snapshot.validUntil) ? 'Expired · Synchronize online' : 'Prepared on this browser';
    $('session-details').textContent = state.snapshot ? state.snapshot.cashSessionNumber+' · Prepared until '+new Date(state.snapshot.validUntil).toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'}) : 'Open Shifts & Expenses before preparing.';
    $('pending-count').textContent = pending.length;
    const last = records.filter(record => record.status === 'Synced').at(-1);
    $('last-sync').textContent = last ? 'Last confirmed receipt: '+last.receipt.number : 'No server-confirmed sales yet.';
    if (!locked) { renderProducts(); renderCart(); } else { $('products').replaceChildren(node('p','Sign in online to unlock this checkout.')); $('cart').replaceChildren(node('p','Checkout is locked.')); $('receipt').hidden = true; }
    $('history').replaceChildren();
    for (const record of [...records].reverse()) {
        const row = node('tr');
        row.append(node('td',record.localReceipt.number), node('td',new Date(record.payload.occurredAt).toLocaleString('en-PH')), node('td',locked ? '—' : amount(record.payload.total)));
        const status = node('td',record.status === 'Synced' ? 'Confirmed · '+record.receipt.number : record.status);
        if (record.error) status.append(node('small',record.error,'backup-history-note'));
        const action = node('td'); if (!locked) action.append(button('View / reprint',()=>showReceipt(record)));
        row.append(status,action); $('history').append(row);
    }
    if (!records.length) { const row = node('tr'), cell = node('td','No local sales yet.'); cell.colSpan = 5; row.append(cell); $('history').append(row); }
    if (currentReceipt && !locked) { const updated = records.find(r => r.eventId === currentReceipt.eventId); if (updated) showReceipt(updated); }
    toggleButtons();
}
function renderProducts() {
    $('products').replaceChildren();
    const products = state.snapshot?.products || [], search = $('search').value.trim().toLowerCase();
    for (const product of products.filter(p => (p.name+' '+p.sku+' '+p.options.map(o => o.barcode).join(' ')).toLowerCase().includes(search))) {
        const units = product.options.reduce((sum,o) => sum+(state.draft?.cart[o.id] || 0)*o.units,0);
        const card = node('article','','pos-product'+(units ? ' in-cart' : ''));
        const image = document.createElement('img'); image.src = '/images/product-placeholder.svg'; image.alt = 'Image placeholder for '+product.name; image.className = 'product-image'; image.width = 240; image.height = 180;
        card.append(image,node('h3',product.name),node('p',state.remaining[product.id]+' '+product.unit+'s sellable · '+product.sku,'muted'));
        if (units) card.append(node('span','✓ In cart · '+units+' base units','cart-status'));
        const options = node('div','','options');
        for (const option of product.options) {
            const optionButton = button('',async()=>{ await store.edit(draft=>{ draft.cart[option.id] = (draft.cart[option.id] || 0)+1; draft.confirmed = false; }); });
            optionButton.append(node('span',option.label),node('strong',amount(option.price))); options.append(optionButton);
        }
        card.append(options); $('products').append(card);
    }
    if (!$('products').children.length) $('products').append(node('p',state.snapshot ? 'No matching product.' : 'Prepare this browser to save the current catalogue.','muted'));
}
function renderCart() {
    const draft = state.draft || {cart:{},method:'Cash',received:'',confirmed:false,customerId:'',dueDate:''};
    $('cart').replaceChildren(); $('cart').className = Object.keys(draft.cart).length ? '' : 'empty';
    let total = 0;
    for (const [id,quantity] of Object.entries(draft.cart)) {
        const product = state.snapshot?.products.find(p=>p.options.some(o=>o.id === id)), option = product?.options.find(o=>o.id === id);
        if (!option) continue;
        const line = node('div','','cart-line'), info = node('div'); info.append(node('strong',product.name),node('p',option.label+' · '+option.units*quantity+' base units'));
        const input = document.createElement('input'); input.type = 'number'; input.min = 1; input.max = 9999; input.value = quantity; input.setAttribute('aria-label','Quantity for '+product.name+' '+option.label);
        input.addEventListener('change',()=>run(async()=>{ const value=Number(input.value); if (!Number.isInteger(value) || value < 1 || value > 9999) throw new Error('Use a whole quantity from 1 to 9999.'); await store.edit(d=>{d.cart[id]=value;d.confirmed=false;}); }));
        line.append(info,input,node('strong',amount(option.price*quantity)),button('Remove',()=>store.edit(d=>{delete d.cart[id];d.confirmed=false;}))); $('cart').append(line); total += option.price*quantity;
    }
    if (!$('cart').children.length) $('cart').append(node('p','Your cart is empty. Add an individual item or package.'));
    $('total').textContent = amount(total);
    for (const [id,value] of [['method',draft.method],['received',draft.received],['due-date',draft.dueDate]]) { if (document.activeElement !== $(id) && !editingFields.has(id)) $(id).value = value; }
    $('confirmed').checked = draft.confirmed;
    const customers = state.snapshot?.customers || [];
    $('customer').replaceChildren(); const placeholder = node('option','Select saved customer'); placeholder.value = ''; $('customer').append(placeholder);
    for (const customer of customers) { const option=node('option',customer.name);option.value=customer.id;$('customer').append(option); }
    $('customer').value = draft.customerId;
    $('cash-field').hidden = draft.method !== 'Cash'; $('credit-fields').hidden = draft.method !== 'Credit'; $('digital-fields').hidden = !['GCash','Maya'].includes(draft.method);
}
function showReceipt(record) {
    currentReceipt = record;
    const receipt = record.receipt || record.localReceipt, panel = $('receipt'); panel.hidden = false; panel.replaceChildren();
    panel.append(node('h2',receipt.shop),node('p',(record.status === 'Synced' ? 'SERVER CONFIRMED' : 'SAVED LOCALLY · UPLOAD PENDING')+' · NOT A FISCAL RECEIPT','receipt-note'),node('p',receipt.number+' · '+new Date(receipt.time).toLocaleString('en-PH')));
    if (record.receipt) panel.append(node('p','Original local receipt: '+record.localReceipt.number,'muted'));
    for (const line of receipt.lines) { const item=node('div','','product'), title=node('p','','spread');title.append(node('span',line.name),node('strong',amount(line.total)));item.append(title,node('small',line.label+' × '+line.quantity+' @ '+amount(line.price)));panel.append(item); }
    const total=node('p','','spread');total.append(node('strong','Total'),node('strong',amount(receipt.total)));panel.append(node('hr'),total,node('p','Payment: '+receipt.method));
    if (receipt.method === 'Cash') panel.append(node('p','Cash received: '+amount(receipt.cashReceived)+' · Change: '+amount(receipt.change)));
    if (receipt.method === 'Credit') panel.append(node('p','Customer: '+receipt.customer+' · Due: '+(receipt.dueDate || 'Not set')));
    panel.append(node('p','Owner · Counter 1 · Currency and tax settings pending.','muted'));
    const actions=node('div','','form-actions no-print');actions.append(button('Print / Save PDF',()=>window.print()));panel.append(actions);
}
function synchronize() {
    if (!syncPromise) syncPromise = uploadPending().finally(()=>{syncPromise=null;});
    return syncPromise;
}
async function uploadPending() {
    await connect();
    while (true) {
        const record = (await store.allSales()).find(r=>r.status !== 'Synced');
        if (!record) break;
        try { record.receipt = await request('/offline/sync',record.payload); record.status = 'Synced'; record.error = ''; await store.updateRecord(record); }
        catch (error) { record.error = error.message; record.status = online && signedIn ? 'Needs review' : 'Pending'; await store.updateRecord(record); throw error; }
    }
    notify('All saved sales are confirmed by the shop database.');
}
async function automaticSync() {
    if (!store || busy || localStorage.getItem('wynn-offline-signed-out')) return;
    try { await synchronize(); } catch (error) { notify(error.message); }
    await render();
}
async function start() {
    if (!window.isSecureContext || !('indexedDB' in window) || !('serviceWorker' in navigator)) { notify('Offline checkout needs a supported browser over localhost or HTTPS.'); return; }
    try {
        try { await navigator.serviceWorker.register('/offline-sw.js'); }
        catch (error) { if (!await navigator.serviceWorker.getRegistration('/')) throw error; }
        await navigator.serviceWorker.ready;
        store = await OfflineStore.open();
        const persisted = await navigator.storage?.persist?.().catch(()=>false);
        $('storage-status').textContent = persisted ? 'Persistent storage granted' : 'Browser storage · Export copies recommended';
        await render();
        try { await connect(); if (state.snapshot) notify('This browser is prepared. Saved sales upload automatically while this page is open.'); }
        catch (error) { notify(error.message); }
        await render();
    } catch { notify('Offline storage or the cached page could not be prepared. Keep the shop connected and retry in a supported browser.'); }
}
$('prepare').addEventListener('click',()=>run(async()=>{ await connect(); const activation=await store.activation(); const snapshot=await request('/offline/prepare',activation); await store.prepared(snapshot); notify('Offline checkout prepared. Other shop changes pause until this session finishes.'); }));
$('sync').addEventListener('click',()=>run(synchronize));
$('finish').addEventListener('click',()=>run(async()=>{ const data=await store.seal(); await synchronize(); await request('/offline/finish',data); await store.finished(); notify('Session finished. Connected shop changes are available again. Local receipt history is retained.'); }));
$('complete').addEventListener('click',()=>run(async()=>{ const record=await store.complete(); showReceipt(record); notify('Sale '+record.localReceipt.number+' saved locally. Its upload remains pending until confirmed.'); }));
$('search').addEventListener('input',()=>{ if (state?.snapshot) renderProducts(); });
$('search-form').addEventListener('submit',event=>{ event.preventDefault(); run(async()=>{ const barcode=$('search').value.trim(); const option=state?.snapshot?.products.flatMap(p=>p.options).find(o=>o.barcode === barcode); if (!option) throw new Error('No matching barcode. Search by product name or SKU.'); await store.edit(d=>{d.cart[option.id]=(d.cart[option.id] || 0)+1;d.confirmed=false;});$('search').value=''; }); });
for (const [id,key] of [['method','method'],['received','received'],['customer','customerId'],['due-date','dueDate'],['confirmed','confirmed']]) {
    $(id).addEventListener(['received','due-date'].includes(id) ? 'input' : 'change',async()=>{
        const value=id === 'confirmed' ? $(id).checked : $(id).value;
        editingFields.set(id,value);
        try { await store.edit(d=>{d[key]=value;if(id === 'method')d.confirmed=false;}); }
        catch(error) { notify(error.message); }
        finally { if (editingFields.get(id) === value) editingFields.delete(id); }
        await render();
    });
}
$('export').addEventListener('click',()=>run(async()=>{ const data=await store.recoveryCopy();const url=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;link.download='wynn-offline-recovery-'+new Date().toISOString().slice(0,10)+'.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);notify('Recovery copy downloaded. Store it privately; it includes sales and customer names.'); }));
$('import').addEventListener('click',()=> $('recovery-file').click());
$('recovery-file').addEventListener('change',()=>run(async()=>{ await connect(); const file=$('recovery-file').files[0];if(!file || file.size>10*1024*1024)throw new Error('Choose a recovery JSON copy smaller than 10 MB.');const copy=JSON.parse(await file.text());await store.restore(copy);notify('Recovery copy loaded. New sales are paused. Synchronize and finish its original session; confirmed uploads will not be duplicated.'); }));
window.addEventListener('online',automaticSync);
window.addEventListener('offline',()=>{online=false;render();});
window.addEventListener('storage',event=>{if(event.key === 'wynn-offline-signed-out')render();});
document.addEventListener('visibilitychange',()=>{if(document.visibilityState === 'visible' && store && !busy)render();});
setInterval(automaticSync,20000);
start();
