{{--
    A compact "other topics" nav shown at the foot of a Help category/article
    page, so readers can hop to another section without going back to the hub.
    @param \Illuminate\Support\Collection $categories  Published categories.
    @param \App\Models\HelpCategory       $current      The section being viewed.
--}}
@if ($categories->count() > 1)
    <nav class="kb__topics" aria-label="Other help topics">
        <h2>Other topics</h2>
        <div class="kb__topics-grid">
            @foreach ($categories as $topic)
                @continue($topic->slug === $current->slug)
                <a href="{{ route('help.category', $topic->slug) }}">{{ $topic->title }}</a>
            @endforeach
        </div>
    </nav>
@endif
