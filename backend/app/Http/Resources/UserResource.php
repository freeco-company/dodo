<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,

            'avatar' => [
                'color' => $this->avatar_color,
                'species' => $this->avatar_species,
                'animal' => $this->avatar_animal,
                'equipped_outfit' => $this->equipped_outfit,
            ],

            'profile' => [
                'height_cm' => $this->height_cm,
                'current_weight_kg' => $this->current_weight_kg,
                'target_weight_kg' => $this->target_weight_kg,
                'birth_date' => $this->birth_date?->toDateString(),
                'gender' => $this->gender,
                'activity_level' => $this->activity_level,
                'dietary_type' => $this->dietary_type,
                'allergies' => $this->allergies ?? [],
                'dislike_foods' => $this->dislike_foods ?? [],
                'favorite_foods' => $this->favorite_foods ?? [],
            ],

            'targets' => [
                'daily_calorie_target' => $this->daily_calorie_target,
                'daily_protein_target_g' => $this->daily_protein_target_g,
                'daily_water_goal_ml' => $this->daily_water_goal_ml,
            ],

            'progress' => [
                'level' => $this->level,
                'xp' => $this->xp,
                'current_streak' => $this->current_streak,
                'longest_streak' => $this->longest_streak,
                'total_days' => $this->total_days,
                'friendship' => $this->friendship,
                'streak_shields' => $this->streak_shields,
            ],

            'subscription' => [
                'membership_tier' => $this->membership_tier,
                'subscription_type' => $this->subscription_type,
                'expires_at' => $this->subscription_expires_at_iso?->toIso8601String(),
            ],

            'journey' => [
                'cycle' => $this->journey_cycle,
                'day' => $this->journey_day,
                'last_advance_date' => $this->journey_last_advance_date?->toDateString(),
            ],

            'onboarded_at' => $this->onboarded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
