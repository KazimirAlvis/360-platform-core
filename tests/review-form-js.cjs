// Exercise the real browser script with controlled network timing and a small DOM adapter.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
 constructor() { this.listeners = {}; this.dataset = {}; this.options = []; this.value = ''; this.disabled = false; this.attrs = {}; }
 addEventListener(name, cb) { this.listeners[name] = cb; }
 setAttribute(name, value) { this.attrs[name] = value; }
 removeAttribute(name) { delete this.attrs[name]; }
 replaceChildren(...options) { this.options = options; this.value = options[0]?.value || ''; }
 add(option) { this.options.push(option); }
}
const clinic = new Element(); clinic.options = [{value:''},{value:'1'},{value:'2'}];
const doctor = new Element(); const status = new Element(); const retry = new Element();
let feedbackMoved = false;
doctor.closest = () => ({closest: () => null, after: (...nodes) => { assert.deepEqual(nodes,[status,retry]); feedbackMoved = true; }});
const form = new Element(); form.id = 'review-test';
const root = new Element(); root.dataset.doctorsUrl = '/directory/';
root.closest = () => form;
root.querySelector = selector => ({'select[name="clinic-id"]':clinic,'select[name="review-doctor"]':doctor,'[data-review-doctor-status]':status,'[data-review-doctor-retry]':retry})[selector];
const requests = [];
const pendingTimers = new Map(); let timer = 0;
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/js/review-form.js'), 'utf8'), {
 document: {readyState:'complete',querySelectorAll:()=>[root]}, window: new Element(),
 Option: class { constructor(text,value) { this.text=text; this.value=value; } }, AbortController,
 setTimeout: cb => { pendingTimers.set(++timer,cb); return timer; }, clearTimeout:id=>pendingTimers.delete(id),
 fetch: (url,options) => new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
});
const tick = async () => { await new Promise(resolve=>setImmediate(resolve)); };
const respond = (index,id,doctors) => requests[index].resolve({ok:true,json:async()=>({clinic_id:id,doctors})});
(async()=>{
 assert(doctor.disabled); assert.equal(requests.length,0);
 assert(feedbackMoved);
 clinic.value='1'; const first=clinic.listeners.change();
 assert(doctor.disabled); assert.equal(doctor.value,''); assert.match(status.textContent,/Loading/);
 respond(0,1,[{id:11,name:'Doctor A'}]); await first;
 assert(!doctor.disabled); assert.deepEqual(doctor.options.map(o=>o.value),['','0','11']);
 doctor.value='11'; clinic.value='2'; const second=clinic.listeners.change();
 assert(doctor.disabled); assert.equal(doctor.value,'');
 respond(1,2,[{id:22,name:'Doctor B'}]); await second;
 assert.deepEqual(doctor.options.map(o=>o.value),['','0','22']);
 // Resolve requests out of order, even if the old request ignores AbortSignal.
 clinic.value='1'; const old=clinic.listeners.change();
 clinic.value='2'; const latest=clinic.listeners.change();
 respond(3,2,[]); await latest;
 respond(2,1,[{id:11,name:'Stale Doctor'}]); await old;
 assert.deepEqual(doctor.options.map(o=>o.value),['','0']); assert.match(status.textContent,/No doctors/);
 // Network error leaves no usable stale doctor and provides a retry.
 clinic.value='1'; const failure=clinic.listeners.change(); requests[4].reject(new Error('offline')); await failure;
 assert(doctor.disabled); assert.equal(doctor.value,''); assert.equal(retry.hidden,false);
 const recovery=retry.listeners.click(); respond(5,1,[{id:11,name:'Doctor A'}]); await recovery;
 assert(!doctor.disabled); assert(retry.hidden);
 // HTTP rejection is treated as failure, not an empty valid clinic.
 clinic.value='2'; const invalid=clinic.listeners.change(); requests[6].resolve({ok:false}); await invalid;
 assert(doctor.disabled); assert(!retry.hidden);
 clinic.value=''; await clinic.listeners.change(); assert(doctor.disabled); assert(retry.hidden);
 // Reset clears a prior clinic/doctor once the browser resets native form fields.
 clinic.value='1'; const prior=clinic.listeners.change(); respond(7,1,[{id:11,name:'Doctor A'}]); await prior;
 doctor.value='11'; form.listeners.reset(); clinic.value='';
 for (const cb of pendingTimers.values()) cb(); await tick();
 assert(doctor.disabled); assert.equal(doctor.value,'');
 console.log('PASS: initial disabled state, loading, clinic switching, stale-response isolation, empty doctors, network/HTTP failure, retry, and reset');
})().catch(error=>{ console.error(error); process.exitCode=1; });
