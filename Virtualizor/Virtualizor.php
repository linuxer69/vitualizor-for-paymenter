<?php

namespace Paymenter\Extensions\Servers\Virtualizor;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

#[ExtensionMeta(name: 'Virtualizor Pro', description: 'Virtualizor VPS management', version: '1.2.0', author: 'Hoosha')]
class Virtualizor extends Server
{
    public function boot()
    {
        try { require_once __DIR__ . '/routes.php'; } catch (\Throwable $e) {}
        try {
            \Filament\Support\Facades\FilamentView::registerRenderHook(
                \Filament\View\PanelsRenderHook::PAGE_START,
                fn () => view()->file(__DIR__ . '/views/admin.blade.php')->render(),
                scopes: \App\Admin\Resources\ServiceResource\Pages\EditService::class,
            );
        } catch (\Throwable $e) {}
    }

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'host', 'label' => 'Panel host (e.g. panel.example.com)', 'type' => 'text', 'required' => true],
            ['name' => 'port', 'label' => 'Admin port', 'type' => 'text', 'default' => '4085', 'required' => true],
            ['name' => 'api_key', 'label' => 'API key', 'type' => 'text', 'required' => true],
            ['name' => 'api_pass', 'label' => 'API password', 'type' => 'password', 'required' => true],
            ['name' => 'client_host', 'label' => 'End-user panel domain (optional, e.g. vps.example.com with valid SSL — needed for the in-site console)', 'type' => 'text'],
            ['name' => 'client_port', 'label' => 'End-user panel port (normally 4083)', 'type' => 'text', 'default' => '4083'],
            ['name' => 'ssl_verify', 'label' => 'Verify SSL', 'type' => 'checkbox', 'default' => true],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $r = $this->api('vs', ['reslen' => 1]);
            return (isset($r['vs']) || isset($r['title']) || isset($r['servers'])) ? true : 'Unexpected response: ' . substr(json_encode($r), 0, 200);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'plid', 'label' => 'Plan ID (Virtualizor)', 'type' => 'number', 'required' => true],
            ['name' => 'server_group', 'label' => 'Server group ID', 'type' => 'number', 'default' => 0],
            ['name' => 'node', 'label' => 'Node/Server ID (0 = auto)', 'type' => 'number', 'default' => 0],
            ['name' => 'os_id', 'label' => 'Default OS template ID', 'type' => 'number', 'required' => true],
            ['name' => 'ips', 'label' => 'Number of IPv4', 'type' => 'number', 'default' => 1],
            ['name' => 'user_plan', 'label' => 'User plan ID (optional)', 'type' => 'number', 'default' => 0],
            ['name' => 'hostname_suffix', 'label' => 'Hostname suffix', 'type' => 'text', 'default' => 'vps.example.com'],
        ];
    }

    public function getCheckoutConfig($product): array
    {
        return [
            ['name' => 'hostname', 'label' => 'Hostname', 'type' => 'text', 'required' => false],
            ['name' => 'os_id', 'label' => 'OS template ID (optional override)', 'type' => 'text', 'required' => false],
        ];
    }

    /** Low-level call to Virtualizor Admin API */
    protected function api(string $act, array $query = [], array $post = []): array
    {
        $url = 'https://' . $this->config('host') . ':' . ($this->config('port') ?: 4085) . '/index.php';
        $query = array_merge(['act' => $act, 'api' => 'json', 'adminapikey' => $this->config('api_key'), 'adminapipass' => $this->config('api_pass')], $query);
        $verify = filter_var($this->config('ssl_verify'), FILTER_VALIDATE_BOOLEAN);
        $http = Http::timeout(60)->withOptions(['verify' => $verify]);
        try {
            $res = $post ? $http->asForm()->post($url . '?' . http_build_query($query), $post) : $http->get($url, $query);
        } catch (\Throwable $e) {
            throw new \Exception(preg_replace('/(apikey|apipass)=[^&\s]+/', '$1=***', $e->getMessage()));
        }
        if (!$res->successful()) {
            throw new \Exception('Virtualizor HTTP error ' . $res->status());
        }
        $json = $res->json();
        if (!is_array($json)) {
            throw new \Exception('Invalid Virtualizor response: ' . substr(strip_tags((string) $res->body()), 0, 150));
        }
        if (!empty($json['error'])) {
            throw new \Exception(is_array($json['error']) ? implode(', ', $json['error']) : $json['error']);
        }
        return $json;
    }

    protected function vpsId(Service $service): int
    {
        $id = $service->properties->whereIn('key', ['vpsid', 'server_id'])->first()?->value;
        if (!$id) {
            $id = $this->autoLink($service);
        }
        if (!$id) {
            throw new \Exception('VPS is not linked to this service yet. Admin: add a service property named "vpsid" with the Virtualizor VPS ID.');
        }
        return (int) $id;
    }

    /** Try to link an existing VPS by hostname property or by the customer's email (only if exactly one match). */
    protected function autoLink(Service $service): ?int
    {
        try {
            $email = $service->order->user->email ?? null;
            $host = $service->properties->where('key', 'hostname')->first()?->value;
            $r = $this->api('vs', ['reslen' => 100] + ($host ? ['vpshostname' => $host] : ['user_email' => $email]));
            $list = $r['vs'] ?? [];
            if (!$host && $email) {
                $list = array_filter($list, fn ($v) => strcasecmp($v['email'] ?? '', $email) === 0);
            }
            if (count($list) === 1) {
                $v = reset($list);
                $id = (int) ($v['vpsid'] ?? key($list));
                $service->properties()->updateOrCreate(['key' => 'vpsid'], ['name' => 'vpsid', 'value' => $id]);
                $service->load('properties');
                return $id;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    public function createServer(Service $service, $settings, $properties)
    {
        $p = array_merge($settings, $properties);
        $user = $service->order->user;
        $rootpass = \Illuminate\Support\Str::password(16, symbols: false);
        $hostname = ($p['hostname'] ?? '') ?: ('vps' . $service->id . '.' . $p['hostname_suffix']);

        $post = [
            'addvps' => 1,
            'user_email' => $user->email,
            'user_pass' => \Illuminate\Support\Str::password(14, symbols: false),
            'fname' => $user->first_name ?? '',
            'lname' => $user->last_name ?? '',
            'hostname' => $hostname,
            'rootpass' => $rootpass,
            'os_id' => $p['os_id'],
            'plid' => $p['plid'],
            'server_group' => $p['server_group'] ?? 0,
            'node_select' => !empty($p['node']) ? 1 : 0,
            'num_ips' => $p['ips'] ?? 1,
            'user_plid' => $p['user_plan'] ?? 0,
        ];
        if (!empty($p['node'])) {
            $post['server'] = $p['node'];
        }
        $r = $this->api('addvs', [], $post);
        $vpsid = $r['vs_info']['vpsid'] ?? $r['vpsid'] ?? null;
        if (!$vpsid) {
            throw new \Exception('VPS creation failed: ' . json_encode($r['done'] ?? $r));
        }
        foreach (['server_id' => $vpsid, 'vpsid' => $vpsid, 'hostname' => $hostname, 'rootpass' => $rootpass] as $k => $v) {
            $service->properties()->updateOrCreate(['key' => $k], ['name' => $k, 'value' => $v]);
        }
        return ['vpsid' => $vpsid];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $this->api('vs', ['suspend' => $this->vpsId($service)]);
        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $this->api('vs', ['unsuspend' => $this->vpsId($service)]);
        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $this->api('vs', ['delete' => $this->vpsId($service)]);
        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $p = array_merge($settings, $properties);
        $this->api('managevps', ['vpsid' => $this->vpsId($service)], [
            'editvps' => 1, 'plid' => $p['plid'],
        ]);
        return true;
    }

    /* ------------------------------------------------------------------
     |  Shared helpers (used by client area and the admin page)
     * ------------------------------------------------------------------ */

    protected function clientBase(): string
    {
        return 'https://' . ($this->config('client_host') ?: $this->config('host')) . ':' . ($this->config('client_port') ?: 4083);
    }

    protected function prop(Service $service, string $key): ?string
    {
        return $service->properties->where('key', $key)->first()?->value;
    }

    protected function setProp(Service $service, string $key, $value): void
    {
        $service->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => $value]);
        $service->load('properties');
    }

    /** Create a one-time end-user session (SSO). Returns [token_key, sid]. */
    protected function sso(int $vpsid): array
    {
        $res = Http::withoutVerifying()->timeout(30)->get($this->clientBase() . '/index.php', [
            'api' => 'json', 'adminapikey' => $this->config('api_key'), 'adminapipass' => $this->config('api_pass'),
            'act' => 'sso', 'svs' => $vpsid,
        ]);
        $r = $res->json();
        if (!is_array($r) || !isset($r['sid'], $r['token_key'])) {
            throw new \Exception('SSO failed (check the end-user port and API permissions)');
        }
        return [$r['token_key'], $r['sid']];
    }

    /** Live, normalised information about a VPS. Never throws. */
    public function liveInfo(Service $service): array
    {
        $d = ['error' => null, 'vpsid' => null, 'running' => null, 'suspended' => false, 'ips' => [], 'virt' => null];
        try {
            $vid = $this->vpsId($service);
            $d['vpsid'] = $vid;
            $r = $this->api('vs', ['vpsid' => $vid]);
            $vs = $r['vs'] ?? [];
            $v = $vs[$vid] ?? ($vs ? reset($vs) : []);
            $d['raw'] = $v;
            $d['hostname'] = $v['hostname'] ?? $this->prop($service, 'hostname');
            $d['virt'] = $v['virt'] ?? null;
            $d['os'] = $v['os_name'] ?? null;
            $d['plan'] = $v['plan_name'] ?? null;
            $d['node'] = $v['server_name'] ?? null;
            $d['ram'] = $v['ram'] ?? null;
            $d['disk'] = $v['space'] ?? null;
            $d['cores'] = $v['cores'] ?? null;
            $d['bandwidth'] = $v['bandwidth'] ?? null;
            $d['used_bandwidth'] = $v['used_bandwidth'] ?? null;
            $d['speed'] = $v['network_speed'] ?? null;
            $d['suspended'] = !empty($v['suspended']);
            $ips = $v['ips'] ?? [];
            $d['ips'] = array_values(array_filter(is_array($ips) ? $ips : [$ips]));
            // Real power state comes from vs_status (the "status" flag in the list is not reliable)
            try {
                $st = $this->api('vs', ['vs_status' => $vid]);
                $row = $st['status'][$vid] ?? (isset($st['status']) && is_array($st['status']) ? reset($st['status']) : null);
                if (is_array($row)) {
                    $d['running'] = !empty($row['status']);
                    $d['usage'] = $row;
                } elseif (is_scalar($row)) {
                    $d['running'] = (bool) $row;
                }
            } catch (\Throwable $e) {
            }
            if ($d['running'] === null) {
                $d['running'] = !empty($v['status']);
            }
        } catch (\Throwable $e) {
            $d['error'] = $e->getMessage();
        }
        return $d;
    }

    /** OS templates for the VPS' virtualization type. */
    public function osList(Service $service, ?string $virt = null): array
    {
        try {
            $os = $this->api('os');
            $virt ??= $this->liveInfo($service)['virt'];
            $out = [];
            foreach (($os['oslist'][$virt] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                foreach ($group as $id => $o) {
                    if (is_array($o) && isset($o['name'])) {
                        $out[$id] = $o['name'];
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Power / password / rebuild. Used by buttons, routes and the admin page. */
    public function clientAction(Service $service, string $action, array $input = [])
    {
        $id = $this->vpsId($service);
        return match ($action) {
            'start', 'stop', 'restart', 'poweroff' => $this->api('vs', ['action' => $action, 'serid' => 0, 'vpsid' => $id]),
            'rebuild' => $this->api('rebuild', [], [
                'reos' => 1, 'vpsid' => $id, 'osid' => (int) $input['os_id'], 'os_id' => (int) $input['os_id'],
                'newpass' => $input['password'], 'conf' => $input['password'],
            ]),
            'password' => $this->api('managevps', ['vpsid' => $id], ['editvps' => 1, 'rootpass' => $input['password']]),
            'hostname' => $this->api('managevps', ['vpsid' => $id], ['editvps' => 1, 'hostname' => $input['hostname']]),
            'plan' => $this->api('managevps', ['vpsid' => $id], ['editvps' => 1, 'theme_edit' => 1, 'plid' => (int) $input['plid']]),
            default => throw new \Exception('Unknown action'),
        };
    }

    /** Admin: set the VPS id manually. */
    public function adminLink(Service $service, int $vpsid): void
    {
        $this->setProp($service, 'vpsid', $vpsid);
        $this->setProp($service, 'server_id', $vpsid);
    }

    /** Admin: find the VPS automatically. */
    public function adminAutoLink(Service $service): ?int
    {
        return $this->autoLink($service);
    }

    public function consoleUrl(Service $service): string
    {
        $id = $this->vpsId($service);
        [$token, $sid] = $this->sso($id);
        return $this->clientBase() . '/' . $token . '/?as=' . $sid . '&act=vnc&launch=1&giveapp=novnc&novnc=1&svs=' . $id;
    }

    public function panelUrl(Service $service): string
    {
        $id = $this->vpsId($service);
        [$token, $sid] = $this->sso($id);
        return $this->clientBase() . '/' . $token . '/?as=' . $sid . '&svs=' . $id;
    }

    /* ------------------------------------------------------------------
     |  Client area
     * ------------------------------------------------------------------ */

    public function getActions(Service $service, $settings, $properties)
    {
        return [
            ['name' => 'control', 'label' => 'VPS Control', 'type' => 'view', 'function' => 'getView'],
        ];
    }

    protected function power(Service $service, string $action): string
    {
        $this->clientAction($service, $action);
        return route('services.show', $service);
    }

    public function actConsole(Service $service, $settings = [], $properties = [])
    {
        return $this->consoleUrl($service);
    }

    public function actStart(Service $service, $settings = [], $properties = [])
    {
        return $this->power($service, 'start');
    }

    public function actRestart(Service $service, $settings = [], $properties = [])
    {
        return $this->power($service, 'restart');
    }

    public function actStop(Service $service, $settings = [], $properties = [])
    {
        return $this->power($service, 'stop');
    }

    public function actPoweroff(Service $service, $settings = [], $properties = [])
    {
        return $this->power($service, 'poweroff');
    }

    public function actResetPassword(Service $service, $settings = [], $properties = [])
    {
        $pass = Str::password(16, symbols: false);
        $this->clientAction($service, 'password', ['password' => $pass]);
        $this->setProp($service, 'rootpass', $pass);
        return route('services.show', $service);
    }

    public function ssoLink(Service $service, $settings = [], $properties = [])
    {
        return $this->panelUrl($service);
    }

    public function getView(Service $service, $settings, $properties, $view = null)
    {
        $d = $this->liveInfo($service);
        return view()->file(__DIR__ . '/views/control.blade.php', [
            'service' => $service,
            'd' => $d,
            'oses' => $d['error'] ? [] : $this->osList($service, $d['virt']),
            'rootpass' => $this->prop($service, 'rootpass'),
        ]);
    }
}
