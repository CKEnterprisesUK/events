{{--
    "Sponsors" screen: manage the Event's repeatable sponsor list. Each sponsor
    is a logo plus optional store-page details (name, website, bio) and a
    "show on ticket" flag (capped at $maxOnTicket). Sponsors are shown on the
    public event/storefront page in order; the ticket preview shows how the
    on-ticket sponsors will print. Sponsors can also be copied in bulk from
    another of the organiser's events. (Sponsors management)
--}}
@extends('layouts.event')

@section('active_section', 'sponsors')

@section('section')
    @php
        $sponsorIds = $sponsors->pluck('id')->all();
        $onTicket = $sponsors->where('on_ticket', true)->values();
        $atOnTicketCap = $onTicketCount >= $maxOnTicket;
        $imgUrl = fn ($path) => \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    @endphp

    <div class="stack">
        <div>
            <h2 style="margin:0 0 .35rem;">Sponsors</h2>
            <p class="muted" style="margin:0;">
                Every sponsor appears on your public event page in the order below.
                You can show up to {{ $maxOnTicket }} sponsor{{ $maxOnTicket === 1 ? '' : 's' }} on the ticket.
                <strong>{{ $onTicketCount }}</strong> of {{ $maxOnTicket }} on the ticket.
            </p>
        </div>

        {{-- Ticket preview: how the on-ticket sponsors print at the bottom of
             the A4 e-ticket. Mirrors the tickets.pdf sponsor band. --}}
        <section class="sponsor-preview" aria-label="Ticket preview">
            <h3 class="sponsor-preview__title">Ticket preview</h3>
            @if ($onTicket->isEmpty())
                <p class="muted" style="margin:0;">
                    No sponsors are shown on the ticket yet, so your own logo is printed instead.
                    Turn on “Show on ticket” for up to {{ $maxOnTicket }} sponsors.
                </p>
            @else
                <div class="sponsor-preview__ticket">
                    <div class="sponsor-preview__band">
                        @foreach ($onTicket as $s)
                            <img src="{{ $imgUrl($s->image_path) }}"
                                 alt="{{ $s->name ?: 'Sponsor' }}"
                                 class="sponsor-preview__logo">
                        @endforeach
                    </div>
                    <p class="sponsor-preview__caption muted">Shown across the bottom of the ticket.</p>
                </div>
            @endif
        </section>

        {{-- Current sponsors, in display order. --}}
        <section>
            <h3 style="margin:0 0 .5rem;">Current sponsors</h3>

            @if ($sponsors->isEmpty())
                <p class="muted">No sponsors yet. Add one below, or copy sponsors from another event.</p>
            @else
                <ul class="sponsor-list">
                    @foreach ($sponsors as $index => $sponsor)
                        <li class="sponsor-list__item">
                            <div class="sponsor-list__logo">
                                <img src="{{ $imgUrl($sponsor->image_path) }}" alt="{{ $sponsor->name ?: 'Sponsor' }}">
                            </div>

                            <form class="sponsor-list__form"
                                  method="POST"
                                  action="{{ route('dashboard.events.sponsors.update', [$event, $sponsor]) }}"
                                  enctype="multipart/form-data">
                                @csrf
                                @method('PUT')

                                <div class="field">
                                    <label for="name-{{ $sponsor->id }}">Name</label>
                                    <input type="text" id="name-{{ $sponsor->id }}" name="name"
                                           value="{{ old('name', $sponsor->name) }}" maxlength="255">
                                </div>

                                <div class="field">
                                    <label for="website-{{ $sponsor->id }}">Website</label>
                                    <input type="url" id="website-{{ $sponsor->id }}" name="website_url"
                                           value="{{ old('website_url', $sponsor->website_url) }}"
                                           placeholder="https://example.com" maxlength="255">
                                </div>

                                <div class="field">
                                    <label for="bio-{{ $sponsor->id }}">Bio</label>
                                    <textarea id="bio-{{ $sponsor->id }}" name="bio" rows="2" maxlength="2000">{{ old('bio', $sponsor->bio) }}</textarea>
                                </div>

                                <div class="field">
                                    <label for="image-{{ $sponsor->id }}">Replace logo</label>
                                    <input type="file" id="image-{{ $sponsor->id }}" name="image" accept="image/*">
                                    <span class="field-hint">Leave blank to keep the current logo. Max 8&nbsp;MB.</span>
                                </div>

                                <label class="consent">
                                    <input type="checkbox" name="on_ticket" value="1"
                                           @checked($sponsor->on_ticket)
                                           @disabled($atOnTicketCap && ! $sponsor->on_ticket)>
                                    Show on ticket
                                    @if ($atOnTicketCap && ! $sponsor->on_ticket)
                                        <span class="field-hint">Limit of {{ $maxOnTicket }} reached — turn one off first.</span>
                                    @endif
                                </label>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-sm">Save</button>
                                </div>
                            </form>

                            <div class="sponsor-list__ops">
                                {{-- Move up / down: submit a reordered id list. --}}
                                @if ($index > 0)
                                    <form method="POST" action="{{ route('dashboard.events.sponsors.reorder', $event) }}">
                                        @csrf
                                        @php $up = $sponsorIds; [$up[$index - 1], $up[$index]] = [$up[$index], $up[$index - 1]]; @endphp
                                        @foreach ($up as $id)
                                            <input type="hidden" name="order[]" value="{{ $id }}">
                                        @endforeach
                                        <button type="submit" class="btn btn-outline btn-sm" aria-label="Move {{ $sponsor->name ?: 'sponsor' }} up">&uarr; Up</button>
                                    </form>
                                @endif
                                @if ($index < $sponsors->count() - 1)
                                    <form method="POST" action="{{ route('dashboard.events.sponsors.reorder', $event) }}">
                                        @csrf
                                        @php $down = $sponsorIds; [$down[$index + 1], $down[$index]] = [$down[$index], $down[$index + 1]]; @endphp
                                        @foreach ($down as $id)
                                            <input type="hidden" name="order[]" value="{{ $id }}">
                                        @endforeach
                                        <button type="submit" class="btn btn-outline btn-sm" aria-label="Move {{ $sponsor->name ?: 'sponsor' }} down">&darr; Down</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('dashboard.events.sponsors.destroy', [$event, $sponsor]) }}"
                                      onsubmit="return confirm('Remove this sponsor?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline btn-sm btn-danger">Remove</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Add a new sponsor. --}}
        <section class="sponsor-add">
            <h3 style="margin:0 0 .5rem;">Add a sponsor</h3>
            <form method="POST" action="{{ route('dashboard.events.sponsors.store', $event) }}" enctype="multipart/form-data" class="stack">
                @csrf

                <div class="field">
                    <label for="new-image">Sponsor logo</label>
                    <input type="file" id="new-image" name="image" accept="image/*" required>
                    <span class="field-hint">JPEG, PNG, GIF or WebP up to 8&nbsp;MB.</span>
                    @error('image')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="new-name">Name <span class="muted">(optional)</span></label>
                    <input type="text" id="new-name" name="name" value="{{ old('name') }}" maxlength="255">
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="new-website">Website <span class="muted">(optional)</span></label>
                    <input type="url" id="new-website" name="website_url" value="{{ old('website_url') }}"
                           placeholder="https://example.com" maxlength="255">
                    @error('website_url')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="new-bio">Bio <span class="muted">(optional)</span></label>
                    <textarea id="new-bio" name="bio" rows="2" maxlength="2000">{{ old('bio') }}</textarea>
                    @error('bio')<p class="error">{{ $message }}</p>@enderror
                </div>

                <label class="consent">
                    <input type="checkbox" name="on_ticket" value="1" @checked(old('on_ticket')) @disabled($atOnTicketCap)>
                    Show on ticket
                    @if ($atOnTicketCap)
                        <span class="field-hint">Limit of {{ $maxOnTicket }} reached — turn one off first.</span>
                    @endif
                </label>
                @error('on_ticket')<p class="error">{{ $message }}</p>@enderror

                <div class="form-actions">
                    <button type="submit" class="btn">Add sponsor</button>
                </div>
            </form>
        </section>

        {{-- Copy sponsors from another of the organiser's events. --}}
        @if ($copyableEvents->isNotEmpty())
            <section class="sponsor-copy">
                <h3 style="margin:0 0 .5rem;">Copy from another event</h3>
                <p class="muted" style="margin:0 0 .5rem;">
                    Bring in the sponsors from one of your other events. They’re added to the end of this list
                    and start off hidden from the ticket, so you can choose which to show.
                </p>
                <form method="POST" action="{{ route('dashboard.events.sponsors.copy', $event) }}" class="sponsor-copy__form">
                    @csrf
                    <div class="field">
                        <label for="source_event_id">Event</label>
                        <select id="source_event_id" name="source_event_id" required>
                            @foreach ($copyableEvents as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                            @endforeach
                        </select>
                        @error('source_event_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-outline">Copy sponsors</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
