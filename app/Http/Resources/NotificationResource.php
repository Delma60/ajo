<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            'id'        => $this->id,
            'type'      => $this->data['type'] ?? class_basename($this->type),
            'title'     => $this->data['title'] ?? '',
            'body'      => $this->data['body'] ?? '',
            'data'      => $this->data,

            'readAt'    => $this->read_at ? $this->read_at->toISOString() : null,
            'read'    => $this->read_at ? true : false,
            'createdAt' => $this->created_at ? $this->created_at->toISOString() : null,
        ];
    }
}
