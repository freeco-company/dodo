<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RitualEventResource;
use App\Models\Meal;
use App\Models\MonthlyCollage;
use App\Models\ProgressSnapshot;
use App\Models\RitualEvent;
use App\Services\Ritual\RitualDispatcher;
use App\Services\Ritual\ShareCardRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * SPEC-progress-ritual-v1 PR #1 — ritual event read/share + share card endpoints.
 *
 *   GET    /api/rituals/unread          — frontend home banner / chat surface
 *   POST   /api/rituals/{e}/seen        — fullscreen displayed
 *   POST   /api/rituals/{e}/share       — generate + return share card url
 *   POST   /api/progress/compare/share-card — slider mode share card
 */
class RitualController extends Controller
{
    public function __construct(
        private readonly RitualDispatcher $dispatcher,
        private readonly ShareCardRenderer $renderer,
    ) {}

    public function unread(Request $request): AnonymousResourceCollection
    {
        $events = RitualEvent::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('seen_at')
            ->orderByDesc('triggered_at')
            ->limit(5)
            ->get();

        return RitualEventResource::collection($events);
    }

    public function seen(Request $request, RitualEvent $event): JsonResponse
    {
        $this->guard($request, $event);
        $this->dispatcher->markSeen($event);

        return response()->json(['ok' => true]);
    }

    public function share(Request $request, RitualEvent $event): JsonResponse
    {
        $this->guard($request, $event);
        $card = $this->renderer->render(
            $request->user(),
            'ritual_event',
            $event->id,
            ['ritual_key' => $event->ritual_key, 'payload' => $event->payload],
        );
        $this->dispatcher->markShared($event);

        return response()->json([
            'image_path' => $card->image_path,
            'image_url' => '/storage/'.$card->image_path,
        ]);
    }

    /** SPEC §3.1 — slider compare two photos → share card (Monthly+ tier). */
    public function compareShareCard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'snapshot_id_a' => ['required', 'integer'],
            'snapshot_id_b' => ['required', 'integer'],
        ]);

        $a = ProgressSnapshot::find($data['snapshot_id_a']);
        $b = ProgressSnapshot::find($data['snapshot_id_b']);
        if ($a === null || $b === null
            || $a->user_id !== $request->user()->id
            || $b->user_id !== $request->user()->id) {
            throw new AuthorizationException('snapshot not found or not yours');
        }

        $card = $this->renderer->render(
            $request->user(),
            'photo_compare',
            min($a->id, $b->id) * 1000000 + max($a->id, $b->id),
            ['a' => $a->id, 'b' => $b->id, 'days' => abs($b->taken_at->diffInDays($a->taken_at))],
        );

        return response()->json([
            'image_path' => $card->image_path,
            'image_url' => '/storage/'.$card->image_path,
        ]);
    }

    /** GET /api/collages — list user's collages (paginated lite). */
    public function collagesIndex(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:24']]);
        $rows = MonthlyCollage::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('month_start')
            ->limit($request->integer('limit', 12))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (MonthlyCollage $c) => [
                'id' => $c->id,
                'month_start' => $c->month_start->toDateString(),
                'snapshot_count' => count($c->snapshot_ids),
                'shared_count' => $c->shared_count,
            ])->all(),
        ]);
    }

    /** GET /api/collages/{collage} — full detail for the collage modal. */
    public function collageShow(Request $request, MonthlyCollage $collage): JsonResponse
    {
        if ($collage->user_id !== $request->user()->id) {
            throw new AuthorizationException('cross-tenant');
        }

        return response()->json([
            'id' => $collage->id,
            'month_start' => $collage->month_start->toDateString(),
            'snapshot_ids' => $collage->snapshot_ids,
            'stats' => $collage->stats_payload,
            'narrative_letter' => $collage->narrative_letter,
            'image_path' => $collage->image_path,
            'image_url' => $collage->image_path ? '/storage/'.$collage->image_path : null,
            'shared_count' => $collage->shared_count,
        ]);
    }

    /** POST /api/collages/{collage}/share — render share card + bump count. */
    public function collageShare(Request $request, MonthlyCollage $collage): JsonResponse
    {
        if ($collage->user_id !== $request->user()->id) {
            throw new AuthorizationException('cross-tenant');
        }

        $card = $this->renderer->render(
            $request->user(),
            'monthly_collage',
            $collage->id,
            ['month_start' => $collage->month_start->toDateString(), 'snapshot_ids' => $collage->snapshot_ids],
        );

        $collage->increment('shared_count');

        return response()->json([
            'image_path' => $card->image_path,
            'image_url' => '/storage/'.$card->image_path,
            'shared_count' => $collage->fresh()->shared_count,
        ]);
    }

    private function guard(Request $request, RitualEvent $event): void
    {
        if ($event->user_id !== $request->user()->id) {
            throw new AuthorizationException('cross-tenant');
        }
    }
}
