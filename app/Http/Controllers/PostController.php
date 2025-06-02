<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Post;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;

class PostController extends Controller
{
    public function index()
    {
        $posts = Post::where('is_draft', false)
            ->where(function ($query) {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->with('user')
            ->paginate(20);

        // scheduled posts
        // $posts = Post::where('is_draft', false)
        //     ->where('published_at', '>', now())
        //     ->with('user')
        //     ->paginate(20);

        // draft posts
        // $posts = Post::where('is_draft', true)
        //     ->with('user')
        //     ->paginate(20);

        return response()->json($posts);
    }

    public function store(StorePostRequest $request)
    {
        $post = $request->user()->posts()->create($request->validated());
        return response()->json($post, 201);
    }

    public function show(Post $post)
    {
        if ($post->is_draft || ($post->published_at && $post->published_at->isFuture())) {
            abort(404);
        }

        return response()->json($post->load('user'));
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        if ($post->user_id !== $request->user()->id) {
            abort(403);
        }

        $post->update($request->validated());
        return response()->json($post);
    }

    public function destroy(Request $request, Post $post)
    {
        if ($post->user_id !== $request->user()->id) {
            abort(403);
        }

        $post->delete();
        return response()->noContent();
    }
}
