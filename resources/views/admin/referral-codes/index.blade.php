@extends('layouts.dashboard')
@section('title', 'Super-Admin — Referral codes')
@section('content')
    <div class="admin-head">
        <div>
            <h1>Referral codes</h1>
            <p>Companies that sign up with one of these codes get its fee percent applied to their account. Share a link like <span class="mono">{{ url('/register') }}?ref=CODE</span> to pre-fill it.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Create a referral code</h2></div>
        <div class="admin-panel__body">
            <form method="POST" action="{{ route('admin.referral-codes.store') }}">
                @csrf
                <div class="field">
                    <label for="code">Code <span class="hint">(leave blank to generate one)</span></label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}" maxlength="64" pattern="[A-Za-z0-9_-]*">
                    @error('code')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="fee_percent">Fee percent</label>
                    <input type="number" id="fee_percent" name="fee_percent" value="{{ old('fee_percent') }}" step="0.01" min="0" max="100" required>
                    @error('fee_percent')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="label">Label <span class="hint">(optional, internal)</span></label>
                    <input type="text" id="label" name="label" value="{{ old('label') }}" maxlength="255">
                    @error('label')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="max_uses">Maximum uses <span class="hint">(optional)</span></label>
                    <input type="number" id="max_uses" name="max_uses" value="{{ old('max_uses') }}" min="1">
                    @error('max_uses')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Create code</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Codes</h2></div>
        <table class="admin-facts">
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Label</th>
                    <th scope="col">Fee</th>
                    <th scope="col">Uses</th>
                    <th scope="col">Status</th>
                    <th scope="col">Signup link</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($codes as $code)
                    <tr>
                        <td class="mono">{{ $code->code }}</td>
                        <td>{{ $code->label ?? '—' }}</td>
                        <td>{{ $code->fee_percent }}%</td>
                        <td>{{ $code->uses_count }}{{ $code->max_uses ? ' / '.$code->max_uses : '' }}</td>
                        <td>
                            @if ($code->isUsable())
                                <span class="admin-pill admin-pill--active">Active</span>
                            @else
                                <span class="admin-pill">{{ $code->is_active ? 'Used up' : 'Disabled' }}</span>
                            @endif
                        </td>
                        <td class="mono">{{ url('/register') }}?ref={{ $code->code }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.referral-codes.toggle', $code) }}" style="display:inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-sm">{{ $code->is_active ? 'Disable' : 'Enable' }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.referral-codes.destroy', $code) }}" style="display:inline" onsubmit="return confirm('Delete this code? Companies that used it keep their fee.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="hint">No referral codes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
