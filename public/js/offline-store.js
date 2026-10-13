export const money = (value) => {
    if (!/^\d{1,12}(\.\d{1,2})?$/.test(value)) throw new Error('Enter a cash amount with at most two decimal places.');
    const [whole, fraction = ''] = value.split('.');
    const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    if (!Number.isSafeInteger(cents)) throw new Error('The cash amount is too large.');
    return cents;
};

export function draftSale(state, at) {
    if (!Number.isFinite(at)) throw new Error('The saved sale time is invalid.');
    if (!state.snapshot || state.sealed) throw new Error('Prepare checkout online, or finish the current session before starting another.');
    if (at < Date.parse(state.snapshot.preparedAt) || at >= Date.parse(state.snapshot.validUntil)) throw new Error('This offline session has expired. Synchronize and finish it online, then prepare again.');
    const draft = state.draft || { cart: {}, method: 'Cash', received: '', confirmed: false, customerId: '', dueDate: '' };
    const cart = draft.cart;
    if (!Object.keys(cart).length || Object.keys(cart).length > 200) throw new Error('Add products to the cart first.');
    const lines = [], requirements = {};
    for (const [id, quantity] of Object.entries(cart)) {
        const product = state.snapshot.products.find(p => p.options.some(o => o.id === id));
        const option = product?.options.find(o => o.id === id);
        if (!option || !Number.isInteger(quantity) || quantity < 1 || quantity > 9999) throw new Error('Use whole quantities from 1 to 9999.');
        const baseQuantity = option.units * quantity, total = option.price * quantity;
        if (!Number.isSafeInteger(baseQuantity) || !Number.isSafeInteger(total)) throw new Error('The quantity or total is too large.');
        requirements[product.id] = (requirements[product.id] || 0) + baseQuantity;
        lines.push({id, productId: product.id, name: product.name, label: option.label, units: option.units, price: option.price, quantity, baseQuantity, total});
    }
    for (const [id, required] of Object.entries(requirements)) {
        if (required > state.remaining[id]) throw new Error('The cart exceeds the remaining sellable stock.');
    }
    const total = lines.reduce((sum, line) => sum + line.total, 0);
    if (!Number.isSafeInteger(total) || total < 1) throw new Error('The sale total is invalid.');
    if (!['Cash', 'GCash', 'Maya', 'Credit'].includes(draft.method)) throw new Error('Choose a payment method.');
    let cashReceived = null;
    if (draft.method === 'Cash') {
        cashReceived = money(draft.received);
        if (cashReceived < total) throw new Error('Cash received must cover the total.');
    } else if (draft.method === 'Credit') {
        if (!state.snapshot.customers.some(c => c.id === draft.customerId)) throw new Error('Choose a saved credit customer.');
        const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(at).map(p=>[p.type,p.value]));
        const today = parts.year+'-'+parts.month+'-'+parts.day;
        if (draft.dueDate && (!/^\d{4}-\d{2}-\d{2}$/.test(draft.dueDate) || draft.dueDate < today || Number.isNaN(Date.parse(draft.dueDate)) || new Date(draft.dueDate+'T00:00:00Z').toISOString().slice(0,10) !== draft.dueDate)) throw new Error('Choose a valid due date on or after the sale date.');
    } else if (!draft.confirmed) throw new Error('Verify the payment in the payment app first.');
    return {lines, requirements, total, method: draft.method, cashReceived, change: cashReceived === null ? 0 : cashReceived - total,
        customer: state.snapshot.customers.find(c => c.id === draft.customerId)?.name ?? null, dueDate: draft.dueDate};
}

export function validateRecovery(copy) {
    const invalid = () => { throw new Error('The recovery copy is incomplete or invalid. Keep the original browser data.'); };
    const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    const saved = copy?.state, snapshot = saved?.snapshot;
    if (copy?.version !== 1 || !snapshot || !uuid.test(saved.deviceId) || !uuid.test(snapshot.id) || snapshot.deviceId !== saved.deviceId
        || !Array.isArray(snapshot.products) || !Array.isArray(snapshot.customers) || !Array.isArray(copy.sales) || copy.sales.length > 100000
        || !Number.isSafeInteger(saved.nextSequence) || saved.nextSequence < 1 || saved.nextSequence > 1000001 || !saved.remaining || !saved.draft
        || !Number.isFinite(Date.parse(snapshot.preparedAt)) || Date.parse(snapshot.validUntil) <= Date.parse(snapshot.preparedAt) || !Number.isFinite(Date.parse(snapshot.validUntil))) invalid();
    const remaining = Object.create(null), options = new Set(), events = new Set();
    for (const product of snapshot.products) {
        if (product.id == null || Object.hasOwn(remaining, product.id) || typeof product.name !== 'string' || !Number.isSafeInteger(product.available) || product.available < 0 || !Array.isArray(product.options)) invalid();
        remaining[product.id] = product.available;
        for (const option of product.options) {
            if (typeof option.id !== 'string' || options.has(option.id) || !Number.isSafeInteger(option.units) || option.units < 1 || !Number.isSafeInteger(option.price) || option.price < 1) invalid();
            options.add(option.id);
        }
    }
    const current = [];
    for (const record of copy.sales) {
        if (!uuid.test(record.eventId) || events.has(record.eventId) || record.eventId !== record.payload?.eventId || !record.localReceipt?.number || !Array.isArray(record.localReceipt.lines)
            || !uuid.test(record.payload.leaseId) || !uuid.test(record.payload.deviceId) || !Number.isSafeInteger(record.payload.sequence) || record.payload.sequence < 1) invalid();
        events.add(record.eventId);
        if (record.payload.leaseId === snapshot.id) current.push(record);
    }
    current.sort((a,b)=>a.payload.sequence-b.payload.sequence);
    if (current.length !== saved.nextSequence - 1) invalid();
    for (const [index,record] of current.entries()) {
        if (record.payload.sequence !== index + 1 || record.payload.deviceId !== saved.deviceId) invalid();
        const draft = draftSale({snapshot,remaining,draft:record.payload,sealed:false},Date.parse(record.payload.occurredAt));
        if (draft.total !== record.payload.total || draft.total !== record.localReceipt.total) invalid();
        for (const [id,required] of Object.entries(draft.requirements)) remaining[id] -= required;
    }
    if (Object.keys(remaining).length !== Object.keys(saved.remaining).length || Object.entries(remaining).some(([id,quantity])=>saved.remaining[id] !== quantity)) invalid();
    return saved;
}

export class OfflineStore {
    constructor(database) { this.database = database; }
    static open(name = 'wynn-mart-offline-v1') {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(name, 1);
            request.onupgradeneeded = () => { request.result.createObjectStore('meta', {keyPath: 'key'}); request.result.createObjectStore('sales', {keyPath: 'eventId'}); };
            request.onsuccess = () => { request.result.onversionchange = () => request.result.close(); resolve(new OfflineStore(request.result)); };
            request.onerror = () => reject(new Error('Local storage could not be opened. Offline checkout is unavailable.'));
            request.onblocked = () => reject(new Error('Close older checkout tabs, then reload to update local storage.'));
        });
    }
    change(action) {
        return new Promise((resolve, reject) => {
            let transaction;
            try { transaction = this.database.transaction(['meta', 'sales'], 'readwrite', {durability: 'strict'}); }
            catch { transaction = this.database.transaction(['meta', 'sales'], 'readwrite'); }
            let result, error;
            transaction.oncomplete = () => resolve(result);
            transaction.onabort = () => reject(error || new Error('The sale could not be saved locally. No receipt was issued. Check browser storage.'));
            transaction.onerror = () => {};
            const meta = transaction.objectStore('meta'), sales = transaction.objectStore('sales');
            const request = meta.get('state');
            request.onsuccess = () => {
                const state = request.result || {key: 'state', deviceId: crypto.randomUUID(), nextSequence: 1};
                try { result = action(state, sales); meta.put(state); }
                catch (caught) { error = caught; transaction.abort(); }
            };
        });
    }
    state() { return this.change(state => structuredClone(state)); }
    recoveryCopy() {
        return new Promise((resolve, reject) => {
            const transaction = this.database.transaction(['meta', 'sales'], 'readonly');
            const state = transaction.objectStore('meta').get('state');
            const sales = transaction.objectStore('sales').getAll();
            transaction.oncomplete = () => resolve({version:1,exportedAt:new Date().toISOString(),state:state.result,sales:sales.result});
            transaction.onabort = transaction.onerror = () => reject(new Error('The recovery copy could not be read. No copy was exported.'));
        });
    }
    allSales() {
        return new Promise((resolve, reject) => {
            const request = this.database.transaction('sales').objectStore('sales').getAll();
            request.onsuccess = () => resolve(request.result.sort((a,b) => a.payload.sequence - b.payload.sequence));
            request.onerror = () => reject(new Error('Saved receipts could not be read.'));
        });
    }
    activation() { return this.change(state => { state.activationId ||= crypto.randomUUID(); return {id: state.activationId, deviceId: state.deviceId}; }); }
    prepared(snapshot) {
        return this.change(state => {
            if (state.snapshot) {
                if (state.snapshot.id !== snapshot.id) throw new Error('Finish the saved offline session before preparing another.');
                return;
            }
            if (snapshot.id !== state.activationId || snapshot.deviceId !== state.deviceId) throw new Error('The prepared session does not match this browser.');
            state.snapshot = snapshot; state.remaining = Object.fromEntries(snapshot.products.map(p => [p.id, p.available]));
            state.nextSequence = 1; state.sealed = false; state.draft = {cart: {}, method: 'Cash', received: '', confirmed: false, customerId: '', dueDate: ''};
            state.clockOffset = Date.parse(snapshot.preparedAt) + 2 - Date.now(); state.lastTime = Date.parse(snapshot.preparedAt) + 2;
        });
    }
    edit(action) { return this.change(state => { if (state.sealed) throw new Error('The session is finishing. Its saved sales can still synchronize.'); state.draft ||= {cart:{},method:'Cash',received:'',confirmed:false,customerId:'',dueDate:''}; action(state.draft); }); }
    complete() {
        return this.change((state, sales) => {
            if (localStorage.getItem('wynn-offline-signed-out')) throw new Error('Sign in online before continuing this checkout.');
            const at = Math.max(Date.now() + (state.clockOffset || 0), state.lastTime || 0);
            const receipt = draftSale(state, at), eventId = crypto.randomUUID(), sequence = state.nextSequence;
            if (sequence > 1000000) throw new Error('Finish this session before recording more sales.');
            const payload = {leaseId: state.snapshot.id, deviceId: state.deviceId, eventId, sequence, occurredAt: new Date(at).toISOString(),
                cart: {...state.draft.cart}, method: state.draft.method, received: state.draft.received, confirmed: state.draft.confirmed,
                customerId: state.draft.customerId, dueDate: state.draft.dueDate, total: receipt.total};
            const localReceipt = {...receipt, shop: state.snapshot.shop, number: 'OFF-'+state.snapshot.id.slice(0,8).toUpperCase()+'-'+String(sequence).padStart(6,'0'), time: payload.occurredAt};
            const record = {eventId, payload, localReceipt, status: 'Pending', error: ''};
            sales.add(record);
            for (const [id, required] of Object.entries(receipt.requirements)) state.remaining[id] -= required;
            state.lastTime = at; state.nextSequence++; state.draft = {cart:{},method:'Cash',received:'',confirmed:false,customerId:'',dueDate:''};
            return record;
        });
    }
    updateRecord(record) { return this.change((state, sales) => { sales.put(record); }); }
    async restore(copy) {
        const records = await this.allSales(), current = await this.state();
        if (current.snapshot || records.some(r=>r.status !== 'Synced')) throw new Error('Recovery copies can only be restored in a browser with no active session or pending uploads.');
        const saved = validateRecovery(copy);
        return this.change((state,sales)=>{
            if (state.snapshot) throw new Error('Another tab prepared checkout. No recovery data was loaded.');
            Object.assign(state, saved, {key:'state',sealed:true});
            for (const record of copy.sales) sales.put({...record,status:'Pending',receipt:undefined,error:''});
        });
    }
    seal() { return this.change(state => { if (!state.snapshot) throw new Error('No offline session is prepared.'); state.sealed = true; return {id:state.snapshot.id,deviceId:state.deviceId,lastSequence:state.nextSequence-1}; }); }
    finished() { return this.change(state => { state.snapshot = null; state.activationId = null; state.sealed = false; state.draft = {cart:{},method:'Cash',received:'',confirmed:false,customerId:'',dueDate:''}; }); }
}
