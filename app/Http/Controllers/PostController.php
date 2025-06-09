<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Http\Resources\PostResource;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PostController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PostResource::collection(
            Post::active()
                ->with('user')
                ->paginate(20)
        );
    }

    public function store(StorePostRequest $request): PostResource
    {
        $post = $request->user()->posts()->create($request->validated());
        return new PostResource($post->load('user'));
    }

    public function show(Post $post): PostResource
    {
        if (!$post->isPublished()) {
            abort(404, 'Post is not available');
        }

        return new PostResource($post->load('user'));
    }

    public function update(UpdatePostRequest $request, Post $post): PostResource
    {
        if (!$post->isOwnedBy($request->user())) {
            abort(403, 'You are not authorized to update this post');
        }

        $post->update($request->validated());
        return new PostResource($post->fresh()->load('user'));
    }

    public function destroy(Post $post): Response
    {
        if (!auth()->user()->can('delete', $post)) {
            abort(403, 'Unauthorized action.');
        }

        $post->delete();
        return response()->noContent();
    }
}