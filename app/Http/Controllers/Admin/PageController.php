<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageRevision;
use App\Services\ContentSanitizer;
use App\Support\ContentSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PageController extends Controller
{
    public function __construct(
        protected ContentSanitizer $sanitizer,
    ) {
        $this->middleware('permission:view content')->only(['index', 'show', 'revisions']);
        $this->middleware('permission:create content')->only(['create', 'store']);
        $this->middleware('permission:edit content')->only(['edit', 'update', 'restoreRevision']);
        $this->middleware('permission:delete content')->only('destroy');
        $this->middleware('permission:publish content')->only(['publish', 'unpublish']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Page::with(['user']);

        if ($request->filled('search')) {
            ContentSearch::applyToPageQuery($query, $request->string('search')->toString());
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $pages = $query->orderBy('order')->paginate(10);

        return Inertia::render('Admin/Pages/Index', [
            'pages' => $pages,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', 'all'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Pages/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
            'featured_image' => 'nullable|image|max:2048',
            'meta_description' => 'nullable|max:255',
            'meta_keywords' => 'nullable|max:255',
            'status' => 'required|in:draft,published,scheduled,pending_review',
            'published_at' => 'nullable|date|required_if:status,scheduled|after:now',
            'order' => 'nullable|integer',
        ]);

        if ($validated['status'] === 'scheduled') {
            $validated['published_at'] = $validated['published_at'] ?? null;
        } else {
            $validated['published_at'] = $validated['status'] === 'published' ? now() : null;
        }

        if ($request->hasFile('featured_image')) {
            $path = $request->file('featured_image')->store('pages', 'public');
            $validated['featured_image'] = $path;
        }

        $validated['content'] = $this->sanitizer->sanitizeForUser($validated['content'] ?? null, $request->user());
        $validated['user_id'] = Auth::id();
        $validated['slug'] = str($request->title)->slug();

        Page::create($validated);

        return redirect()->route('admin.pages.index')
            ->with('message', 'Halaman berhasil dibuat.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Page $page)
    {
        $page->load(['user']);

        return Inertia::render('Admin/Pages/Show', [
            'page' => $page,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Page $page)
    {
        return Inertia::render('Admin/Pages/Edit', [
            'page' => $page,
            'preview' => [
                'url' => URL::route('preview.page', $page),
                'signed_url' => URL::temporarySignedRoute(
                    'preview.page',
                    now()->addHours(72),
                    ['page' => $page->id]
                ),
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Page $page)
    {
        $validated = $request->validate([
            'title' => 'required|max:255',
            'content' => 'required',
            'featured_image' => 'nullable|image|max:2048',
            'meta_description' => 'nullable|max:255',
            'meta_keywords' => 'nullable|max:255',
            'status' => 'required|in:draft,published,scheduled,pending_review',
            'published_at' => 'nullable|date|required_if:status,scheduled|after:now',
            'order' => 'nullable|integer',
        ]);

        if ($validated['status'] === 'scheduled') {
            $validated['published_at'] = $validated['published_at'] ?? null;
        } else {
            $validated['published_at'] = $validated['status'] === 'published' ? now() : null;
        }

        if ($request->hasFile('featured_image')) {
            if ($page->featured_image) {
                Storage::disk('public')->delete($page->featured_image);
            }
            $path = $request->file('featured_image')->store('pages', 'public');
            $validated['featured_image'] = $path;
        }

        $validated['content'] = $this->sanitizer->sanitizeForUser($validated['content'] ?? null, $request->user());
        $validated['slug'] = str($request->title)->slug();

        $page->createRevision();
        $page->update($validated);

        return redirect()->route('admin.pages.index')
            ->with('message', 'Halaman berhasil diperbarui.');
    }

    /**
     * List revisions for a page.
     */
    public function revisions(Page $page)
    {
        $revisions = $page->revisions()
            ->with('user:id,name')
            ->get()
            ->map(function (PageRevision $revision) {
                return [
                    'id' => $revision->id,
                    'revision_number' => $revision->revision_number,
                    'title' => $revision->title,
                    'content_preview' => Str::limit(strip_tags($revision->content), 200),
                    'user' => $revision->user->name,
                    'created_at' => $revision->created_at->format('d M Y H:i'),
                    'created_at_diff' => $revision->created_at->diffForHumans(),
                ];
            });

        return Inertia::render('Admin/Pages/Revisions', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
            ],
            'revisions' => $revisions,
        ]);
    }

    /**
     * Restore a page to a previous revision.
     */
    public function restoreRevision(Page $page, PageRevision $revision)
    {
        if ($revision->page_id !== $page->id) {
            return back()->with('error', 'Invalid revision.');
        }

        $page->createRevision();

        $page->update([
            'title' => $revision->title,
            'content' => $this->sanitizer->sanitizeForUser($revision->content, request()->user()),
            'featured_image' => $revision->featured_image,
            'meta_description' => $revision->meta_description,
            'meta_keywords' => $revision->meta_keywords,
        ]);

        return redirect()->route('admin.pages.edit', $page)
            ->with('message', 'Halaman dikembalikan ke revisi #'.$revision->revision_number);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Page $page)
    {
        if ($page->featured_image) {
            Storage::disk('public')->delete($page->featured_image);
        }

        $page->delete();

        return redirect()->route('admin.pages.index')
            ->with('message', 'Halaman berhasil dihapus.');
    }

    public function publish(Page $page)
    {
        $page->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return back()->with('message', 'Halaman berhasil dipublikasikan.');
    }

    public function unpublish(Page $page)
    {
        $page->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        return back()->with('message', 'Halaman berhasil dijadikan draft.');
    }
}
