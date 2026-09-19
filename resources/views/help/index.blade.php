@extends('gadya-connect::help.layout')

@section('title', 'Get help')

@section('content')
    <h1>Get help from Gadya Media</h1>

    @if (! $connected)
        <p class="lede">This site is not connected to Gadya Media yet, so write to <a href="mailto:{{ config('gadya-connect.help_address') }}">{{ config('gadya-connect.help_address') }}</a>.</p>
    @else
        <p class="lede">It goes straight to the team, with the page you were on. You will get an email when they answer.</p>

        <form class="card" method="POST" action="{{ route('gadya-connect.help.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="type">What is it about</label>
            <select id="type" name="type">
                @foreach (['bug' => 'Something is broken', 'change' => 'A change to the site', 'content' => 'Words or photos', 'question' => 'A question'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', 'question') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <label for="subject">In a few words</label>
            <input id="subject" type="text" name="subject" value="{{ old('subject') }}" required>
            @error('subject')<p class="error">{{ $message }}</p>@enderror

            <label for="body">Tell us more</label>
            <textarea id="body" name="body" rows="6" required>{{ old('body') }}</textarea>
            @error('body')<p class="error">{{ $message }}</p>@enderror

            <label for="files">Screenshots or files (up to 5)</label>
            <input id="files" type="file" name="files[]" multiple>

            <label style="font-weight:400"><input type="checkbox" name="urgent" value="1"> It is urgent: the site is down or customers cannot buy or book</label>

            <button type="submit">Send to Gadya Media</button>
        </form>

        <div class="card">
            <strong>Your requests</strong>
            @forelse ($requests as $request)
                <a class="row" href="{{ route('gadya-connect.help.show', $request['reference']) }}">
                    <span>{{ $request['subject'] }} <span class="muted">{{ $request['reference'] }}</span></span>
                    <span class="muted">{{ $request['status_label'] }}</span>
                </a>
            @empty
                <p class="muted">Nothing yet.</p>
            @endforelse
        </div>
    @endif
@endsection
