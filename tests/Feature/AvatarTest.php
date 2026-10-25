<?php

namespace Tests\Feature;

use Spark\Queue\Queue;
use Tests\Support\HttpServer;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    public function testReplacementRemovesTheOldAvatarOnlyAfterASuccessfulUpdate(): void
    {
        $user = $this->makeUser();
        storage('public')->put('avatars/1/old.png', 'old');
        storage('public')->put('avatars/1/new.png', 'new');
        $user->update(['avatar' => 'avatars/1/old.png']);
        $this->asUser($user);

        $this->putJson('/api/v1/auth/profile', ['bio' => 'Updated bio'])->assertOk();
        $this->putJson('/api/v1/auth/profile', ['avatar' => media_url('avatars/1/old.png')])->assertOk();
        $this->putJson('/api/v1/auth/profile', ['avatar' => media_url('avatars/1/missing.png')])->assertStatus(422);
        $this->assertTrue(storage('public')->exists('avatars/1/old.png'));

        $this->putJson('/api/v1/auth/profile', ['avatar' => media_url('avatars/1/new.png')])
            ->assertOk()->assertJsonPath('data.avatar', media_url('avatars/1/new.png'));

        $this->assertFalse(storage('public')->exists('avatars/1/old.png'));
        $this->assertTrue(storage('public')->exists('avatars/1/new.png'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'public:avatars/1/new.png']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function testReplacementPreservesSharedAndExternalAvatars(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser('other');
        storage('public')->put('avatars/1/shared.png', 'shared');
        $user->update(['avatar' => 'avatars/1/shared.png']);
        query('users')->where('id', $other->id)->update(['avatar' => 'avatars/1/shared.png']);

        $this->asUser($user)->putJson('/api/v1/auth/profile', ['avatar' => 'https://external.example.com/avatar.png'])->assertOk();
        $this->assertTrue(storage('public')->exists('avatars/1/shared.png'));

        $this->putJson('/api/v1/auth/profile', ['avatar' => null])->assertOk()->assertJsonPath('data.avatar', null);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function testS3CleanupRetriesWithoutUndoingTheNewAvatar(): void
    {
        $server = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);

        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true]);
            $user = $this->makeUser();
            storage('s3')->put('avatars/1/old.png', 'old');
            storage('public')->put('avatars/1/new.png', 'new');
            $user->update(['avatar' => 's3:avatars/1/old.png']);
            file_put_contents($this->storagePath . '/fail-delete', '1');

            $this->asUser($user)->putJson('/api/v1/auth/profile', ['avatar' => media_url('avatars/1/new.png')])->assertOk();

            $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'public:avatars/1/new.png']);
            $this->assertTrue(storage('s3')->exists('avatars/1/old.png'));
            $this->assertDatabaseCount('jobs', 1);

            unlink($this->storagePath . '/fail-delete');
            app(Queue::class)->work(once: true, timeout: 5, sleep: 0);

            $this->assertFalse(storage('s3')->exists('avatars/1/old.png'));
            $this->assertTrue(storage('public')->exists('avatars/1/new.png'));
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            $server->stop();
        }
    }
}
