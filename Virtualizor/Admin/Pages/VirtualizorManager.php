<?php

namespace Paymenter\Extensions\Servers\Virtualizor\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class VirtualizorManager extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-server-line';

    protected static ?string $navigationLabel = 'Virtualizor VPS';

    protected static string|\UnitEnum|null $navigationGroup = 'Extensions';

    protected static ?string $title = 'Virtualizor VPS Manager';

    protected static ?string $slug = 'virtualizor-vps';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** Extension instance for the server this service uses. */
    protected static function ext(Service $service)
    {
        $server = $service->product->server;
        if (!$server) {
            throw new \Exception('No server assigned to this product');
        }

        return ExtensionHelper::getExtension('server', $server->extension, $server->settings);
    }

    protected static function vpsId(Service $service): ?string
    {
        return $service->properties->whereIn('key', ['vpsid', 'server_id'])->first()?->value;
    }

    /** Run something, show a success or error notification. */
    protected static function run(Service $record, callable $fn, string $ok): void
    {
        try {
            $fn(static::ext($record));
            Notification::make()->title($ok)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    protected static function power(string $name, string $label, string $icon, string $action, bool $confirm = false): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation($confirm)
            ->visible(fn (Service $record) => (bool) static::vpsId($record))
            ->action(fn (Service $record) => static::run($record, fn ($e) => $e->clientAction($record, $action), $label . ' sent'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Service::query()->whereHas('product.server', fn (Builder $q) => $q->where('extension', 'Virtualizor'))
                    ->with(['product', 'user', 'properties'])
            )
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.email')->label('Customer')->searchable(),
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('vpsid')->label('VPS ID')
                    ->getStateUsing(fn (Service $record) => static::vpsId($record) ?: 'Not linked')
                    ->badge()
                    ->color(fn ($state) => $state === 'Not linked' ? 'danger' : 'success'),
                TextColumn::make('hostname')->label('Hostname')
                    ->getStateUsing(fn (Service $record) => $record->properties->where('key', 'hostname')->first()?->value),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'suspended' => 'warning', 'cancelled' => 'danger', default => 'gray'
                    })->sortable(),
                TextColumn::make('expires_at')->label('Expires')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active', 'suspended' => 'Suspended', 'pending' => 'Pending', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                Action::make('info')
                    ->label('Details')
                    ->icon('ri-information-line')
                    ->visible(fn (Service $record) => (bool) static::vpsId($record))
                    ->modalHeading(fn (Service $record) => 'Server #' . static::vpsId($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalDescription(function (Service $record) {
                        try {
                            $d = static::ext($record)->liveInfo($record);
                        } catch (\Throwable $e) {
                            return new HtmlString('<b style="color:#ef4444">' . e($e->getMessage()) . '</b>');
                        }
                        if ($d['error']) {
                            return new HtmlString('<b style="color:#ef4444">' . e($d['error']) . '</b>');
                        }
                        $state = $d['suspended'] ? 'Suspended' : ($d['running'] ? 'Online' : 'Offline');
                        $rows = [
                            'Status' => $state,
                            'Hostname' => $d['hostname'] ?? '-',
                            'IPs' => implode(', ', $d['ips']) ?: '-',
                            'Plan' => $d['plan'] ?? '-',
                            'OS' => $d['os'] ?? '-',
                            'Node' => $d['node'] ?? '-',
                            'Virtualization' => $d['virt'] ?? '-',
                            'RAM (MB)' => $d['ram'] ?? '-',
                            'Disk (GB)' => $d['disk'] ?? '-',
                            'CPU cores' => $d['cores'] ?? '-',
                            'Bandwidth (GB)' => ($d['used_bandwidth'] ?? 0) . ' / ' . (($d['bandwidth'] ?? 0) ?: 'unlimited'),
                        ];
                        $html = '<table style="width:100%;font-size:14px">';
                        foreach ($rows as $k => $v) {
                            $html .= '<tr><td style="padding:4px 8px;opacity:.7">' . e($k) . '</td><td style="padding:4px 8px"><b>' . e((string) $v) . '</b></td></tr>';
                        }

                        return new HtmlString($html . '</table>');
                    }),

                ActionGroup::make([
                    static::power('start', 'Start', 'ri-play-line', 'start'),
                    static::power('restart', 'Restart', 'ri-restart-line', 'restart', true),
                    static::power('stop', 'Stop', 'ri-stop-line', 'stop', true),
                    static::power('poweroff', 'Force power off', 'ri-shut-down-line', 'poweroff', true),
                ])->label('Power')->icon('ri-flashlight-line')->button(),

                ActionGroup::make([
                    Action::make('suspend')
                        ->label('Suspend')->icon('ri-pause-circle-line')->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->action(function (Service $record) {
                            static::run($record, function () use ($record) {
                                ExtensionHelper::suspendServer($record);
                                $record->update(['status' => Service::STATUS_SUSPENDED]);
                            }, 'Server suspended');
                        }),
                    Action::make('unsuspend')
                        ->label('Unsuspend')->icon('ri-play-circle-line')->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->action(function (Service $record) {
                            static::run($record, function () use ($record) {
                                ExtensionHelper::unsuspendServer($record);
                                $record->update(['status' => Service::STATUS_ACTIVE]);
                            }, 'Server unsuspended');
                        }),
                    Action::make('changePlan')
                        ->label('Change plan')->icon('ri-arrow-up-circle-line')
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->schema([TextInput::make('plid')->label('Virtualizor Plan ID')->numeric()->required()])
                        ->action(fn (array $data, Service $record) => static::run($record, fn ($e) => $e->clientAction($record, 'plan', $data), 'Plan changed')),
                    Action::make('hostname')
                        ->label('Change hostname')->icon('ri-edit-line')
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->schema([TextInput::make('hostname')->required()])
                        ->action(function (array $data, Service $record) {
                            static::run($record, function ($e) use ($record, $data) {
                                $e->clientAction($record, 'hostname', $data);
                                $record->properties()->updateOrCreate(['key' => 'hostname'], ['name' => 'hostname', 'value' => $data['hostname']]);
                            }, 'Hostname changed');
                        }),
                    Action::make('password')
                        ->label('Change root password')->icon('ri-key-2-line')
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->schema([TextInput::make('password')->label('New password')->minLength(8)->required()->default(fn () => Str::password(16, symbols: false))])
                        ->action(function (array $data, Service $record) {
                            static::run($record, function ($e) use ($record, $data) {
                                $e->clientAction($record, 'password', $data);
                                $record->properties()->updateOrCreate(['key' => 'rootpass'], ['name' => 'rootpass', 'value' => $data['password']]);
                            }, 'Password changed');
                        }),
                    Action::make('rebuild')
                        ->label('Reinstall OS')->icon('ri-install-line')->color('danger')
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->requiresConfirmation()
                        ->modalDescription('All data on the server will be erased.')
                        ->schema(fn (Service $record) => [
                            Select::make('os_id')->label('Operating system')->required()->searchable()
                                ->options(function () use ($record) {
                                    try {
                                        return static::ext($record)->osList($record);
                                    } catch (\Throwable $e) {
                                        return [];
                                    }
                                }),
                            TextInput::make('password')->label('Root password')->minLength(8)->required()->default(fn () => Str::password(16, symbols: false)),
                        ])
                        ->action(function (array $data, Service $record) {
                            static::run($record, function ($e) use ($record, $data) {
                                $e->clientAction($record, 'rebuild', $data);
                                $record->properties()->updateOrCreate(['key' => 'rootpass'], ['name' => 'rootpass', 'value' => $data['password']]);
                            }, 'Reinstall started');
                        }),
                ])->label('Manage')->icon('ri-settings-3-line')->button(),

                ActionGroup::make([
                    Action::make('console')
                        ->label('VNC console')->icon('ri-terminal-box-line')
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->action(function (Service $record) {
                            static::linkNotification($record, 'consoleUrl', 'VNC console');
                        }),
                    Action::make('panel')
                        ->label('Open end-user panel')->icon('ri-external-link-line')
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->action(function (Service $record) {
                            static::linkNotification($record, 'panelUrl', 'End-user panel');
                        }),
                ])->label('Access')->icon('ri-computer-line')->button(),

                ActionGroup::make([
                    Action::make('link')
                        ->label('Set VPS ID')->icon('ri-links-line')
                        ->schema(fn (Service $record) => [
                            TextInput::make('vpsid')->label('Virtualizor VPS ID')->numeric()->required()->default(static::vpsId($record)),
                        ])
                        ->action(function (array $data, Service $record) {
                            static::run($record, fn ($e) => $e->adminLink($record, (int) $data['vpsid']), 'VPS ID saved');
                        }),
                    Action::make('autolink')
                        ->label('Find VPS automatically')->icon('ri-search-line')
                        ->action(function (Service $record) {
                            try {
                                $id = static::ext($record)->adminAutoLink($record);
                                $id
                                    ? Notification::make()->title('Linked to VPS #' . $id)->success()->send()
                                    : Notification::make()->title('No unique VPS found')->body('Use "Set VPS ID" to link it manually.')->warning()->send();
                            } catch (\Throwable $e) {
                                Notification::make()->title('Failed')->body($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('create')
                        ->label('Create VPS')->icon('ri-add-circle-line')->color('success')
                        ->visible(fn (Service $record) => !static::vpsId($record))
                        ->requiresConfirmation()
                        ->modalDescription('Provisions a NEW VPS on Virtualizor for this service.')
                        ->action(function (Service $record) {
                            static::run($record, fn () => ExtensionHelper::createServer($record), 'VPS created');
                        }),
                    Action::make('terminate')
                        ->label('Terminate (delete VPS)')->icon('ri-delete-bin-line')->color('danger')
                        ->visible(fn (Service $record) => (bool) static::vpsId($record))
                        ->requiresConfirmation()
                        ->modalDescription('The VPS and all its data will be permanently deleted from Virtualizor.')
                        ->action(function (Service $record) {
                            static::run($record, function () use ($record) {
                                ExtensionHelper::terminateServer($record);
                                $record->properties()->whereIn('key', ['vpsid', 'server_id'])->delete();
                            }, 'Server terminated');
                        }),
                ])->label('Admin')->icon('ri-shield-user-line')->button(),
            ]);
    }

    protected static function linkNotification(Service $record, string $method, string $title): void
    {
        try {
            $url = static::ext($record)->$method($record);
            Notification::make()
                ->title($title . ' is ready')
                ->body(new HtmlString('<a href="' . e($url) . '" target="_blank" rel="noopener" style="text-decoration:underline;font-weight:600">Click here to open</a>'))
                ->success()->persistent()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Failed')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
