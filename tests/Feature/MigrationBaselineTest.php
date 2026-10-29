<?php

namespace Tests\Feature;

use App\Models\{Audio, User, Video};
use Tests\TestCase;

class MigrationBaselineTest extends TestCase
{
    public function testBaselineRollsBackAndRebuildsWithAudioForeignKeysAndNoSeededCredentials(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        $audio = Audio::create(['user_id' => $owner->id, 'source_video_id' => $video->id, 'title' => 'Original', 'storage_path' => 'sounds/1/a.m4a']);
        $video->update(['audio_id' => $audio->id]);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
        $paths = glob(dirname(__DIR__, 2) . '/database/migrations/migration_*.php');
        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }
        foreach ($paths as $path) {
            (require $path)->up();
        }
        $columns = array_column(db()->getPdo()->query('PRAGMA table_info(videos)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        foreach (['sound_name', 'sound_artist', 'sound_provider', 'sound_external_id', 'sound_preview_url'] as $removed) {
            $this->assertFalse(in_array($removed, $columns, true));
        }
        $this->assertTrue(in_array('rights_confirmed_at', $columns, true));
        $this->assertTrue(in_array('audio_id', $columns, true));
        $user = $this->makeUser();
        $audio = Audio::create(['user_id' => $user->id, 'title' => 'Sound', 'storage_path' => 'sounds/1/a.m4a']);
        $video = $this->makeVideo($user, ['audio_id' => $audio->id]);
        $audio->remove();
        $this->assertSame(null, $video->refresh()->audio_id);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
    }
}
