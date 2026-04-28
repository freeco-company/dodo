<?php

namespace App\Providers\Filament;

use App\Filament\Pages\FunnelDashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Admin panel for 朵朵 Dodo.
 *
 * Mirrors the conventions in the mothership (pandora.js-store) admin so the
 * group looks/feels consistent across products:
 *   - same primary brand color (#9F6B3E gold-brown)
 *   - same semantic colors (Stone gray, Sky info, Emerald success, Amber warning)
 *   - light-mode only (group brand)
 *   - SPA + global search + sidebar collapsible (productivity wins the team
 *     already relies on in pandora.js-store)
 *   - Chinese navigation groups
 *   - AccountWidget dropped — the "Welcome / Sign out" card is dead space when
 *     there's only one admin (signed-out lives in the user menu)
 *
 * @todo Vite-built custom Filament theme (mothership ships filament/custom.css).
 *       Skipped on this PR — porting the build pipeline + tailwind tokens is
 *       a >1hr standalone task, tracked in the PR description's TODO list.
 *       Hex primary color above is enough to look on-brand for now.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')

            // ── Branding ──
            ->brandName('朵朵 Dodo Admin')

            // ── Login ──
            ->login()

            // ── Colors: aligned with mothership gold-brown brand ──
            ->colors([
                'primary' => Color::hex('#9F6B3E'),
                'danger' => Color::Red,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'gray' => Color::Stone,
            ])

            // ── Light mode only — keep parity with mothership ──
            ->darkMode(false)

            // ── Navigation groups (Chinese, dodo-specific) ──
            // Mothership uses 訂單/商品/行銷/內容/系統; dodo's nouns differ
            // (no orders/products), but the *style* (Chinese, top-down by
            // operational priority) is intentionally identical.
            ->navigationGroups([
                '使用者',
                '飲食紀錄',
                '食物資料',
                '漏斗',
                '系統',
            ])

            // ── Typography ──
            ->font('Noto Sans TC')

            // ── Productivity defaults (mothership parity) ──
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->spa()
            ->globalSearch()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])

            // ── Resources & Pages ──
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
                FunnelDashboard::class,
            ])

            // ── Widgets ──
            // No AccountWidget / FilamentInfoWidget — same call as mothership.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])

            // ── Middleware ──
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
