<?php

namespace Gadya\Connect\Http\Controllers;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Get help for a Laravel site without Filament: the same requests as the
 * admin page, as plain pages for whoever is signed in.
 */
class HelpController extends Controller
{
    public function __construct(private readonly PortalClient $portal) {}

    public function index(Request $request): View
    {
        return view('gadya-connect::help.index', [
            'connected' => Connection::current() !== null,
            'requests' => rescue(fn (): array => $this->portal->tickets($this->email($request)), [], report: false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:bug,change,content,question'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'files' => ['array', 'max:5'],
            'files.*' => ['file', 'max:10240'],
        ]);

        try {
            $ticket = $this->portal->openTicket([
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'type' => $validated['type'],
                'priority' => $request->boolean('urgent') ? 'urgent' : 'normal',
                'requester' => ['name' => $request->user()?->name, 'email' => $this->email($request)],
                'context' => array_filter(['page' => url()->previous(), 'browser' => $request->userAgent(), 'site' => config('app.url')]),
                'attachments' => $this->files($request),
            ]);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['subject' => $exception->getMessage()]);
        }

        return redirect()->route('gadya-connect.help.show', $ticket['reference'])->with('gadya-connect.status', 'Sent. You will get an email when the Gadya team answers.');
    }

    public function show(Request $request, string $reference): View
    {
        $conversation = rescue(fn (): array => $this->portal->ticket($reference, $this->email($request)), null, report: false);

        abort_if($conversation === null, 404);

        return view('gadya-connect::help.show', ['conversation' => $conversation]);
    }

    public function reply(Request $request, string $reference): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'files' => ['array', 'max:5'],
            'files.*' => ['file', 'max:10240'],
        ]);

        try {
            $this->portal->replyToTicket($reference, $this->email($request), $request->user()?->name, $validated['body'], $this->files($request));
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['body' => $exception->getMessage()]);
        }

        return back()->with('gadya-connect.status', 'Sent.');
    }

    private function email(Request $request): string
    {
        return strtolower((string) $request->user()?->email);
    }

    /**
     * @return list<array{filename: string, content_type: string, content: string}>
     */
    private function files(Request $request): array
    {
        return collect($request->file('files', []))
            ->filter(fn ($file): bool => $file instanceof UploadedFile)
            ->map(fn (UploadedFile $file): array => [
                'filename' => $file->getClientOriginalName(),
                'content_type' => $file->getMimeType() ?: 'application/octet-stream',
                'content' => base64_encode((string) file_get_contents($file->getRealPath())),
            ])
            ->values()
            ->all();
    }
}
