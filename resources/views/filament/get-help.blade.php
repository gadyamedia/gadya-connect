<x-filament-panels::page>
    @if (! $this->isConnected())
        <x-filament::section>
            <x-slot name="heading">Email us</x-slot>
            <p style="font-size:.875rem">
                This site is not connected to Gadya Media yet, so write to
                <a href="mailto:{{ config('gadya-connect.help_address') }}" style="text-decoration:underline">{{ config('gadya-connect.help_address') }}</a>
                and we will pick it up from there.
            </p>
        </x-filament::section>
    @elseif ($this->open && ($conversation = $this->conversation))
        <x-filament::section>
            <x-slot name="heading">{{ $conversation['subject'] }}</x-slot>
            <x-slot name="description">{{ $conversation['reference'] }} · {{ $conversation['status_label'] }}</x-slot>
            <x-slot name="headerEnd">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left" wire:click="$set('open', null)">All requests</x-filament::button>
            </x-slot>

            <div style="display:flex;flex-direction:column;gap:.75rem">
                @foreach ($conversation['messages'] as $message)
                    <div wire:key="message-{{ $loop->index }}" style="border-radius:.75rem;padding:.875rem 1rem;border:1px solid rgb(0 0 0 / .08);{{ $message['from_team'] ? 'background:rgb(99 102 241 / .06)' : '' }}">
                        <p style="font-size:.8125rem;font-weight:600;margin:0 0 .375rem">
                            {{ $message['author'] }}@if ($message['from_team']) <span style="font-weight:400;opacity:.6">· Gadya Media</span>@endif
                            <span style="font-weight:400;opacity:.5">· {{ \Illuminate\Support\Carbon::parse($message['at'])->diffForHumans() }}</span>
                        </p>
                        <div style="font-size:.875rem;white-space:pre-wrap">{{ $message['body'] }}</div>
                        @if ($message['files'] !== [])
                            <p style="font-size:.75rem;opacity:.6;margin-top:.5rem">Attached: {{ implode(', ', $message['files']) }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <form wire:submit="sendReply" style="margin-top:1.25rem;display:flex;flex-direction:column;gap:.75rem">
                <x-filament::input.wrapper>
                    <textarea wire:model="reply" rows="4" placeholder="Write back" class="fi-input" style="width:100%;padding:.625rem .75rem;background:transparent;border:0;resize:vertical"></textarea>
                </x-filament::input.wrapper>
                @error('reply')<p style="color:rgb(220 38 38);font-size:.8125rem">{{ $message }}</p>@enderror
                <input type="file" wire:model="files" multiple style="font-size:.8125rem">
                <div><x-filament::button type="submit" icon="heroicon-o-paper-airplane">Send</x-filament::button></div>
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">What do you need?</x-slot>
            <x-slot name="description">It goes straight to the Gadya Media team, with the page you were on. You will get an email when they answer.</x-slot>

            <form wire:submit="send" style="display:flex;flex-direction:column;gap:1rem">
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    @foreach (['bug' => 'Something is broken', 'change' => 'A change to the site', 'content' => 'Words or photos', 'question' => 'A question'] as $value => $label)
                        <x-filament::button type="button" size="sm" :color="$type === $value ? 'primary' : 'gray'" wire:click="$set('type', '{{ $value }}')">{{ $label }}</x-filament::button>
                    @endforeach
                </div>

                <x-filament::input.wrapper :valid="! $errors->has('subject')">
                    <x-filament::input type="text" wire:model="subject" placeholder="In a few words" />
                </x-filament::input.wrapper>
                @error('subject')<p style="color:rgb(220 38 38);font-size:.8125rem">{{ $message }}</p>@enderror

                <x-filament::input.wrapper :valid="! $errors->has('body')">
                    <textarea wire:model="body" rows="6" placeholder="Tell us more: what you expected, what happened, which page." class="fi-input" style="width:100%;padding:.625rem .75rem;background:transparent;border:0;resize:vertical"></textarea>
                </x-filament::input.wrapper>
                @error('body')<p style="color:rgb(220 38 38);font-size:.8125rem">{{ $message }}</p>@enderror

                <div>
                    <p style="font-size:.8125rem;opacity:.7;margin-bottom:.25rem">Screenshots or files (up to 5)</p>
                    <input type="file" wire:model="files" multiple style="font-size:.8125rem">
                    @error('files.*')<p style="color:rgb(220 38 38);font-size:.8125rem">{{ $message }}</p>@enderror
                </div>

                <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem">
                    <x-filament::input.checkbox wire:model="urgent" />
                    It is urgent: the site is down or customers cannot book
                </label>

                <div><x-filament::button type="submit" icon="heroicon-o-paper-airplane">Send to Gadya Media</x-filament::button></div>
            </form>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Your requests</x-slot>

            @forelse ($this->requests as $request)
                <button type="button" wire:click="$set('open', '{{ $request['reference'] }}')" wire:key="request-{{ $request['reference'] }}"
                    style="display:flex;width:100%;justify-content:space-between;gap:1rem;padding:.625rem 0;border-top:1px solid rgb(0 0 0 / .06);text-align:left;font-size:.875rem">
                    <span><strong>{{ $request['subject'] }}</strong> <span style="opacity:.5">{{ $request['reference'] }}</span></span>
                    <span style="opacity:.7;white-space:nowrap">{{ $request['status_label'] }}</span>
                </button>
            @empty
                <p style="font-size:.875rem;opacity:.6">Nothing yet.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
