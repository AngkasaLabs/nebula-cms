@extends('theme::layouts.app')

@section('meta_title', $post->meta_title ?: $post->title)
@section('meta_description', $post->meta_description ?: Str::limit(strip_tags($post->content), 160))
@section('meta_keywords', $post->tags->pluck('name')->join(', '))
@section('meta_robots', $post->robots ?: 'index,follow')
@if (! empty($post->canonical_url))
    @section('canonical', $post->canonical_url)
@endif
@if (! empty($post->featured_image_url))
    @section('og_image', $post->featured_image_url)
@endif

@section('content')
    @if(!empty($is_preview))
        <div class="alert alert-warning text-center rounded-0 mb-0 border-0" role="alert">
            {{ __('posts.preview_banner') }}
        </div>
    @endif
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <!-- Post Header -->
                <header class="mb-4">
                    <h1 class="display-4 mb-3">{{ $post->title }}</h1>

                    <div class="d-flex align-items-center text-muted mb-4">
                        <span class="me-3">
                            <i class="bi bi-calendar me-1"></i>
                            {{ ($post->published_at ?? $post->created_at)->format('d M Y') }}
                        </span>

                        {{-- Reading Time Hook --}}
                        {!! apply_filters('post.meta', '', $post) !!}

                        @if ($post->category)
                            <a href="{{ route('blog', ['category' => $post->category->slug]) }}"
                                class="me-3 text-decoration-none">
                                <i class="bi bi-folder me-1"></i>
                                {{ $post->category->name }}
                            </a>
                        @endif
                    </div>
                </header>

                <!-- Featured Image -->
                @if ($post->featured_image_url)
                    <figure class="figure mb-4">
                        <img src="{{ $post->featured_image_url }}" class="figure-img img-fluid rounded"
                            alt="{{ $post->title }}">
                    </figure>
                @endif

                <!-- Post Content -->
                <article class="blog-post mb-5">
                    {!! apply_filters('post.content', $post->content, $post) !!}
                </article>

                <!-- Tags -->
                @if ($post->tags->count() > 0)
                    <div class="mb-5">
                        <h5>Tags:</h5>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($post->tags as $tag)
                                <a href="{{ route('blog', ['tag' => $tag->slug]) }}" class="btn btn-outline-secondary btn-sm">
                                    {{ $tag->name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Related Posts -->
                @if ($relatedPosts->count() > 0)
                    <section class="related-posts">
                        <h3 class="mb-4">Related Posts</h3>
                        <div class="row g-4">
                            @foreach ($relatedPosts as $relatedPost)
                                <div class="col-md-4">
                                    <div class="card h-100">
                                        @if ($relatedPost->featured_image_url)
                                            <img src="{{ $relatedPost->featured_image_url }}" class="card-img-top"
                                                alt="{{ $relatedPost->title }}">
                                        @endif

                                        <div class="card-body">
                                            <h5 class="card-title">{{ $relatedPost->title }}</h5>
                                            <p class="card-text text-muted">
                                                {{ Str::limit(strip_tags($relatedPost->content), 100) }}
                                            </p>

                                            <div class="d-flex justify-content-between align-items-center">
                                                <a href="{{ route('blog.post', $relatedPost->slug) }}"
                                                    class="btn btn-sm btn-outline-primary">
                                                    Read More
                                                </a>
                                                <small class="text-muted">
                                                    {{ $relatedPost->published_at?->diffForHumans() }}
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .blog-post {
            font-size: 1.1rem;
            line-height: 1.8;
        }

        .blog-post img {
            max-width: 100%;
            height: auto;
            border-radius: 0.375rem;
        }

        .blog-post h2,
        .blog-post h3,
        .blog-post h4 {
            margin-top: 2rem;
            margin-bottom: 1rem;
        }

        .blog-post p {
            margin-bottom: 1.5rem;
        }

        .blog-post blockquote {
            border-left: 4px solid #0d6efd;
            padding-left: 1rem;
            margin-left: 0;
            font-style: italic;
        }
    </style>
@endpush
