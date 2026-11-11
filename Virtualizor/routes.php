<?php

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

if (!function_exists('vzExt')) {
    function vzExt(Service $service)
    {
        $server = $service->product->server;
        abort_unless($server && $server->extension === 'Virtualizor', 404);

        return ExtensionHelper::getExtension('server', $server->extension, $server->settings);
    }

    function vzService(Request $request, int $id, bool $admin): Service
    {
        $user = $request->user();
        if ($admin) {
            abort_unless($user && !is_null($user->role_id), 403);

            return Service::findOrFail($id);
        }

        return Service::where('id', $id)->where('user_id', $user->id)->where('status', Service::STATUS_ACTIVE)->firstOrFail();
    }

    function vzProp(Service $s, string $key): ?string
    {
        return $s->properties()->where('key', $key)->first()?->value;
    }

    function vzSet(Service $s, string $key, $val): void
    {
        $s->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => $val]);
    }

    /** Runs an action; returns a JSON result. */
    function vzHandle(Request $request, Service $service, string $action, bool $admin)
    {
        $ext = vzExt($service);
        try {
            $msg = 'Done';
            switch ($action) {
                case 'start': case 'stop': case 'restart': case 'poweroff':
                    $ext->clientAction($service, $action);
                    $msg = ucfirst($action) . ' command sent';
                    break;
                case 'password':
                    $pw = $request->input('password') ?: Str::password(16, symbols: false);
                    abort_if(strlen($pw) < 8, 422, 'Password must be at least 8 characters');
                    $ext->clientAction($service, 'password', ['password' => $pw]);
                    vzSet($service, 'rootpass', $pw);
                    $msg = 'Root password changed';
                    break;
                case 'hostname':
                    $h = $request->validate(['hostname' => ['required', 'regex:/^[A-Za-z0-9.\-]{1,100}$/']])['hostname'];
                    $ext->clientAction($service, 'hostname', ['hostname' => $h]);
                    vzSet($service, 'hostname', $h);
                    $msg = 'Hostname changed';
                    break;
                case 'rebuild':
                    $d = $request->validate(['os_id' => ['required', 'integer'], 'password' => ['required', 'string', 'min:8', 'max:64']]);
                    $ext->clientAction($service, 'rebuild', $d);
                    vzSet($service, 'rootpass', $d['password']);
                    $msg = 'Reinstall started';
                    break;
                case 'console':
                    return response()->json(['ok' => true, 'url' => $ext->consoleUrl($service)]);
                case 'panel':
                    return response()->json(['ok' => true, 'url' => $ext->panelUrl($service)]);
                case 'info':
                    $d = $ext->liveInfo($service);
                    $d['rootpass'] = vzProp($service, 'rootpass');
                    $d['linked'] = !empty(vzProp($service, 'vpsid') ?: vzProp($service, 'server_id'));
                    $d['oses'] = $d['error'] ? [] : $ext->osList($service, $d['virt']);
                    unset($d['raw']);

                    return response()->json(['ok' => true, 'data' => $d, 'status' => $service->status]);
                default:
                    abort_unless($admin, 403);
                    switch ($action) {
                        case 'suspend':
                            ExtensionHelper::suspendServer($service);
                            $service->update(['status' => Service::STATUS_SUSPENDED]);
                            $msg = 'Server suspended';
                            break;
                        case 'unsuspend':
                            ExtensionHelper::unsuspendServer($service);
                            $service->update(['status' => Service::STATUS_ACTIVE]);
                            $msg = 'Server unsuspended';
                            break;
                        case 'terminate':
                            ExtensionHelper::terminateServer($service);
                            $service->properties()->whereIn('key', ['vpsid', 'server_id'])->delete();
                            $msg = 'Server terminated';
                            break;
                        case 'create':
                            ExtensionHelper::createServer($service);
                            $msg = 'Server created';
                            break;
                        case 'link':
                            $id = (int) $request->validate(['vpsid' => ['required', 'integer', 'min:1']])['vpsid'];
                            $ext->adminLink($service, $id);
                            $msg = 'VPS ID saved';
                            break;
                        case 'autolink':
                            $id = $ext->adminAutoLink($service);
                            abort_unless($id, 422, 'No unique VPS found. Set the VPS ID manually.');
                            $msg = 'Linked to VPS #' . $id;
                            break;
                        case 'plan':
                            $ext->clientAction($service, 'plan', ['plid' => (int) $request->validate(['plid' => ['required', 'integer']])['plid']]);
                            $msg = 'Plan changed';
                            break;
                        default:
                            abort(404);
                    }
            }

            return response()->json(['ok' => true, 'message' => $msg]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage() ?: 'Not allowed'], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

Route::middleware(['web', 'auth'])->group(function () {
    Route::match(['get', 'post'], '/extensions/virtualizor/{service}/do/{action}', function (Request $request, int $service, string $action) {
        abort_unless(in_array($action, ['start', 'stop', 'restart', 'poweroff', 'password', 'hostname', 'rebuild', 'console', 'panel', 'info']), 404);

        return vzHandle($request, vzService($request, $service, false), $action, false);
    })->name('virtualizor.do');

    Route::match(['get', 'post'], '/extensions/virtualizor/admin/{service}/do/{action}', function (Request $request, int $service, string $action) {
        return vzHandle($request, vzService($request, $service, true), $action, true);
    })->name('virtualizor.admin.do');
});
