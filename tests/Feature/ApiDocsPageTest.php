<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocsPageTest extends TestCase
{
    public function test_api_docs_page_renders_api_routes(): void
    {
        $this->get('/api-docs')
            ->assertOk()
            ->assertSee('XSpann RNB API Docs')
            ->assertSee('Auth')
            ->assertSee('Feed')
            ->assertSee('Videos')
            ->assertSee('Social Actions')
            ->assertSee('/api/v1/auth/register')
            ->assertSee('/api/v1/videos/{video}/like');
    }
}
