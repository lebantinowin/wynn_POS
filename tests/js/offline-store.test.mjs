import {test} from 'node:test';
import assert from 'node:assert/strict';
import {money,draftSale,validateRecovery} from '../../public/js/offline-store.js';

const at=Date.parse('2026-10-12T12:00:00Z');
function state() {
    return {snapshot:{preparedAt:'2026-10-12T11:00:00Z',validUntil:'2026-10-12T13:00:00Z',products:[{id:1,name:'Drink',options:[{id:'one',label:'Individual',units:1,price:33},{id:'pack',label:'Pack of 3',units:3,price:95}]}],customers:[{id:'customer',name:'Customer'}]},remaining:{1:10},draft:{cart:{one:2,pack:1},method:'Cash',received:'2',confirmed:false,customerId:'',dueDate:''},sealed:false};
}
test('cash uses exact cents and rejects rounding or oversized input',()=>{
    assert.equal(money('0.33'),33);assert.equal(money('200'),20000);assert.equal(money('1.2'),120);
    for(const value of ['-1','1.001','1e3','Infinity','9007199254740991',''])assert.throws(()=>money(value));
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
    saved.draft.method='Maya';assert.throws(()=>draftSale(saved,at),/Verify/);saved.draft.confirmed=true;assert.equal(draftSale(saved,at).cashReceived,null);
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

test('recovery rejects malformed catalogue prices and dates before loading',()=>{
    for(const change of [copy=>copy.state.snapshot.products[0].options[0].price=-1,copy=>copy.state.snapshot.validUntil='invalid',copy=>copy.state.snapshot.deviceId='other']){
        const copy=recovery();change(copy);assert.throws(()=>validateRecovery(copy));
    }
});
