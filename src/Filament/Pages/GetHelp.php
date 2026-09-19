<?php

namespace Gadya\Connect\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Asking the Gadya team for help without leaving the admin: a new request
 * with screenshots, the requests already open, and the conversation in
 * each. Everyone who can sign in to the admin can use it.
 */
class GetHelp extends Page
{
    use WithFileUploads;

    protected string $view = 'gadya-connect::filament.get-help';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Get help';

    protected static ?string $title = 'Get help from Gadya Media';

    protected static ?string $slug = 'get-help';

    protected static ?int $navigationSort = 100;

    #[Url]
    public ?string $open = null;

    public string $type = 'question';

    public string $subject = '';

    public string $body = '';

    public bool $urgent = false;

    public string $reply = '';

    /** Where the person was before opening this page, sent along as context. */
    public ?string $cameFrom = null;

    /** @var array<int, UploadedFile> */
    public array $files = [];

    public function mount(): void
    {
        $previous = url()->previous();
        $this->cameFrom = $previous !== url()->current() ? $previous : null;
    }

    public function send(PortalClient $portal): void
    {
        $this->validate([
            'type' => ['required', 'in:bug,change,content,question'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'files' => ['array', 'max:5'],
            'files.*' => ['file', 'max:10240'],
        ]);

        try {
            $ticket = $portal->openTicket([
                'subject' => $this->subject,
                'body' => $this->body,
                'type' => $this->type,
                'priority' => $this->urgent ? 'urgent' : 'normal',
                'requester' => ['name' => auth()->user()?->name, 'email' => $this->email()],
                'context' => array_filter([
                    'page' => $this->cameFrom,
                    'browser' => request()->userAgent(),
                    'site' => config('app.url'),
                ]),
                'attachments' => $this->encodedFiles(),
            ]);
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('That did not go through')->body($exception->getMessage())->send();

            return;
        }

        $this->reset('subject', 'body', 'urgent', 'files');
        $this->open = $ticket['reference'] ?? null;

        Notification::make()->success()->title('Sent to Gadya Media')->body('You will get an email when they answer. You can reply to it, or here.')->send();
    }

    public function sendReply(PortalClient $portal): void
    {
        $this->validate([
            'reply' => ['required', 'string', 'max:20000'],
            'files' => ['array', 'max:5'],
            'files.*' => ['file', 'max:10240'],
        ]);

        try {
            $portal->replyToTicket((string) $this->open, $this->email(), auth()->user()?->name, $this->reply, $this->encodedFiles());
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('That did not go through')->body($exception->getMessage())->send();

            return;
        }

        $this->reset('reply', 'files');
        Notification::make()->success()->title('Sent')->send();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRequestsProperty(): array
    {
        return rescue(fn (): array => app(PortalClient::class)->tickets($this->email()), [], report: false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConversationProperty(): ?array
    {
        if ($this->open === null) {
            return null;
        }

        return rescue(fn (): array => app(PortalClient::class)->ticket($this->open, $this->email()), null, report: false);
    }

    public function isConnected(): bool
    {
        return Connection::current() !== null;
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    private function email(): string
    {
        return strtolower((string) auth()->user()?->email);
    }

    /**
     * @return list<array{filename: string, content_type: string, content: string}>
     */
    private function encodedFiles(): array
    {
        return collect($this->files)
            ->map(fn (UploadedFile $file): array => [
                'filename' => $file->getClientOriginalName(),
                'content_type' => $file->getMimeType() ?: 'application/octet-stream',
                'content' => base64_encode((string) file_get_contents($file->getRealPath())),
            ])
            ->values()
            ->all();
    }
}
