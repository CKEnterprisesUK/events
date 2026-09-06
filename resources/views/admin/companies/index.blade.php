@extends('layouts.app')

@section('title', 'Super-Admin — Companies')

@section('content')
    <section>
        <h1>Companies</h1>

        <p class="muted">Oversee every Company on the Platform and manage suspension.</p>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        @if ($companies->isEmpty())
            <p>No companies yet.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Status</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($companies as $company)
                        <tr data-company-id="{{ $company->id }}">
                            <td>{{ $company->name }}</td>
                            <td>{{ $company->slug }}</td>
                            <td data-status="{{ $company->status }}">{{ $company->status }}</td>
                            <td>
                                @if ($company->isSuspended())
                                    <form method="POST" action="{{ route('admin.companies.unsuspend', $company) }}">
                                        @csrf
                                        <button type="submit" class="btn">Unsuspend</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.companies.suspend', $company) }}">
                                        @csrf
                                        <button type="submit" class="btn">Suspend</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
