<?php

namespace Gadya\Connect\Remote;

use Gadya\Connect\Remote\Handlers\ClearCache;
use Gadya\Connect\Remote\Handlers\MaintenanceDown;
use Gadya\Connect\Remote\Handlers\MaintenanceUp;
use Gadya\Connect\Remote\Handlers\ReportNow;
use Gadya\Connect\Remote\Handlers\RotateSecret;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the actions the portal has queued for this site: a check-in now,
 * clearing the caches, maintenance mode, a new secret, and whatever other
 * packages add. The portal never reaches into the site; the site asks it
 * for work every minute and runs only what it can prove the portal sent.
 *
 * A handler is any class tagged `gadya-connect.remote-commands` in the
 * container with two methods - nothing to extend or implement, so Gadya CMS
 * and others can add actions without depending on this version:
 *
 *     public function type(): string                 // "backup.run"
 *     public function handle(array $payload): array  // ['output' => string, 'result' => ?array]; throw to fail
 */
class RemoteCommands
{
    public const TAG = 'gadya-connect.remote-commands';

    /** @var list<class-string> */
    public const BUILT_IN = [
        ReportNow::class,
        ClearCache::class,
        MaintenanceDown::class,
        MaintenanceUp::class,
        RotateSecret::class,
    ];

    private const OUTPUT_LENGTH = 5000;

    /** Long enough to outlive the portal's retries of a command it did not hear back about. */
    private const REMEMBER_SECONDS = 2 * 24 * 60 * 60;

    /**
     * Every action this site can run, by type. When two classes claim one
     * type, the built-in wins, then whichever was tagged first.
     *
     * @return array<string, object>
     */
    public function handlers(): array
    {
        $handlers = [];

        $tagged = collect(iterator_to_array(app()->tagged(self::TAG), false))
            ->sortBy(fn (object $handler): int => in_array($handler::class, self::BUILT_IN, true) ? 0 : 1);

        foreach ($tagged as $handler) {
            if (! method_exists($handler, 'type') || ! method_exists($handler, 'handle')) {
                continue;
            }

            $type = (string) $handler->type();
            $handlers[$type] ??= $handler;
        }

        return $handlers;
    }

    /**
     * Checks one command from the portal and runs it if it passes. Nothing
     * runs unless the signature matches the secret the site held when it
     * asked for the commands and the command has not expired.
     *
     * A command the portal sends again (because it never heard how it went)
     * is not run twice: the first outcome is sent back instead.
     *
     * @param  array<string, mixed>  $command  {id, type, payload, expires_at, signature}
     * @return array{status: 'succeeded'|'failed', output: string, result: ?array}
     */
    public function run(array $command, string $secret): array
    {
        $id = (int) ($command['id'] ?? 0);
        $type = $command['type'] ?? null;
        $payload = $command['payload'] ?? [];
        $expiresAt = $command['expires_at'] ?? null;
        $signature = $command['signature'] ?? null;

        if ($id < 1 || ! is_string($type) || ! is_array($payload) || ! is_string($expiresAt) || ! is_string($signature)) {
            return $this->failed('The command was not in a form this site understands.');
        }

        if (! CommandSignature::verify($secret, $id, $type, $payload, $expiresAt, $signature)) {
            return $this->failed('The command\'s signature did not match this site\'s secret, so it was not run.');
        }

        $expiry = rescue(fn (): Carbon => Carbon::parse($expiresAt), null, report: false);

        if ($expiry === null || $expiry->isPast()) {
            return $this->failed("The command expired ({$expiresAt}) before the site picked it up, so it was not run.");
        }

        return Cache::get($this->rememberKey($id))
            ?? tap($this->handle($type, $payload), fn (array $outcome) => Cache::put($this->rememberKey($id), $outcome, self::REMEMBER_SECONDS));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{status: 'succeeded'|'failed', output: string, result: ?array}
     */
    private function handle(string $type, array $payload): array
    {
        if (! config('gadya-connect.remote_commands.enabled', true)) {
            return $this->failed('Actions from the portal are switched off on this site (GADYA_CONNECT_REMOTE_COMMANDS).');
        }

        if (in_array($type, (array) config('gadya-connect.remote_commands.except', []), true)) {
            return $this->failed("This site does not allow \"{$type}\" from the portal (gadya-connect.remote_commands.except).");
        }

        try {
            $handler = $this->handlers()[$type] ?? null;

            if ($handler === null) {
                return $this->failed("This site does not know how to run \"{$type}\". It may need a newer gadya/connect or gadya/cms.");
            }

            $outcome = $handler->handle($payload);
        } catch (Throwable $exception) {
            return $this->failed($exception->getMessage() ?: $exception::class);
        }

        return [
            'status' => 'succeeded',
            'output' => $this->limit((string) (is_array($outcome) ? ($outcome['output'] ?? '') : $outcome)),
            'result' => is_array($outcome) && is_array($outcome['result'] ?? null) ? $outcome['result'] : null,
        ];
    }

    /**
     * @return array{status: 'failed', output: string, result: null}
     */
    private function failed(string $why): array
    {
        return ['status' => 'failed', 'output' => $this->limit($why), 'result' => null];
    }

    private function limit(string $output): string
    {
        return Str::substr(trim($output), 0, self::OUTPUT_LENGTH);
    }

    private function rememberKey(int $id): string
    {
        return 'gadya-connect.commands.'.$id;
    }
}
