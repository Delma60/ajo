<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemAlertResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,        // info | warning | critical | success
            'category'    => $this->category,    // payment | user | group | system | security
            'title'       => $this->title,
            'body'        => $this->body,
            'meta'        => $this->meta,
            'is_read'     => (bool) $this->is_read,
            'resolved'    => $this->isResolved(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolved_by' => $this->resolved_by ? [
                'id'   => $this->resolver?->id,
                'name' => $this->resolver?->name,
            ] : null,
            'created_at'  => $this->created_at?->toISOString(),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
