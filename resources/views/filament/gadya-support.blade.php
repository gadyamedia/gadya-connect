<x-filament-panels::page>
    @php($connection = $this->connection)

    @if ($connection)
        <x-filament::section>
            <x-slot name="heading">Connected to Gadya Media</x-slot>
            <x-slot name="description">This site checks in with the Gadya team's portal every few minutes, so they know it is up, healthy and up to date.</x-slot>

            <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1.5rem;font-size:.875rem">
                <dt style="opacity:.6">In the portal as</dt>
                <dd>{{ $connection->site_name }}@if ($connection->client_name) ({{ $connection->client_name }})@endif</dd>
                <dt style="opacity:.6">Last check-in</dt>
                <dd>{{ $connection->last_report_at?->diffForHumans() ?? 'Not yet' }}</dd>
                @if ($connection->last_error)
                    <dt style="opacity:.6">Last problem</dt>
                    <dd style="color:rgb(220 38 38)">{{ $connection->last_error }}</dd>
                @endif
            </dl>

            <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem">
                <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="sendNow">Check in now</x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-link-slash" wire:click="disconnect"
                    wire:confirm="Disconnect this site from Gadya Media? The team will stop seeing whether it is up.">Disconnect</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Sign-in for the Gadya team</x-slot>
            <x-slot name="description">
                When it is on, the Gadya team can open this admin from their portal to help you, without asking for a password. Every sign-in is recorded.
            </x-slot>

            <x-filament::button :color="$connection->sso_enabled ? 'danger' : 'primary'" wire:click="toggleSignIn">
                {{ $connection->sso_enabled ? 'Turn it off' : 'Allow the Gadya team to sign in' }}
            </x-filament::button>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Connect to Gadya Media</x-slot>
            <x-slot name="description">Paste the pairing code the Gadya team gave you. The site then reports to them every few minutes: whether it is up, healthy and up to date.</x-slot>

            <form wire:submit="connect" style="display:flex;gap:.75rem;align-items:flex-start;flex-wrap:wrap">
                <div style="flex:1;min-width:14rem">
                    <x-filament::input.wrapper :valid="! $errors->has('code')">
                        <x-filament::input type="text" wire:model="code" placeholder="GDY-XXXX-XXXX" autocomplete="off" style="font-family:ui-monospace,monospace;letter-spacing:.1em" />
                    </x-filament::input.wrapper>
                    @error('code')<p style="color:rgb(220 38 38);font-size:.8125rem;margin-top:.375rem">{{ $message }}</p>@enderror
                </div>
                <x-filament::button type="submit" icon="heroicon-o-link">Connect</x-filament::button>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
