<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createActivePost(array $attributes = []): Post
    {
        return Post::factory()->create(array_merge([
            'is_draft' => false,
            'published_at' => now()->subMinute()
        ], $attributes));
    }

    protected function createDraftPost(array $attributes = []): Post
    {
        return Post::factory()->create(array_merge([
            'is_draft' => true
        ], $attributes));
    }

    protected function createScheduledPost(array $attributes = []): Post
    {
        return Post::factory()->create(array_merge([
            'is_draft' => false,
            'published_at' => now()->addDay()
        ], $attributes));
    }

    // Authentication tests
    public function test_guest_cannot_create_post(): void
    {
        $response = $this->postJson('/posts', []);
        $response->assertUnauthorized();
    }

    // Post Creation tests
    public function test_authenticated_user_can_create_post(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/posts', [
            'title' => 'Test Post',
            'content' => 'Test Content',
            'is_draft' => false,
            'published_at' => null
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [ 
                    'id',
                    'title',
                    'content',
                    'is_draft',
                    'published_at',
                    'user' => ['id', 'name', 'email']
                ]
            ])
            ->assertJson([
                'data' => [ 
                    'title' => 'Test Post',
                    'is_draft' => false
                ]
            ]);

        $this->assertDatabaseHas('posts', [
            'title' => 'Test Post',
            'user_id' => $user->id,
            'is_draft' => false
        ]);
    }

    public function test_post_creation_requires_validation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/posts', []);
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'content', 'is_draft']);
    }

    // Post Visibility tests
    public function test_index_returns_only_active_posts(): void
    {
        $active = $this->createActivePost(['title' => 'Active Post']);
        $this->createDraftPost(['title' => 'Draft Post']);
        $this->createScheduledPost(['title' => 'Scheduled Post']);

        $response = $this->getJson('/posts');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Active Post')
            ->assertJsonMissing(['title' => 'Draft Post'])
            ->assertJsonMissing(['title' => 'Scheduled Post']);
    }

    public function test_index_includes_user_data(): void
    {
        $this->createActivePost();

        $response = $this->getJson('/posts');
        $response->assertJsonStructure([
            'data' => [['user' => ['id', 'name', 'email']]]
        ]);
    }

    public function test_show_returns_active_post(): void
    {
        $post = $this->createActivePost(['title' => 'Test Post']);

        $response = $this->getJson("/posts/{$post->id}");
        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $post->id,
                    'title' => 'Test Post'
                ]
            ]);
    }

    public function test_show_returns_404_for_draft(): void
    {
        $post = $this->createDraftPost();
        $this->getJson("/posts/{$post->id}")
            ->assertNotFound();
    }

    public function test_show_returns_404_for_scheduled(): void
    {
        $post = $this->createScheduledPost();
        $this->getJson("/posts/{$post->id}")
            ->assertNotFound();
    }

    // Post Update tests
    public function test_user_can_update_own_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $response = $this->actingAs($user)->putJson("/posts/{$post->id}", [
            'title' => 'Updated Title',
            'content' => 'Updated Content',
            'is_draft' => true,
            'published_at' => null
        ]);

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'title' => 'Updated Title',
                    'content' => 'Updated Content',
                    'is_draft' => true
                ]
            ]);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'Updated Title'
        ]);
    }

    public function test_user_cannot_update_others_post(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $post = Post::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->putJson("/posts/{$post->id}", [
            'title' => 'Hacked Title'
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('posts', ['title' => 'Hacked Title']);
    }

    // Post Deletion tests
    public function test_user_can_delete_own_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $response = $this->actingAs($user)->deleteJson("/posts/{$post->id}");
        $response->assertNoContent();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_user_cannot_delete_others_post(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $post = Post::factory()->for($owner)->create();

        $response = $this->actingAs($otherUser)->deleteJson("/posts/{$post->id}");
        $response->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    // Pagination tests
    public function test_index_returns_paginated_results(): void
    {
        Post::factory()->count(25)->create(['is_draft' => false]);

        $response = $this->getJson('/posts');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta'
            ])
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20);
    }

    // Model tests
    public function test_is_published_works_correctly(): void
    {
        $publishedPost = Post::factory()->create([
            'is_draft' => false,
            'published_at' => null
        ]);

        $draftPost = Post::factory()->create(['is_draft' => true]);

        $scheduledPost = Post::factory()->create([
            'is_draft' => false,
            'published_at' => now()->addDay()
        ]);

        $this->assertTrue($publishedPost->isPublished());
        $this->assertFalse($draftPost->isPublished());
        $this->assertFalse($scheduledPost->isPublished());
    }

    // Additional edge cases
    public function test_show_returns_404_for_non_existing_post(): void
    {
        $this->getJson('/posts/9999')->assertNotFound();
    }

    public function test_update_with_invalid_data_returns_validation_errors(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $response = $this->actingAs($user)->putJson("/posts/{$post->id}", [
            'title' => '',
            'content' => '',
            'is_draft' => 'not-a-boolean'
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['title', 'content', 'is_draft']);
    }
}
