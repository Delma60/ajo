<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    //
    protected $fillable = [
        'path',
        'alt',
        'imageable_id',
        'imageable_type',
        'disk',             // Added
        'filename',         // Added
        'mime',             // Added
        'size',             // Added
        'thumbnail_path',   // Added
        'tag',
    ];

    public function imageable()
    {
        return $this->morphTo();
    }


    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'url' => url("/storage/{$this->path}"),
            'tag' => $this->tag,
        ];
    }

}
