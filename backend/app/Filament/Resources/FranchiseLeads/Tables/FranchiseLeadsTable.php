<?php

namespace App\Filament\Resources\FranchiseLeads\Tables;

use App\Models\DodoUser;
use App\Models\FranchiseLead;
use App\Models\LifecycleOverrideLog;
use App\Services\Conversion\LifecycleAdminClient;
use App\Services\Conversion\LifecycleClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FranchiseLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // 預設隱藏 silenced 列：避免 BD 視覺上把 opt-out 的人當聯繫對象
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('status', '!=', FranchiseLead::STATUS_SILENCED))
            ->columns([
                TextColumn::make('pandora_user_uuid')
                    ->label('UUID')
                    ->searchable()
                    ->limit(8)
                    ->tooltip(fn (FranchiseLead $r) => $r->pandora_user_uuid),
                TextColumn::make('display_name')
                    ->label('暱稱')
                    ->state(function (FranchiseLead $r): string {
                        // 從 dodo_users mirror 撈 display_name；找不到顯示 '—'
                        return DodoUser::query()
                            ->whereKey($r->pandora_user_uuid)
                            ->value('display_name') ?? '—';
                    }),
                TextColumn::make('source_app')
                    ->label('來源 App')
                    ->badge(),
                TextColumn::make('trigger_event')
                    ->label('觸發事件')
                    ->searchable(),
                BadgeColumn::make('status')
                    ->label('狀態')
                    ->colors([
                        'gray' => FranchiseLead::STATUS_NEW,
                        'warning' => FranchiseLead::STATUS_CONTACTING,
                        'info' => FranchiseLead::STATUS_CONTACTED,
                        'success' => FranchiseLead::STATUS_CONVERTED,
                        'danger' => FranchiseLead::STATUS_DISMISSED,
                        'secondary' => FranchiseLead::STATUS_SILENCED,
                    ]),
                TextColumn::make('assigned_to')
                    ->label('負責業務')
                    ->placeholder('—'),
                TextColumn::make('contacted_at')
                    ->label('已聯繫時間')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('未聯繫'),
                TextColumn::make('created_at')
                    ->label('進 inbox 時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(array_combine(FranchiseLead::STATUSES, FranchiseLead::STATUSES)),
                SelectFilter::make('source_app')
                    ->label('來源 App')
                    ->options(['doudou' => 'doudou']),
                Filter::make('today')
                    ->label('今天')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', today())),
                Filter::make('last_7_days')
                    ->label('最近 7 天')
                    ->query(fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(7))),
                Filter::make('last_30_days')
                    ->label('最近 30 天')
                    ->query(fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(30))),
            ])
            ->recordActions([
                Action::make('mark_contacting')
                    ->label('標 contacting')
                    ->icon('heroicon-o-phone')
                    ->color('warning')
                    ->visible(fn (FranchiseLead $r) => $r->status === FranchiseLead::STATUS_NEW)
                    ->action(function (FranchiseLead $r): void {
                        $r->update(['status' => FranchiseLead::STATUS_CONTACTING]);
                    }),
                Action::make('mark_contacted')
                    ->label('標 contacted')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->visible(fn (FranchiseLead $r) => in_array(
                        $r->status,
                        [FranchiseLead::STATUS_NEW, FranchiseLead::STATUS_CONTACTING],
                        true,
                    ))
                    ->action(function (FranchiseLead $r): void {
                        $r->update([
                            'status' => FranchiseLead::STATUS_CONTACTED,
                            'contacted_at' => now(),
                        ]);
                    }),
                Action::make('mark_converted')
                    ->label('標 converted')
                    ->icon('heroicon-o-trophy')
                    ->color('success')
                    ->action(function (FranchiseLead $r): void {
                        $r->update(['status' => FranchiseLead::STATUS_CONVERTED]);
                    }),
                Action::make('mark_dismissed')
                    ->label('標 dismissed')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(function (FranchiseLead $r): void {
                        $r->update(['status' => FranchiseLead::STATUS_DISMISSED]);
                    }),
                // Admin override：覆寫 py-service lifecycle stage（任一 → 任一）。
                // ADR-008 §2.2 提供的後路：當婕樂纖首單 webhook 漏掉、或 BD 線下確認狀態
                // 與系統不符時可手動修正。每次操作寫 lifecycle_override_logs 留底，py-service
                // 端 force_transition 也會記 metadata（actor + reason）。
                Action::make('override_lifecycle')
                    ->label('覆寫 lifecycle 階段')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('gray')
                    ->modalHeading('覆寫 lifecycle 階段（謹慎使用）')
                    ->modalDescription('這個動作會直接改 py-service 上的 lifecycle stage，並寫進 audit log。請填寫具體理由，方便日後對帳。')
                    ->schema([
                        Select::make('to_status')
                            ->label('目標階段')
                            ->options(array_combine(LifecycleClient::stages(), LifecycleClient::stages()))
                            ->required(),
                        Textarea::make('reason')
                            ->label('理由（必填）')
                            ->rows(3)
                            ->required()
                            ->minLength(5)
                            ->maxLength(500),
                    ])
                    ->action(function (FranchiseLead $r, array $data): void {
                        /** @var LifecycleAdminClient $client */
                        $client = app(LifecycleAdminClient::class);
                        /** @var LifecycleClient $cache */
                        $cache = app(LifecycleClient::class);
                        $authedUser = Auth::user();
                        $actor = $authedUser !== null ? (string) $authedUser->email : 'unknown';
                        $fromStatus = $cache->getStatus($r->pandora_user_uuid);
                        $toStatus = (string) $data['to_status'];
                        $reason = (string) $data['reason'];

                        $logBase = [
                            'pandora_user_uuid' => $r->pandora_user_uuid,
                            'from_status' => $fromStatus,
                            'to_status' => $toStatus,
                            'reason' => $reason,
                            'actor_email' => $actor,
                            'created_at' => now(),
                        ];

                        try {
                            $client->override($r->pandora_user_uuid, $toStatus, $reason, $actor);
                            LifecycleOverrideLog::create($logBase + ['succeeded' => true]);
                            // py-service cache_invalidator (PG-93) 會 push webhook 過來自動清；
                            // 這裡再 forget 一次當作防禦 layer，使 admin 立刻看到新狀態。
                            $cache->forget($r->pandora_user_uuid);
                            Notification::make()
                                ->title('Lifecycle 階段已更新')
                                ->body($fromStatus.' → '.$toStatus)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            LifecycleOverrideLog::create($logBase + [
                                'succeeded' => false,
                                'error' => substr($e->getMessage(), 0, 500),
                            ]);
                            Log::error('[LifecycleOverride] failed', [
                                'uuid' => $r->pandora_user_uuid,
                                'to_status' => $toStatus,
                                'actor' => $actor,
                                'error' => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Lifecycle 階段更新失敗')
                                ->body(substr($e->getMessage(), 0, 200))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
