<?php

namespace App\Filament\Resources\FranchiseLeads\Tables;

use App\Models\DodoUser;
use App\Models\FranchiseLead;
use Filament\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            ]);
    }
}
