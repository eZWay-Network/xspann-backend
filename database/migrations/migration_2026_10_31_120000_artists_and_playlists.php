<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class {
    public function up(): void
    {
        Schema::create('artist_genres', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
        });

        foreach (['Alternative', 'Blues', 'Classical', 'Country', 'Electronic', 'Folk', 'Hip Hop', 'Jazz', 'Latin', 'Metal', 'Pop', 'R&B', 'Reggae', 'Rock', 'Soul', 'World', 'Other'] as $index => $name) {
            DB::table('artist_genres')->insert([
                'id' => $index + 1,
                'name' => $name,
                'slug' => strtolower(str_replace([' ', '&'], ['-', 'and'], $name)),
            ]);
        }

        Schema::create('artists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique('user_id');
            $table->string('stage_name', 120);
            $table->string('slug', 80)->unique();
            $table->text('bio')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('image', 2048)->nullable();
            $table->foreignId('featured_video_id')->nullable()->constrained('videos')->setNullOnDelete();
            $table->boolean('listed')->default(true);
            $table->string('moderation_status', 20)->default('approved');
            $table->timestamp('data_since');
            $table->timestamps();
            $table->index(['listed', 'created_at', 'id']);
        });

        Schema::create('artist_genre_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained('artist_genres')->cascadeOnDelete();
            $table->unique(['artist_id', 'genre_id']);
            $table->index(['genre_id', 'artist_id']);
        });

        Schema::create('artist_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('type', 30);
            $table->string('url', 2048);
            $table->unsignedInteger('position');
            $table->unique(['artist_id', 'position']);
        });

        Schema::create('artist_playlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->foreignId('genre_id')->nullable()->constrained('artist_genres')->setNullOnDelete();
            $table->string('category', 30)->default('other');
            $table->string('visibility', 20)->default('public');
            $table->string('moderation_status', 20)->default('approved');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['artist_id', 'visibility', 'created_at', 'id']);
        });

        Schema::create('artist_playlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playlist_id')->constrained('artist_playlists')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unique(['playlist_id', 'video_id']);
            $table->unique(['playlist_id', 'position']);
        });

        Schema::create('artist_daily_statistics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->date('date');
            foreach (['followers', 'videos', 'views', 'likes', 'comments', 'saves', 'shares'] as $metric) {
                $table->unsignedBigInteger($metric)->default(0);
            }
            $table->timestamp('collected_at');
            $table->unique(['artist_id', 'date']);
        });

        if (db()->getDriver() === \Spark\Database\DB::DRIVER_SQLITE) {
            // Spark requires an inline nullable reference when altering a SQLite table.
            db()->statement('ALTER TABLE reports ADD COLUMN playlist_id INTEGER REFERENCES artist_playlists(id) ON DELETE SET NULL');
        } else {
            Schema::table('reports', function (Blueprint $table): void {
                $table->foreignId('playlist_id')->nullable()->constrained('artist_playlists')->setNullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            if (db()->getDriver() !== \Spark\Database\DB::DRIVER_SQLITE) {
                $table->dropForeign(['playlist_id']);
            }
            $table->dropColumn('playlist_id');
        });

        foreach (['artist_daily_statistics', 'artist_playlist_items', 'artist_playlists', 'artist_links', 'artist_genre_assignments', 'artists', 'artist_genres'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
