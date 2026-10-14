import {test} from 'node:test';
import assert from 'node:assert/strict';
import {money,draftSale,validateRecovery,discountedLines} from '../../public/js/offline-store.js';

const at=Date.parse('2026-10-12T12:00:00Z');
function state() {
    return {snapshot:{preparedAt:'2026-10-12T11:00:00Z',validUntil:'2026-10-12T13:00:00Z',products:[{id:1,name:'Drink',options:[{id:'one',label:'Individual',units:1,price:33},{id:'pack',label:'Pack of 3',units:3,price:95}]}],customers:[{id:'customer',name:'Customer'}]},remaining:{1:10},draft:{cart:{one:2,pack:1},method:'Cash',received:'2',confirmed:false,customerId:'',dueDate:''},sealed:false};
}
test('cash uses exact cents and rejects rounding or oversized input',()=>{
    assert.equal(money('0.33'),33);assert.equal(money('200'),20000);assert.equal(money('1.2'),120);
    for(const value of ['-1','1.001','1e3','Infinity','9007199254740991',''])assert.throws(()=>money(value));
});

test('offline receipts preserve prepared business details without sharing a mutable profile',()=>{
    const saved=state();saved.snapshot.receiptProfile={currency:'PHP',shopName:'Original shop',address:'Original address',footer:'Saved thanks',taxStatus:'Unconfirmed',invoiceRequirement:'BIR'};
    const receipt=draftSale(saved,at);saved.snapshot.receiptProfile.address='Changed address';
    assert.equal(receipt.receiptProfile.address,'Original address');assert.equal(receipt.receiptProfile.footer,'Saved thanks');
    assert.equal(receipt.receiptProfile.currency,'PHP');assert.equal(receipt.total,161);
    delete saved.snapshot.receiptProfile;assert.equal(draftSale(saved,at).receiptProfile.currency,'PHP');
});
test('individual and package quantities share base stock and exact totals',()=>{
    const draft=draftSale(state(),at);assert.equal(draft.total,161);assert.equal(draft.requirements[1],5);assert.equal(draft.change,39);
    assert.equal(draft.lines[1].baseQuantity,3);
});
test('all package options aggregate stock before completing a cart',()=>{
    const saved=state();saved.remaining[1]=4;assert.throws(()=>draftSale(saved,at),/remaining sellable stock/);
    saved.remaining[1]=100;for(const value of [-1,0,1.5,10000,'2']){saved.draft.cart={one:value};assert.throws(()=>draftSale(saved,at));}
});
test('expired, unprepared and sealed sessions cannot issue local sales',()=>{
    assert.throws(()=>draftSale(state(),Date.parse('2026-10-12T13:00:00Z')),/expired/);
    assert.throws(()=>draftSale(state(),Date.parse('2026-10-12T10:59:59Z')),/expired/);
    const saved=state();saved.sealed=true;assert.throws(()=>draftSale(saved,at));saved.sealed=false;saved.snapshot=null;assert.throws(()=>draftSale(saved,at));
});
test('cash coverage and actual payment confirmation are required',()=>{
    const saved=state();saved.draft.received='1.60';assert.throws(()=>draftSale(saved,at),/cover/);
    saved.draft.method='Maya';assert.throws(()=>draftSale(saved,at),/Verify/);saved.draft.confirmed=true;saved.draft.received='1.61';assert.equal(draftSale(saved,at).cashReceived,null);
});
test('digital amounts must match exactly and are retained separately from cash',()=>{
    for(const method of ['GCash','Maya']){
        const saved=state();saved.draft.method=method;saved.draft.confirmed=true;
        for(const received of ['', '0', '1.60', '1.62', '1.611', '-1.61', '1e2']){saved.draft.received=received;assert.throws(()=>draftSale(saved,at));}
        saved.draft.received='1.61';const receipt=draftSale(saved,at);
        assert.equal(receipt.paymentReceived,161);assert.equal(receipt.cashReceived,null);assert.equal(receipt.change,0);
    }
});
test('discounts allocate exact cents and change cash and digital totals without changing stock requirements',()=>{
    const saved=state();saved.draft.discount='0.11';saved.draft.discountReason='Promotion';
    const receipt=draftSale(saved,at);assert.equal(receipt.subtotal,161);assert.equal(receipt.discount,11);assert.equal(receipt.total,150);
    assert.deepEqual(receipt.lines.map(line=>line.discount),[4,7]);assert.equal(receipt.change,50);assert.equal(receipt.requirements[1],5);
    saved.draft.method='GCash';saved.draft.confirmed=true;saved.draft.received='1.61';assert.throws(()=>draftSale(saved,at),/must match/);
    saved.draft.received='1.50';assert.equal(draftSale(saved,at).paymentReceived,150);
});
test('invalid discounts and missing reasons cannot produce an offline receipt',()=>{
    const saved=state();saved.draft.discountReason='Promotion';
    for(const discount of ['-1','1.61','1.62','0.001','1e2',[]]){saved.draft.discount=discount;assert.throws(()=>draftSale(saved,at));}
    saved.draft.discount='0.01';for(const reason of ['', 'ab', 'x'.repeat(201)]){saved.draft.discountReason=reason;assert.throws(()=>draftSale(saved,at));}
});
test('large discounts use exact integer proportions without overflowing Number multiplication',()=>{
    const receipt=discountedLines([{total:4000000000000000},{total:4000000000000000}],'999999999999.99','Large test discount');
    assert.deepEqual(receipt.lines.map(line=>line.discount),[49999999999999,50000000000000]);
    assert.equal(receipt.lines.reduce((sum,line)=>sum+line.total,0),receipt.total);
});
test('line discount shares match the server even when cart insertion order differs',()=>{
    const lines=[{id:'practice-pack',total:2800},{id:'practice-one',total:1000}];
    const forward=discountedLines(lines,'3','Owner promotion'),reverse=discountedLines([...lines].reverse(),'3','Owner promotion');
    assert.equal(forward.lines[0].total,2578);assert.equal(forward.lines[1].total,922);
    assert.deepEqual(Object.fromEntries(forward.lines.map(line=>[line.id,line.total])),Object.fromEntries(reverse.lines.map(line=>[line.id,line.total])));
    assert.deepEqual(forward.lines.map(line=>line.id),['practice-pack','practice-one']);
});
test('credit requires a cached customer and a real local-calendar due date',()=>{
    const saved=state();saved.draft.method='Credit';assert.throws(()=>draftSale(saved,at),/customer/);
    saved.draft.customerId='customer';saved.draft.dueDate='2026-10-12';assert.equal(draftSale(saved,at).customer,'Customer');
    for(const due of ['2026-10-11','2027-02-30','wrong']){saved.draft.dueDate=due;assert.throws(()=>draftSale(saved,at),/due date/);}
});

function recovery() {
    const saved=state();saved.deviceId='11111111-1111-4111-8111-111111111111';saved.snapshot.id='22222222-2222-4222-8222-222222222222';saved.snapshot.deviceId=saved.deviceId;saved.snapshot.products[0].available=10;
    const receipt=draftSale(saved,at),eventId='33333333-3333-4333-8333-333333333333';
    const payload={...saved.draft,eventId,leaseId:saved.snapshot.id,deviceId:saved.deviceId,sequence:1,occurredAt:new Date(at).toISOString(),total:receipt.total};
    saved.remaining[1]=5;saved.nextSequence=2;
    return {version:1,state:saved,sales:[{eventId,payload,localReceipt:{...receipt,number:'OFF-TEST-000001'}}]};
}

test('recovery validates the complete ordered queue against its saved stock',()=>{
    assert.equal(validateRecovery(recovery()).remaining[1],5);
    for(const change of [copy=>copy.sales.pop(),copy=>copy.sales[0].payload.sequence=2,copy=>copy.state.remaining[1]=10,copy=>copy.sales[0].payload.total=1,copy=>copy.sales[0].payload.occurredAt='invalid',copy=>copy.sales.push(copy.sales[0])]){
        const copy=recovery();change(copy);assert.throws(()=>validateRecovery(copy));
    }
});
test('discounted recovery queues recompute the saved net total and reject changed discounts',()=>{
    const copy=recovery();copy.sales[0].payload.discount='0.11';copy.sales[0].payload.discountReason='Promotion';
    copy.sales[0].payload.total=150;copy.sales[0].localReceipt.total=150;assert.equal(validateRecovery(copy).remaining[1],5);
    copy.sales[0].payload.discount='0.10';assert.throws(()=>validateRecovery(copy));
});
test('old digital recovery queues retain unknown amounts while new sessions reject missing amounts',()=>{
    const copy=recovery();copy.sales[0].payload.method='Maya';copy.sales[0].payload.confirmed=true;copy.sales[0].payload.received='';
    assert.equal(validateRecovery(copy).remaining[1],5);
    copy.sales[0].payload.received='2';assert.equal(validateRecovery(copy).remaining[1],5);
    copy.sales[0].payload.paymentVersion=2;assert.throws(()=>validateRecovery(copy));
    copy.sales[0].payload.received='1.61';assert.equal(validateRecovery(copy).remaining[1],5);
    delete copy.sales[0].payload.paymentVersion;copy.sales[0].payload.received='';
    copy.state.snapshot.version=2;assert.throws(()=>validateRecovery(copy));
    copy.sales[0].payload.received='1.61';assert.equal(validateRecovery(copy).remaining[1],5);
});

test('recovery rejects malformed catalogue prices and dates before loading',()=>{
    for(const change of [copy=>copy.state.snapshot.products[0].options[0].price=-1,copy=>copy.state.snapshot.validUntil='invalid',copy=>copy.state.snapshot.deviceId='other']){
        const copy=recovery();change(copy);assert.throws(()=>validateRecovery(copy));
    }
});
