<?php

namespace Tests\Feature;

use Spark\Http\Routing\Router;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    private const ROUTES = [
        'POST /api/v1/auth/register',
        'POST /api/v1/auth/login',
        'POST /api/v1/auth/social/{provider}',
        'POST /api/v1/auth/forgot-password',
        'POST /api/v1/auth/reset-password',
        'GET /api/v1/feed',
        'GET /api/v1/discover',
        'GET /api/v1/discover/people',
        'GET /api/v1/videos',
        'GET /api/v1/videos/{video}',
        'GET /api/v1/users/suggestions',
        'GET /api/v1/users/{user:username}',
        'GET /api/v1/users/{user:username}/videos',
        'GET /api/v1/users/{user:username}/followers',
        'GET /api/v1/users/{user:username}/following',
        'GET /api/v1/videos/{video}/comments',
        'POST /api/v1/videos/{video}/share',
        'POST /api/v1/videos/{video}/view',
        'POST /api/v1/auth/logout',
        'GET /api/v1/auth/me',
        'PUT /api/v1/auth/profile',
        'PUT /api/v1/auth/password',
        'POST /api/v1/auth/email/verification-notification',
        'POST /api/v1/auth/token/refresh',
        'GET /api/v1/feed/following',
        'POST /api/v1/uploads/avatar',
        'POST /api/v1/uploads/videos/signed-url',
        'POST /api/v1/uploads/videos/local',
        'POST /api/v1/uploads/videos/chunk',
        'POST /api/v1/uploads/videos/complete',
        'POST /api/v1/uploads/sounds/local',
        'GET /api/v1/me/videos',
        'POST /api/v1/videos',
        'PATCH /api/v1/videos/{video}',
        'DELETE /api/v1/videos/{video}',
        'GET /api/v1/me/liked-videos',
        'POST /api/v1/videos/{video}/like',
        'DELETE /api/v1/videos/{video}/like',
        'POST /api/v1/videos/{video}/comments',
        'DELETE /api/v1/comments/{comment}',
        'GET /api/v1/me/saved-videos',
        'POST /api/v1/videos/{video}/save',
        'DELETE /api/v1/videos/{video}/save',
        'POST /api/v1/users/{user}/follow',
        'DELETE /api/v1/users/{user}/follow',
        'GET /api/v1/comments/{comment}/replies',
        'GET /api/v1/me/blocks',
        'POST /api/v1/users/{user}/block',
        'DELETE /api/v1/users/{user}/block',
        'POST /api/v1/comments/{comment}/reaction',
        'DELETE /api/v1/comments/{comment}/reaction',
        'POST /api/v1/reports',
        'GET /api/v1/notifications',
        'PATCH /api/v1/notifications/read-all',
        'PATCH /api/v1/notifications/{notification}/read',
    ];

    public function testEveryEndpointExistsAndDocsRender(): void
    {
        $expected = self::ROUTES;
        $actual = [];
        foreach (app(Router::class)->getRoutes() as $route) {
            if (str_starts_with($route['path'], '/api/v1/')) {
                $actual[] = $route['method'] . ' ' . $route['path'];
            }
        }
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->get('/')->assertOk()->assertSee('XSpann API')->assertSee('/api/v1/uploads/videos/complete');
    }

    public function testBladeDocsContainEveryRouteAndEscapeConfiguration(): void
    {
        $this->app->mergeConfig(['app' => ['url' => 'https://example.com/<script>alert(1)</script>']]);
        $response = $this->get('/')->assertOk();
        $html = $response->content();
        foreach (self::ROUTES as $route) {
            $response->assertSee(explode(' ', $route, 2)[1]);
        }
        foreach (['Authentication', 'Feed', 'Videos', 'Uploads', 'Users', 'Social actions', 'Parameters', 'Responses', 'Bearer token required', 'password_confirmation', 'filter_settings.brightness'] as $text) {
            $response->assertSee($text);
        }
        $this->assertFalse(str_contains($html, '<script>alert(1)</script>'));
        $this->assertTrue(str_contains($html, '&lt;script&gt;'));
        $this->assertFalse(str_contains($html, '@foreach'));
        $this->assertSame(count(self::ROUTES), substr_count($html, '<details class="endpoint"'));
    }

    public function testDocumentationContractsCoverAllRegisteredOperations(): void
    {
        $html = $this->get('/')->assertOk()->content();
        preg_match('/<script id="reference-data" type="application\/json">(.*?)<\/script>/s', $html, $matches);
        $docs = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $operations = [];
        $entries = [];
        foreach ($docs['groups'] as $group) {
            foreach ($group as $entry) {
                $operations[] = $entry['method'] . ' ' . $entry['route'];
                $entries[$entry['id']] = $entry;
                $this->assertTrue(strlen($entry['description']) > 20);
                $this->assertTrue(array_key_exists('data', $entry['response']));
                foreach ($entry['parameters'] as $parameter) {
                    $this->assertTrue(in_array($parameter['in'], ['path', 'query', 'body'], true));
                }
            }
        }
        $expected = self::ROUTES;
        sort($expected);
        sort($operations);
        $this->assertSame($expected, $operations);
        $this->assertSame(55, count($entries));
        $this->assertFalse($entries['discovercontroller-index']['auth']);
        $this->assertFalse($entries['discovercontroller-people']['auth']);
        $this->assertSame(120, $entries['discovercontroller-index']['rate']);
        $this->assertSame(null, $entries['discovercontroller-index']['contentType']);
        $this->assertSame(['q', 'page', 'limit', 'sort'], array_column($entries['discovercontroller-index']['parameters'], 'name'));
        $this->assertSame(['q', 'page', 'limit'], array_column($entries['discovercontroller-people']['parameters'], 'name'));
        $this->assertSame(['query'], array_values(array_unique(array_column($entries['discovercontroller-index']['parameters'], 'in'))));
        $this->assertSame(18, $entries['discovercontroller-index']['response']['meta']['per_page']);
        $this->assertFalse($entries['authcontroller-login']['auth']);
        $this->assertTrue($entries['authcontroller-me']['auth']);
        $this->assertSame(5, $entries['authcontroller-login']['rate']);
        $this->assertSame(900, $entries['uploadcontroller-videochunk']['rate']);
        $this->assertSame('multipart/form-data', $entries['uploadcontroller-videochunk']['contentType']);
        $this->assertSame('multipart', $entries['uploadcontroller-video']['alternate']['data']['upload_method']);
        $this->assertSame(18, $entries['usercontroller-suggestions']['response']['meta']['per_page']);
        $this->assertSame(null, $entries['authcontroller-register']['response']['data']['token']);
        $this->assertSame('username', $entries['usercontroller-show']['parameters'][0]['name']);
        $this->assertFalse(str_contains($html, '@if'));
        $this->assertFalse(str_contains($html, '@endif'));
    }

    public function testValidationAndMissingRecordsAreJsonWithCors(): void
    {
        $headers = ['Origin' => 'http://localhost:3000'];
        $this->postJson('/api/v1/auth/register', [], $headers)->assertStatus(422)->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->getJson('/api/v1/videos/98765', $headers)->assertStatus(404)->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->getJson('/api/v1/videos/not-a-number')->assertStatus(404);
        $this->getJson('/api/v1/auth/me', $headers)->assertStatus(401)->assertHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function testPaginationAndPinnedPostsSortFirst(): void
    {
        $user = $this->makeUser();
        $pinned = $this->makeVideo($user, ['pinned_at' => now()]);
        $this->makeVideo($user);
        $this->asUser($user);
        $this->getJson('/api/v1/me/videos')->assertJsonPath('data.0.id', (int) $pinned->id);
        $this->getJson('/api/v1/feed?limit=0')->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/feed?limit=99999')->assertJsonPath('meta.per_page', 100);
    }

    public function testRevokedExpiredAndTamperedTokensAreRejected(): void
    {
        $user = $this->makeUser();
        $token = $this->token($user);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        query('jwt_access_tokens')->where('user_id', $user->id)->update(['expire_at' => '2000-01-01 00:00:00']);
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken(substr($token, 0, -8) . 'tampered')->getJson('/api/v1/auth/me')->assertStatus(401);
    }
    public function testVideoProfileAndCommentResourcesMatchLaravelFields(): void
    {
        $user = $this->makeUser();
        $video = $this->makeVideo($user);
        $data = $this->getJson('/api/v1/feed')->assertOk()->json('data.0');
        $this->assertFields([
            'id',
            'video_url',
            'thumbnail_url',
            'caption',
            'sound_name',
            'sound_artist',
            'sound_provider',
            'sound_external_id',
            'sound_preview_url',
            'music',
            'tags',
            'duration',
            'location_name',
            'visibility',
            'high_quality_upload',
            'scheduled_at',
            'pinned_at',
            'edit',
            'status',
            'user',
            'stats',
            'viewer',
            'created_at',
        ], $data);
        $this->assertFields(['id', 'name', 'username', 'avatar', 'bio', 'followers_count', 'following_count', 'following', 'cover_url', 'cover_video_url'], $data['user']);
        $this->assertFields(['views', 'likes', 'comments', 'saves', 'shares'], $data['stats']);
        $this->assertFields(['liked', 'saved', 'following'], $data['viewer']);
        $this->assertFields(['trim_start', 'trim_end', 'cut_points', 'cover_time', 'crop_mode', 'text_overlay', 'original_audio_muted', 'filter_settings', 'effect_settings'], $data['edit']);
        $this->assertSame($data, $this->getJson('/api/v1/videos')->assertOk()->json('data.0'));
        $this->assertSame($data, $this->getJson('/api/v1/videos/' . $video->id)->assertOk()->json('data'));
        $profile = $this->getJson('/api/v1/users/alice')->assertOk()->json('data');
        $this->assertFields(['id', 'name', 'username', 'email', 'email_verified_at', 'avatar', 'bio', 'followers_count', 'following_count', 'likes_count', 'videos_count', 'following'], $profile);
        $comment = $this->asUser($user)->postJson('/api/v1/videos/' . $video->id . '/comments', ['body' => 'Hello'])->assertStatus(201)->json('data');
        $this->assertFields(['id', 'video_id', 'parent_id', 'body', 'user', 'created_at', 'replies_count', 'reactions', 'viewer_reaction'], $comment);
        $this->assertSame($comment, $this->getJson('/api/v1/videos/' . $video->id . '/comments')->assertOk()->json('data.0'));
    }

    private function assertFields(array $expected, array $data): void
    {
        $actual = array_keys($data);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

}
