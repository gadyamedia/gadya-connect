@extends('gadya-connect::help.layout')

@section('title', $conversation['subject'])

@section('content')
    <p><a href="{{ route('gadya-connect.help.index') }}">← All requests</a></p>
    <h1>{{ $conversation['subject'] }}</h1>
    <p class="lede">{{ $conversation['reference'] }} · {{ $conversation['status_label'] }}</p>

    @foreach ($conversation['messages'] as $message)
        <div class="message {{ $message['from_team'] ? 'team' : '' }}"><strong>{{ $message['author'] }}</strong>@if ($message['from_team']) <span class="muted">· Gadya Media</span>@endif
{{ $message['body'] }}@if ($message['files'] !== [])

<span class="muted">Attached: {{ implode(', ', $message['files']) }}</span>@endif</div>
    @endforeach

    <form class="card" method="POST" action="{{ route('gadya-connect.help.reply', $conversation['reference']) }}" enctype="multipart/form-data">
        @csrf
        <label for="body">Write back</label>
        <textarea id="body" name="body" rows="4" required>{{ old('body') }}</textarea>
        @error('body')<p class="error">{{ $message }}</p>@enderror
        <input type="file" name="files[]" multiple style="margin-top:.75rem">
        <button type="submit">Send</button>
    </form>
@endsection
