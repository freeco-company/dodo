<?php

namespace App\Http\Resources;

use App\Models\Insight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Insight
 */
class InsightResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'insight_key' => $this->insight_key,
            'narrative' => [
                'headline' => $this->narrative_headline,
                'body' => $this->narrative_body,
            ],
            'actions' => $this->action_suggestion ?? [],
            'detection' => $this->detection_payload ?? [],
            'source' => $this->source,
            'fired_at' => $this->fired_at->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
        ];
    }
}
