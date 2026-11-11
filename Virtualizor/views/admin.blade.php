<style>
.vza{background:#fff;color:#1f2937;border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-bottom:16px;font-size:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.vza *{box-sizing:border-box}
.vza h3{margin:0;font-size:17px;font-weight:700;color:#111827}
.vza .hd{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px}
.vza .pill{display:inline-block;padding:4px 12px;border-radius:999px;font-weight:600;font-size:12px}
.vza .pill.online{background:#dcfce7;color:#15803d}.vza .pill.offline{background:#fee2e2;color:#b91c1c}.vza .pill.suspended{background:#fef3c7;color:#b45309}.vza .pill.none{background:#e5e7eb;color:#4b5563}
.vza .b{cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151;padding:7px 13px;border-radius:8px;font-size:13px;font-weight:600}
.vza .b:hover{background:#f3f4f6}
.vza .b.blue{background:#2563eb;border-color:#2563eb;color:#fff}.vza .b.green{background:#16a34a;border-color:#16a34a;color:#fff}.vza .b.red{background:#dc2626;border-color:#dc2626;color:#fff}.vza .b.amber{background:#f59e0b;border-color:#f59e0b;color:#fff}
.vza .b[disabled]{opacity:.5;cursor:wait}
.vza .bar{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}
.vza .info{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:10px 0}
.vza .info div{background:#f9fafb;border:1px solid #eef0f3;border-radius:9px;padding:9px 12px}
.vza .info small{display:block;color:#6b7280;font-size:11px;text-transform:uppercase;font-weight:600}
.vza .info b{color:#111827}
.vza .forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-top:12px}
.vza .f{border:1px solid #e5e7eb;border-radius:10px;padding:12px}
.vza .f h4{margin:0 0 8px;font-size:13px;font-weight:700;color:#111827}
.vza input,.vza select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#111827;font-size:13px;margin-bottom:8px}
.vza .toast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:100000;padding:11px 20px;border-radius:10px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18)}
.vza .toast.ok{background:#16a34a;color:#fff}.vza .toast.err{background:#dc2626;color:#fff}
.vza .modal{position:fixed;inset:0;background:rgba(17,24,39,.65);z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px}
.vza .win{background:#fff;border-radius:14px;width:min(1100px,100%);height:min(720px,92vh);display:flex;flex-direction:column;overflow:hidden}
.vza .win header{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #e5e7eb}
.vza .win iframe{flex:1;border:0;width:100%}
[x-cloak]{display:none!important}
</style>

<div class="vza" x-cloak x-show="ok" x-data="{
    ok:false, id:null, d:null, st:'', busy:null, toast:null,
    h:'', pw:'', os:'', rpw:'', plid:'', vpsid:'',
    csrf:@js(csrf_token()), root:@js(url('/extensions/virtualizor/admin')),
    async init() {
        const m = location.pathname.match(/services\/(\d+)/);
        if (!m) return;
        this.id = m[1];
        await this.load();
    },
    async req(action, body = null) {
        const r = await fetch(this.root + '/' + this.id + '/do/' + action, {method: body ? 'POST' : 'GET', headers:{'X-CSRF-TOKEN':this.csrf,'Accept':'application/json','Content-Type':'application/json'}, body: body ? JSON.stringify(body) : undefined});
        return r.json().catch(() => ({ok:false, message:'Bad response'}));
    },
    async load() {
        const j = await this.req('info');
        if (j.ok) { this.d = j.data; this.st = j.status; this.h = j.data.hostname || ''; this.vpsid = ''; this.ok = true; }
        else if (j.message && !/not found|Not allowed/i.test(j.message)) { this.ok = false; }
    },
    async run(action, body = {}, reload = true) {
        this.busy = action;
        const j = await this.req(action, body);
        this.busy = null;
        this.toast = {ok: j.ok, t: j.message || (j.ok ? 'Done' : 'Error')};
        setTimeout(() => this.toast = null, 5000);
        if (j.ok && reload && action !== 'console' && action !== 'panel') setTimeout(() => this.load(), 1500);
        return j;
    },
    async console() { const w = window.open('', '_blank'); const j = await this.run('console', {}, false); if (j.ok && j.url) { if (w) { w.location.href = j.url; } else { window.location.href = j.url; } } else if (w) { w.close(); } },
    async panel() { const j = await this.run('panel', {}, false); if (j.ok && j.url) window.open(j.url, '_blank'); },
    get state() { if (!this.d || !this.d.linked) return 'none'; return this.d.suspended ? 'suspended' : (this.d.running ? 'online' : 'offline'); }
}">
    <template x-if="toast"><div class="toast" :class="toast.ok ? 'ok' : 'err'" x-text="toast.t"></div></template>

    <div class="hd">
        <h3>Virtualizor VPS Management</h3>
        <span>
            <span class="pill" :class="state" x-text="state === 'none' ? 'Not linked' : state"></span>
            <button type="button" class="b" x-on:click="load()">Refresh</button>
        </span>
    </div>

    <template x-if="d && d.linked && d.error"><div style="color:#b91c1c;margin-bottom:8px" x-text="d.error"></div></template>

    <template x-if="d && d.linked && !d.error">
        <div>
            <div class="info">
                <div><small>VPS ID</small><b x-text="d.vpsid"></b></div>
                <div><small>Hostname</small><b x-text="d.hostname || '-'"></b></div>
                <div><small>IP</small><b x-text="(d.ips || []).join(', ') || '-'"></b></div>
                <div><small>Plan</small><b x-text="d.plan || '-'"></b></div>
                <div><small>OS</small><b x-text="d.os || '-'"></b></div>
                <div><small>Node</small><b x-text="d.node || '-'"></b></div>
                <div><small>RAM / Disk / CPU</small><b x-text="(d.ram||'-')+' MB / '+(d.disk||'-')+' GB / '+(d.cores||'-')"></b></div>
                <div><small>Bandwidth</small><b x-text="(d.used_bandwidth||0)+' / '+(d.bandwidth||'unlimited')+' GB'"></b></div>
                <div><small>Root password</small><b x-text="d.rootpass || '-'"></b></div>
            </div>

            <div class="bar">
                <button type="button" class="b blue" x-on:click="console()" :disabled="busy">Console (VNC)</button>
                <button type="button" class="b" x-on:click="panel()" :disabled="busy">Open user panel</button>
                <button type="button" class="b green" x-on:click="run('start')" :disabled="busy">Start</button>
                <button type="button" class="b" x-on:click="confirm('Restart?') && run('restart')" :disabled="busy">Restart</button>
                <button type="button" class="b" x-on:click="confirm('Stop?') && run('stop')" :disabled="busy">Stop</button>
                <button type="button" class="b red" x-on:click="confirm('Force power off?') && run('poweroff')" :disabled="busy">Force off</button>
                <button type="button" class="b amber" x-on:click="confirm('Suspend this VPS?') && run('suspend')" :disabled="busy">Suspend</button>
                <button type="button" class="b green" x-on:click="confirm('Unsuspend this VPS?') && run('unsuspend')" :disabled="busy">Unsuspend</button>
                <button type="button" class="b red" x-on:click="confirm('PERMANENTLY delete this VPS from Virtualizor?') && run('terminate')" :disabled="busy">Terminate</button>
            </div>

            <div class="forms">
                <div class="f"><h4>Hostname</h4><input x-model="h"><button type="button" class="b blue" x-on:click="run('hostname',{hostname:h})" :disabled="busy">Save</button></div>
                <div class="f"><h4>Root password</h4><input x-model="pw" placeholder="empty = random"><button type="button" class="b blue" x-on:click="run('password',{password:pw})" :disabled="busy">Change</button></div>
                <div class="f"><h4>Change plan</h4><input x-model="plid" placeholder="Virtualizor plan ID" type="number"><button type="button" class="b blue" x-on:click="plid && run('plan',{plid:plid})" :disabled="busy">Apply</button></div>
                <div class="f"><h4>Reinstall OS</h4>
                    <select x-model="os"><option value="">Select OS…</option><template x-for="(n,i) in d.oses" :key="i"><option :value="i" x-text="n"></option></template></select>
                    <input x-model="rpw" placeholder="New root password (min 8)">
                    <button type="button" class="b red" x-on:click="os && rpw.length>=8 ? (confirm('All data will be erased!') && run('rebuild',{os_id:os,password:rpw})) : (toast={ok:false,t:'Select OS and password (min 8)'})" :disabled="busy">Reinstall</button>
                </div>
                <div class="f"><h4>Change linked VPS ID</h4><input x-model="vpsid" type="number" placeholder="New VPS ID"><button type="button" class="b" x-on:click="vpsid && run('link',{vpsid:vpsid})" :disabled="busy">Save</button></div>
            </div>
        </div>
    </template>

    <template x-if="d && !d.linked">
        <div>
            <p style="color:#6b7280;margin:0 0 10px">This service is not linked to a Virtualizor VPS yet.</p>
            <div class="forms">
                <div class="f"><h4>Link manually</h4><input x-model="vpsid" type="number" placeholder="Virtualizor VPS ID"><button type="button" class="b blue" x-on:click="vpsid && run('link',{vpsid:vpsid})" :disabled="busy">Link</button></div>
                <div class="f"><h4>Link automatically</h4><p style="margin:0 0 8px;color:#6b7280;font-size:12px">Searches by hostname / IP.</p><button type="button" class="b" x-on:click="run('autolink')" :disabled="busy">Find VPS</button></div>
                <div class="f"><h4>Create new VPS</h4><p style="margin:0 0 8px;color:#6b7280;font-size:12px">Provisions a new VPS on Virtualizor.</p><button type="button" class="b green" x-on:click="confirm('Create a new VPS?') && run('create')" :disabled="busy">Create VPS</button></div>
            </div>
        </div>
    </template>

</div>
