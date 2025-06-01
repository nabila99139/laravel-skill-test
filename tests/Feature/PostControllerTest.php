<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostControllerTest extends TestCase
{
    use RefreshDatabase;
    // 1. Authentication tests
    /** @test */
    public function guest_cannot_create_post(): void
    {
        $response = $this->postJson('/posts', []);

        $response->assertUnauthorized(); // Lebih idiomatik dari assertStatus(401)
    }

    // 2. Post Creation tests
    /** @test */
    public function authenticated_user_can_create_post(): void
    {
        $user = User::factory()->create();

        $payload = [
            'title' => 'Test Post',
            'content' => 'This is the content',
            'is_draft' => false,
            'published_at' => now()->subMinute()->toDateTimeString(),
        ];

        $response = $this->actingAs($user)->postJson('/posts', $payload);

        $response->assertCreated()
            ->assertJsonFragment(['title' => 'Test Post']);

        $this->assertDatabaseHas('posts', [
            'title' => 'Test Post',
            'user_id' => $user->id,
        ]);
    }

    /** @test */
    public function post_requires_validation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/posts', []);

        $response->assertUnprocessable();
    }

    // 3. Post Visibility tests
    /** @test */
    public function index_returns_only_active_posts(): void
    {
        // Buat 1 post aktif
        Post::factory()->create([
            'title' => 'Published',
            'is_draft' => false,
            'published_at' => now()->subHour() // Pastikan sudah terpublish
        ]);

        // Buat post yang seharusnya tidak muncul
        Post::factory()->create(['is_draft' => true]);
        Post::factory()->create([
            'is_draft' => false,
            'published_at' => now()->addDay()
        ]);

        $response = $this->getJson('/posts');

        $response->assertOk()
            ->assertJsonCount(1, 'data') // Harusnya hanya 1 post aktif
            ->assertJsonFragment(['title' => 'Published'])
            ->assertJsonMissing(['title' => 'Draft'])
            ->assertJsonMissing(['title' => 'Scheduled']);
    }

    /** @test */
    public function index_response_includes_user_data()
    {
        // Buat post aktif
        Post::factory()->create([
            'is_draft' => false,
            'published_at' => now()
        ]);

        $response = $this->getJson('/posts');

        $response->assertJsonStructure([
            'data' => [
                ['user'] // Pastikan setiap item dalam 'data' memiliki 'user'
            ]
        ]);
    }

    /** @test */
    public function posts_with_null_published_at_are_visible()
    {
        // Buat post dengan published_at null
        $post = Post::factory()->create([
            'is_draft' => false,
            'published_at' => null
        ]);

        // Harus muncul di index
        $this->getJson('/posts')
            ->assertJsonFragment(['id' => $post->id]);

        // Harus bisa diakses langsung
        $this->getJson("/posts/{$post->id}")
            ->assertOk();
    }

    /** @test */
    public function show_returns_post_if_active(): void
    {
        $post = Post::factory()->create([
            'is_draft' => false,
            'published_at' => now()->subMinute(),
        ]);

        $response = $this->getJson("/posts/{$post->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $post->id]);
    }

    /** @test */
    public function show_returns_404_if_draft_or_scheduled(): void
    {
        $draft = Post::factory()->create(['is_draft' => true]);

        $scheduled = Post::factory()->create([
            'is_draft' => false,
            'published_at' => now()->addHour(),
        ]);

        $this->getJson("/posts/{$draft->id}")->assertNotFound();
        $this->getJson("/posts/{$scheduled->id}")->assertNotFound();
    }

    /** @test */
    public function scheduled_posts_are_not_visible_before_publish_time()
    {
        $post = Post::factory()->create([
            'is_draft' => false,
            'published_at' => now()->addWeek() // Jadwal 1 minggu lagi
        ]);

        $this->getJson("/posts/{$post->id}")
            ->assertNotFound();
    }

    /** @test */
    public function draft_posts_return_404()
    {
        $draft = Post::factory()->create(['is_draft' => true]);

        $this->getJson("/posts/{$draft->id}")
            ->assertNotFound();
    }

    /** @test */
    public function index_includes_user_data()
    {
        Post::factory()->create(['is_draft' => false]);

        $this->getJson('/posts')
            ->assertJsonStructure(['data' => [['user']]]);
    }

    // 4. Post Update tests
    /** @test */
    public function user_can_update_own_post(): void
    {
        $user = User::factory()->create();

        $post = Post::factory()->for($user)->create();

        $payload = [
            'title' => 'Updated',
            'content' => 'New',
            'is_draft' => false,
            'published_at' => now()->toDateTimeString(),
        ];

        $response = $this->actingAs($user)->putJson("/posts/{$post->id}", $payload);

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Updated']);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'Updated',
        ]);
    }

    /** @test */
    public function user_cannot_update_others_post(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $post = Post::factory()->for($user2)->create();

        $response = $this->actingAs($user1)->putJson("/posts/{$post->id}", [
            'title' => 'Unauthorized',
            'content' => 'Blocked',
            'is_draft' => false,
            'published_at' => now()->toDateTimeString(),
        ]);

        $response->assertForbidden(); // 403
    }

    // 5. Post Deletion tests
    /** @test */
    public function user_can_delete_own_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $response = $this->actingAs($user)->deleteJson("/posts/{$post->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    /** @test */
    public function user_cannot_delete_others_post(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $post = Post::factory()->for($user2)->create();

        $response = $this->actingAs($user1)->deleteJson("/posts/{$post->id}");

        $response->assertForbidden(); // 403
    }
}
