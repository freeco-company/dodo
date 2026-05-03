<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SPEC-progress-ritual-v1 — share card cache (PNG path + content checksum).
 *
 * @property int $id
 * @property int $user_id
 * @property string $source_type
 * @property int $source_id
 * @property string $image_path
 * @property string $checksum
 */
class ShareCardRender extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'source_type', 'source_id', 'image_path', 'checksum'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
