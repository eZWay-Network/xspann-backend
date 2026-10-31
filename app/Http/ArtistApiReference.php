<?php

namespace App\Http;

use App\Http\Requests\Artists\{PutArtistLinksRequest, PutArtistRequest, PutPlaylistItemsRequest};
use App\Http\Requests\Uploads\AvatarUploadRequest;

/** Step 2 contracts consumed by the rendered and exported API reference. */
class ArtistApiReference
{
    public static function catalog(): array
    {
        $result = [];
        $add = function ($action, $title, $description, $response, $status = '200', $rules = [], $example = [], $limit = null, $errors = []) use (&$result): void {
            $group = str_starts_with($action, 'ArtistPlaylist') ? 'Artist playlists' : 'Artists';
            $result[$action] = compact('group', 'title', 'description', 'response', 'status', 'rules', 'example', 'limit', 'errors');
        };
        $date = '2026-10-31T12:00:00.000000Z';
        $artist = [
            'id' => 1, 'user_id' => 1, 'stage_name' => 'Alex Band', 'slug' => 'alex-band',
            'bio' => 'Independent music', 'location' => null, 'image_url' => null, 'listed' => true,
            'genres' => [['id' => 1, 'name' => 'Alternative', 'slug' => 'alternative']],
            'links' => [['label' => 'Listen', 'type' => 'music', 'url' => 'https://example.com/music']],
            'featured_release' => null,
            'presentation' => ['enhanced_profile' => false, 'enhanced_directory_card' => false],
            'followers_count' => 0, 'announcement_subscribers_count' => 0,
            'viewer' => ['following' => false, 'updates_subscribed' => false, 'update_subscriptions_available' => false],
            'created_at' => $date, 'updated_at' => $date,
        ];
        $ownArtist = $artist + [
            'image' => null, 'featured_video_id' => null, 'moderation_status' => 'approved',
            'capabilities' => ['plan' => 'free', ...config('artists.plans.free')], 'data_since' => $date,
        ];
        $playlist = [
            'id' => 1, 'artist_id' => 1, 'title' => 'Debut', 'description' => null, 'genre_id' => 1,
            'category' => 'release', 'visibility' => 'public', 'videos_count' => 1,
            'cover_url' => null, 'created_at' => $date, 'updated_at' => $date,
        ];
        $ownPlaylist = $playlist + ['version' => 1, 'read_only' => false, 'moderation_status' => 'approved'];
        $ownDetail = $ownPlaylist + ['videos' => ['$Video'], 'items' => [[
            'video_id' => 1, 'position' => 0, 'status' => 'published', 'visibility' => 'public', 'moderation_status' => 'approved',
        ]]];
        $metadata = [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'genre_id' => ['sometimes', 'nullable', 'integer', 'min:1', ['exists' => ['artist_genres', 'id']]],
            'category' => ['sometimes', 'required', 'string', ['in' => config('artists.playlist_categories')]],
            'visibility' => ['sometimes', 'required', 'string', 'in:public,private'],
        ];
        $filter = [
            'genre_id' => ['sometimes', 'required', 'integer', 'min:1', ['exists' => ['artist_genres', 'id']]],
            'category' => ['sometimes', 'required', 'string', ['in' => config('artists.playlist_categories')]],
        ];
        $errors = ['403' => 'Account or capability/plan limit denied.', '404' => 'Artist/playlist absent, inaccessible, or owned by another account.'];
        $conflict = $errors + ['409' => 'Playlist version conflict; reload the owner detail and reconcile your edit.'];
        $public = 'Active, verified, onboarded accounts only; blocks in either direction hide the artist. Public resources contain no email or age evidence. ';

        $add('ArtistController@taxonomy', 'Artist taxonomy', 'Seeded genre IDs, playlist categories and supported link types. Genre IDs are shared by profiles and playlists.', [
            'genres' => $artist['genres'], 'playlist_categories' => config('artists.playlist_categories'), 'link_types' => config('artists.link_types'),
        ]);
        $add('ArtistController@index', 'Browse artists', $public . 'Organic listed artists. q searches stage name/slug, with literal SQL wildcards. Filter genre_id; sort=latest (default) or popular by existing follower totals, then creation time and ID descending. No paid ranking or featured-artist placement.', $artist, '200', [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'], 'genre_id' => $filter['genre_id'], 'sort' => ['sometimes', 'required', 'string', 'in:latest,popular'],
        ], [], 20);
        $add('ArtistController@show', 'Read an artist', $public . 'listed=false removes organic directory discovery but the known profile remains accessible. Public links and featured_release use current capabilities. featured_release is an owned public video, not an operator placement or identity verification. Consent is unavailable until Step 4.', $artist);
        $add('ArtistController@bySlug', 'Resolve an artist slug', $public . 'Lowercase ASCII slug resolves the same resource/ID. Slugs persist across name edits unless explicitly changed; old slugs return 404 after a change.', $artist);
        $add('ArtistController@videos', 'Artist videos', $public . 'Published, approved videos filtered for this viewer, newest first. Private and followers-only visibility follows the existing video rules.', '$Video', '200', [], [], 20);
        $add('ArtistController@followers', 'Artist followers', $public . 'Existing owner-account followers with existing user visibility and blocks. Ordered by account creation time/ID descending. This is not a marketing subscriber/contact list.', '$User', '200', [], [], 20);
        $follow = ['following' => true, 'followers_count' => 1, 'following_count' => 1];
        $add('ArtistController@follow', 'Follow an artist', 'Idempotent alias of following the owner account; always 200. Cannot follow yourself. Uses the same follow row/notification as /users/{user}/follow. Does not enroll announcements.', $follow);
        $add('ArtistController@unfollow', 'Unfollow an artist', 'Idempotent account unfollow; does not grant or withdraw announcement consent.', ['following' => false, 'followers_count' => 0, 'following_count' => 0]);
        $add('ArtistController@mine', 'Your full artist profile', '404 before enrollment. Includes all saved links, image reference, featured_video_id and capabilities even after downgrade. Public resources apply current display limits.', $ownArtist, errors: $errors);
        $add('ArtistController@save', 'Create or update your artist profile', 'Requires adult enrollment. One artist per owner; unique lowercase slug (3–80 characters, letters/digits separated by hyphens). stage_name and slug required on every PUT. Other omitted fields are preserved. genre_ids=[] clears genres; at most five unique taxonomy IDs. image=null restores the account-avatar fallback; otherwise use your managed artist-image upload reference. Pinning a new featured_video_id requires Starter/Pro and your own approved, published, public video. Clearing is always allowed. Server-owned/unknown fields are rejected. Returns 201 on creation, 200 on update.', $ownArtist, '201', PutArtistRequest::class, ['stage_name' => 'Alex Band', 'slug' => 'alex-band', 'genre_ids' => [1], 'listed' => true], errors: $errors);
        $add('ArtistController@links', 'Replace your artist links', 'Complete ordered replacement; links=[] clears. Free allows one, Starter/Pro five. label/type/HTTPS URL only; credentials, unsafe schemes and unknown fields rejected. Saved excess links survive downgrade but only the first permitted links display publicly. No URL fetch or verification is performed.', $ownArtist, '200', PutArtistLinksRequest::class, ['links' => $artist['links']], errors: $errors);
        $add('UploadController@artistImage', 'Upload an artist image', 'Requires an existing owned artist. Multipart file, maximum 5 MiB JPEG/PNG/WebP; decoded/re-encoded and resized to 512×512. Returns a managed reference for image in PUT /me/artist; upload alone does not change the profile. Abandoned/replaced images use existing cleanup.', ['upload_method' => 'multipart', 'storage_path' => 'public:artist-images/1/example.webp', 'image_url' => 'https://media.example.com/artist-images/1/example.webp'], '201', AvatarUploadRequest::class, ['file' => '@/path/to/image.webp'], errors: $errors);

        $add('ArtistPlaylistController@index', 'Artist playlists', $public . 'Public, approved collections only. Filter genre_id/category. Counts and cover derive only from published videos visible to this viewer; hidden items do not leak through summary fields.', $playlist, '200', $filter, [], 20);
        $add('ArtistPlaylistController@show', 'Public playlist detail', $public . 'Playlist must belong to the route artist. Only public/approved collections; private collections use owner routes. Ordered visible videos, no hidden IDs or positions.', $playlist + ['videos' => ['$Video']]);
        $add('ArtistPlaylistController@mine', 'Your playlists', 'Includes private/restricted collections. read_only=true when total collections exceed the current plan limit; deleting collections remains available. Filter genre_id/category.', $ownPlaylist, '200', $filter, [], 20, $errors);
        $add('ArtistPlaylistController@ownShow', 'Your playlist detail', 'Owner-only version, read_only and ordered item metadata, including unfinished/private/restricted entries. videos contains only approved published videos visible to you; unfinished/restricted items have no media URL here.', $ownDetail, errors: $errors);
        $add('ArtistPlaylistController@store', 'Create a playlist', 'Free: one; Starter: ten; Pro: fifty. All collections count toward the limit. Ownership, limits and creation are serialized. Defaults: category=other, visibility=public. Unknown fields rejected.', $ownDetail, '201', $metadata, ['title' => 'Debut', 'genre_id' => 1, 'category' => 'release', 'visibility' => 'public'], errors: $errors);
        $add('ArtistPlaylistController@update', 'Edit playlist metadata', 'Allowed metadata plus required current version. Every successful metadata edit increments version; stale edits return 409. Excess collections are read-only until within the plan limit. Moderation cannot be overwritten.', $ownDetail, '200', [...$metadata, 'title' => ['sometimes', 'required', 'string', 'max:120'], 'version' => ['required', 'integer', 'min:1']], ['title' => 'Debut live', 'version' => 1], errors: $conflict);
        $add('ArtistPlaylistController@items', 'Replace or reorder playlist videos', 'Complete ordered unique video_ids array, maximum 100, plus current version. All videos must belong to the artist and not be deleted. Empty array clears. Transactional replace and version increment; failures preserve previous items. Owner metadata may include unfinished videos, but public data never does.', $ownDetail, '200', PutPlaylistItemsRequest::class, ['video_ids' => [1, 2], 'version' => 1], errors: $conflict);
        $add('ArtistPlaylistController@destroy', 'Delete a playlist', 'Owner-only; allowed after downgrade. Deletes the collection and items, never its videos. No response body.', null, '204', errors: $errors);

        $totals = ['followers' => 0, 'videos' => 1, 'views' => 10, 'likes' => 2, 'comments' => 0, 'saves' => 0, 'shares' => 0];
        $add('ArtistController@analytics', 'Your artist statistics', 'Current totals are free. Starter/Pro fixtures unlock last 30 UTC days of collected cumulative snapshots; daily=[] for free. Collection begins at enrollment, runs hourly and refreshes on owner reads. Missing days are absent; no backfill. Totals include approved published owned videos of all visibilities. Existing views are six-hour deduplicated records, not unique people, watch time or billable impressions. Deletion/moderation can lower totals.', [
            'totals' => $totals, 'data_since' => $date, 'timezone' => 'UTC', 'trends_available' => false,
            'period' => ['from' => '2026-10-02', 'to' => '2026-10-31'], 'daily' => [],
            'metric_definitions' => ['followers' => 'Current account follow relationships, not consent.', 'videos' => 'Currently published approved owned videos.', 'views' => 'Six-hour deduplicated records.', 'engagement' => 'Current stored engagement totals.', 'daily' => 'Last collected cumulative snapshot per UTC day, not daily events.'],
        ], errors: $errors);
        $add('ArtistController@fans', 'Your fan summary', 'Existing follow total and separate consent total. Consent count is the currently eligible in-app audience; follows never create consent. Paid observed_follower_change compares first and last observed snapshots in the 30-day window and returns their timestamps; null when unavailable or insufficient observations. No private contact list.', [
            'followers_count' => 0, 'announcement_subscribers_count' => 0, 'announcement_subscriptions_available' => true,
            'data_since' => $date, 'trends_available' => false, 'period' => ['from' => '2026-10-02', 'to' => '2026-10-31'],
            'observed_follower_change' => null, 'change_from' => null, 'change_to' => null,
        ], errors: $errors);

        return $result;
    }
}
