@php
    $fmt = fn ($v) => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : $v;
    $state = $d['suspended'] ? 'suspended' : (($d['running'] ?? false) ? 'online' : 'offline');
    $stateLabel = ['online' => 'Online', 'offline' => 'Offline', 'suspended' => 'Suspended'][$state];
    $bwLimit = (float) ($d['bandwidth'] ?? 0);
    $bwUsed = (float) ($d['used_bandwidth'] ?? 0);
    $bwPct = $bwLimit > 0 ? min(100, round($bwUsed / $bwLimit * 100)) : 0;
    $ip = $d['ips'][0] ?? '';
    $base = url('/extensions/virtualizor/' . $service->id . '/do');
@endphp

<style>
.vzc{background:#fff;color:#1f2937;border:1px solid #e5e7eb;border-radius:16px;padding:20px;font-size:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.vzc *{box-sizing:border-box}
.vzc .top{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;padding-bottom:16px;border-bottom:1px solid #eef0f3}
.vzc .host{font-size:20px;font-weight:700;color:#111827}
.vzc .sub{color:#6b7280;margin-top:3px;font-size:13px}
.vzc .pill{display:inline-flex;align-items:center;gap:7px;padding:5px 13px;border-radius:999px;font-weight:600;font-size:13px}
.vzc .pill i{width:8px;height:8px;border-radius:50%;display:inline-block}
.vzc .pill.online{background:#dcfce7;color:#15803d}.vzc .pill.online i{background:#22c55e}
.vzc .pill.offline{background:#fee2e2;color:#b91c1c}.vzc .pill.offline i{background:#ef4444}
.vzc .pill.suspended{background:#fef3c7;color:#b45309}.vzc .pill.suspended i{background:#f59e0b}
.vzc .tools{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}
.vzc .b{cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151;padding:8px 14px;border-radius:9px;font-size:13px;font-weight:600;transition:.15s}
.vzc .b:hover{background:#f3f4f6}
.vzc .b.blue{background:#2563eb;border-color:#2563eb;color:#fff}.vzc .b.blue:hover{background:#1d4ed8}
.vzc .b.green{background:#16a34a;border-color:#16a34a;color:#fff}.vzc .b.green:hover{background:#15803d}
.vzc .b.red{background:#dc2626;border-color:#dc2626;color:#fff}.vzc .b.red:hover{background:#b91c1c}
.vzc .b.sm{padding:3px 9px;font-size:12px;font-weight:500}
.vzc .b[disabled]{opacity:.5;cursor:wait}
.vzc .grid{display:grid;gap:12px;margin-top:12px}
.vzc .g4{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
.vzc .g2{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
.vzc .stat{background:#f9fafb;border:1px solid #eef0f3;border-radius:12px;padding:14px}
.vzc .k{color:#6b7280;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.vzc .v{font-size:20px;font-weight:700;margin-top:5px;color:#111827}
.vzc .v small{font-size:12px;color:#6b7280;font-weight:500}
.vzc .box{border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff}
.vzc h4{margin:0 0 10px;font-size:15px;font-weight:700;color:#111827}
.vzc .row{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid #f1f2f4;flex-wrap:wrap}
.vzc .row:last-child{border:0}
.vzc .row>span:first-child{color:#6b7280}
.vzc code{background:#f3f4f6;border:1px solid #e5e7eb;color:#111827;padding:2px 8px;border-radius:6px;font-family:ui-monospace,Menlo,monospace;font-size:13px;word-break:break-all}
.vzc .bar{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-top:10px}
.vzc .bar b{display:block;height:100%;background:#2563eb;border-radius:99px}
.vzc .bar.hot b{background:#ef4444}
.vzc input,.vzc select{width:100%;padding:9px 11px;border-radius:9px;border:1px solid #d1d5db;background:#fff;color:#111827;font-size:14px}
.vzc input:focus,.vzc select:focus{outline:2px solid #bfdbfe;border-color:#2563eb}
.vzc .toast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:100000;padding:11px 20px;border-radius:10px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18)}
.vzc .toast.ok{background:#16a34a;color:#fff}.vzc .toast.err{background:#dc2626;color:#fff}
.vzc .err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:11px 14px;border-radius:10px;margin-bottom:12px}
.vzc .modal{position:fixed;inset:0;background:rgba(17,24,39,.65);z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px}
.vzc .win{background:#fff;border-radius:14px;width:min(1100px,100%);height:min(720px,92vh);display:flex;flex-direction:column;overflow:hidden}
.vzc .win header{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #e5e7eb;gap:8px;flex-wrap:wrap}
.vzc .win iframe{flex:1;border:0;width:100%;background:#f3f4f6}
[x-cloak]{display:none!important}
</style>

<div class="vzc" x-data="{
    busy:null, toast:null, copied:null, show:false,
    csrf:@js(csrf_token()), base:@js($base),
    async call(action, body = {}, reload = true) {
        this.busy = action;
        try {
            const r = await fetch(this.base + '/' + action, {method:'POST', headers:{'X-CSRF-TOKEN':this.csrf,'Accept':'application/json','Content-Type':'application/json'}, body:JSON.stringify(body)});
            const j = await r.json();
            if (!j.ok) throw new Error(j.message || 'Error');
            if (j.message) { this.toast = {ok:true, t:j.message}; }
            if (reload && j.message) setTimeout(() => location.reload(), 1800);
            return j;
        } catch (e) { this.toast = {ok:false, t:e.message}; }
        finally { this.busy = null; setTimeout(() => this.toast = null, 5000); }
    },
    async console() { const w = window.open('', '_blank'); const j = await this.call('console', {}, false); if (j && j.url) { if (w) { w.location.href = j.url; } else { window.location.href = j.url; } } else if (w) { w.close(); } },
    async panel() { const j = await this.call('panel', {}, false); if (j && j.url) window.open(j.url, '_blank'); },
    copy(t, k) { navigator.clipboard.writeText(t); this.copied = k; setTimeout(() => this.copied = null, 1500); }
}">

    <template x-if="toast"><div class="toast" :class="toast.ok ? 'ok' : 'err'" x-text="toast.t"></div></template>

    @if($d['error'])<div class="err"><b>Could not load server information.</b> {{ $d['error'] }}</div>@endif

    <div class="top">
        <div>
            <div class="host">{{ $d['hostname'] ?? 'Server' }}</div>
            <div class="sub">
                {{ $d['plan'] ?? '' }}@if(!empty($d['os'])) · {{ $d['os'] }}@endif @if(!empty($d['node'])) · {{ $d['node'] }}@endif
            </div>
        </div>
        <span class="pill {{ $state }}"><i></i>{{ $stateLabel }}</span>
    </div>

    <div class="tools">
        <button type="button" class="b blue" x-on:click="console()" :disabled="busy">Console (VNC)</button>
        <button type="button" class="b green" x-on:click="call('start')" :disabled="busy">Start</button>
        <button type="button" class="b" x-on:click="confirm('Restart the server?') && call('restart')" :disabled="busy">Restart</button>
        <button type="button" class="b" x-on:click="confirm('Stop the server?') && call('stop')" :disabled="busy">Stop</button>
        <button type="button" class="b red" x-on:click="confirm('Force power off?') && call('poweroff')" :disabled="busy">Force off</button>
        <button type="button" class="b" x-on:click="panel()" :disabled="busy">Control panel</button>
    </div>

    <div class="grid g4" style="margin-top:0">
        <div class="stat"><div class="k">CPU</div><div class="v">{{ $d['cores'] ?? '—' }} <small>cores</small></div></div>
        <div class="stat"><div class="k">Memory</div><div class="v">{{ $fmt($d['ram'] ?? null) ?: '—' }} <small>MB</small></div></div>
        <div class="stat"><div class="k">Disk</div><div class="v">{{ $fmt($d['disk'] ?? null) ?: '—' }} <small>GB</small></div></div>
        <div class="stat"><div class="k">Network</div><div class="v">{{ $fmt($d['speed'] ?? null) ?: '—' }} <small>Mbps</small></div></div>
    </div>

    <div class="grid g2">
        <div class="box">
            <h4>Connection</h4>
            <div class="row"><span>IP address</span>
                <span>
                    @forelse($d['ips'] as $i)
                        <code>{{ $i }}</code> <button type="button" class="b sm" x-on:click="copy('{{ $i }}','ip{{ $loop->index }}')" x-text="copied==='ip{{ $loop->index }}' ? 'Copied' : 'Copy'"></button>
                    @empty — @endforelse
                </span>
            </div>
            <div class="row"><span>Username</span><code>root</code></div>
            <div class="row"><span>Password</span>
                <span>
                    @if($rootpass)
                        <code x-show="show" x-cloak>{{ $rootpass }}</code><code x-show="!show">••••••••••</code>
                        <button type="button" class="b sm" x-on:click="show=!show" x-text="show ? 'Hide' : 'Show'"></button>
                        <button type="button" class="b sm" x-on:click="copy(@js($rootpass),'pw')" x-text="copied==='pw' ? 'Copied' : 'Copy'"></button>
                    @else <span style="color:#9ca3af">Not stored</span> @endif
                </span>
            </div>
            @if($ip)
            <div class="row"><span>SSH</span>
                <span><code>ssh root@{{ $ip }}</code> <button type="button" class="b sm" x-on:click="copy('ssh root@{{ $ip }}','ssh')" x-text="copied==='ssh' ? 'Copied' : 'Copy'"></button></span>
            </div>
            @endif
        </div>

        <div class="box">
            <h4>Bandwidth</h4>
            @if($bwLimit > 0)
                <div class="row"><span>Used</span><b>{{ $fmt($bwUsed) }} / {{ $fmt($bwLimit) }} GB</b></div>
                <div class="bar {{ $bwPct > 85 ? 'hot' : '' }}"><b style="width:{{ $bwPct }}%"></b></div>
                <div class="sub" style="margin-top:8px">{{ $bwPct }}% of monthly traffic</div>
            @else
                <div class="row"><span>Traffic</span><b>Unlimited</b></div>
                @if($bwUsed > 0)<div class="row"><span>Used</span><b>{{ $fmt($bwUsed) }} GB</b></div>@endif
            @endif
        </div>
    </div>

    <div class="grid g2">
        <div class="box" x-data="{h:@js($d['hostname'] ?? '')}">
            <h4>Hostname</h4>
            <input type="text" x-model="h" placeholder="server.example.com">
            <button type="button" class="b blue" style="margin-top:10px" x-on:click="call('hostname',{hostname:h})" :disabled="busy">Save hostname</button>
        </div>
        <div class="box" x-data="{p:''}">
            <h4>Root password</h4>
            <input type="text" x-model="p" placeholder="New password (empty = random)">
            <button type="button" class="b blue" style="margin-top:10px" x-on:click="confirm('Change the root password?') && call('password',{password:p})" :disabled="busy">Change password</button>
        </div>
    </div>

    @if(!$d['error'] && !empty($oses))
    <div class="box" style="margin-top:12px" x-data="{os:'', p:''}">
        <h4>Reinstall operating system</h4>
        <div class="sub" style="margin-bottom:10px">All data on the server will be erased.</div>
        <div class="grid g2" style="margin-top:0">
            <select x-model="os">
                <option value="">Select an OS…</option>
                @foreach($oses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <input type="text" x-model="p" minlength="8" placeholder="New root password (min 8 chars)">
        </div>
        <button type="button" class="b red" style="margin-top:10px" x-on:click="os && p.length >= 8 ? (confirm('All data will be erased. Continue?') && call('rebuild',{os_id:os,password:p})) : (toast={ok:false,t:'Select an OS and enter a password (min 8 chars)'})" :disabled="busy">Reinstall</button>
    </div>
    @endif

</div>
