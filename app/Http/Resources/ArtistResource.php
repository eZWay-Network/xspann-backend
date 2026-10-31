<?php

namespace App\Http\Resources;

use App\Services\{ArtistCapabilities, StorageService};
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class ArtistResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        $capabilities = app(ArtistCapabilities::class)->forArtist($this->resource);
        $owner = (bool) $this->owner_view;
        $links = $this->whenLoaded('links', fn($links) => $links->all(), []);
        $featured = $this->whenLoaded('featuredVideo', default: null);
        $user = $this->whenLoaded('user', default: null);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'stage_name' => $this->stage_name,
            'slug' => $this->slug,
            'bio' => $this->bio,
            'location' => $this->location,
            'image_url' => StorageService::publicUrl($this->image ?: $user?->avatar),
            'listed' => $this->listed,
            'genres' => $this->whenLoaded('genres', fn($genres) => $genres->map(fn($genre) => [
                'id' => $genre->id, 'name' => $genre->name, 'slug' => $genre->slug,
            ])->values()),
            'links' => array_map(fn($link) => ['label' => $link->label, 'type' => $link->type, 'url' => $link->url], $owner ? $links : array_slice($links, 0, $capabilities['links'])),
            'featured_release' => $capabilities['featured_release'] && $featured && $featured->user_id === $this->user_id
                ? VideoResource::make($featured) : null,
            'presentation' => [
                'enhanced_profile' => $capabilities['enhanced_profile'],
                'enhanced_directory_card' => $capabilities['enhanced_directory_card'],
            ],
            'followers_count' => (int) $user?->followers_count,
            'announcement_subscribers_count' => 0,
            'viewer' => [
                'following' => (bool) $user?->viewer_following,
                'updates_subscribed' => false,
                'update_subscriptions_available' => false,
            ],
            ...($owner ? [
                'image' => $this->image,
                'featured_video_id' => $this->featured_video_id,
                'moderation_status' => $this->moderation_status,
                'capabilities' => $capabilities,
                'data_since' => $this->data_since,
            ] : []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
