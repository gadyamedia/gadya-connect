<?php

namespace Gadya\Connect\Tests\Unit;

use Gadya\Connect\Report\ErrorCount;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The errors section of the check-in, read from log files written the way
 * Laravel writes them.
 */
class ErrorCountTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/gadya-connect-logs-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/logs');
        $this->app->useStoragePath($this->storage);

        $this->travelTo('2026-09-27 12:00:00');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function log(string $file, string $contents): void
    {
        file_put_contents($this->storage.'/logs/'.$file, $contents, FILE_APPEND);
    }

    private function exception(string $moment, string $class, string $message, string $file, int $line, string $level = 'ERROR'): string
    {
        $escapedClass = str_replace('\\', '\\\\', $class);
        $escapedFile = base_path($file);

        return "[{$moment}] production.{$level}: {$message} {\"userId\":3,\"exception\":\"[object] ({$escapedClass}(code: 0): {$message} at {$escapedFile}:{$line})\n"
            ."[stacktrace]\n"
            ."#0 /var/www/site/app/Http/Controllers/PageController.php(20): secret_frame()\n"
            ."#1 {main}\n"
            ."\"} \n";
    }

    public function test_it_groups_the_same_error_about_different_records(): void
    {
        $this->log('laravel.log', implode('', [
            $this->exception('2026-09-27 09:00:00', 'Illuminate\Database\Eloquent\ModelNotFoundException', 'No query results for model [App\Models\Page] 12', 'app/Http/Controllers/PageController.php', 20),
            "[2026-09-27 09:30:00] production.INFO: Sent the newsletter.\n",
            $this->exception('2026-09-27 10:00:00', 'Illuminate\Database\Eloquent\ModelNotFoundException', 'No query results for model [App\Models\Page] 7', 'app/Http/Controllers/PageController.php', 20),
            $this->exception('2026-09-27 11:00:00', 'Illuminate\Database\Eloquent\ModelNotFoundException', 'No query results for model [App\Models\Page] 99', 'app/Http/Controllers/PageController.php', 20),
            $this->exception('2026-09-27 11:30:00', 'RuntimeException', 'The feed at 5c0e2a1e-8a1b-4c56-9d7e-1f2a3b4c5d6e timed out', 'app/Feeds/Reader.php', 44, 'CRITICAL'),
        ]));

        $summary = app(ErrorCount::class)->summary();

        $this->assertSame(4, $summary['last_day']);
        $this->assertCount(2, $summary['recent']);

        $this->assertSame([
            'level' => 'error',
            'class' => 'Illuminate\Database\Eloquent\ModelNotFoundException',
            'message' => 'No query results for model [App\Models\Page] 99',
            'file' => 'app/Http/Controllers/PageController.php',
            'line' => 20,
            'count' => 3,
            'first_seen' => '2026-09-27T09:00:00+00:00',
            'last_seen' => '2026-09-27T11:00:00+00:00',
        ], $summary['recent'][0]);

        $this->assertSame('critical', $summary['recent'][1]['level']);
        $this->assertSame('RuntimeException', $summary['recent'][1]['class']);
        $this->assertSame(1, $summary['recent'][1]['count']);
    }

    public function test_nothing_of_the_stack_trace_or_context_leaves(): void
    {
        $this->log('laravel.log', $this->exception('2026-09-27 11:00:00', 'RuntimeException', 'Boom', 'app/Boom.php', 3));

        $encoded = json_encode(app(ErrorCount::class)->recent());

        $this->assertStringNotContainsString('secret_frame', $encoded);
        $this->assertStringNotContainsString('userId', $encoded);
        $this->assertStringNotContainsString('stacktrace', $encoded);
    }

    public function test_errors_older_than_a_day_and_lesser_levels_are_left_out(): void
    {
        $this->log('laravel.log', implode('', [
            "[2026-09-26 11:59:59] production.ERROR: Yesterday's news\n",
            "[2026-09-27 11:00:00] production.WARNING: Only a warning\n",
            "[2026-09-27 11:00:00] production.DEBUG: Only debugging\n",
            "[2026-09-27 11:10:00] production.ALERT: Disk nearly full\n",
            "[2026-09-27 11:20:00] production.EMERGENCY: Disk full\n",
        ]));

        $summary = app(ErrorCount::class)->summary();

        $this->assertSame(2, $summary['last_day']);
        $this->assertEqualsCanonicalizing(['Disk nearly full', 'Disk full'], array_column($summary['recent'], 'message'));
        $this->assertNull($summary['recent'][0]['class']);
        $this->assertNull($summary['recent'][0]['file']);
        $this->assertNull($summary['recent'][0]['line']);
    }

    public function test_daily_logs_are_read_together_and_other_files_ignored(): void
    {
        $this->log('laravel-2026-09-26.log', "[2026-09-26 23:00:00] production.ERROR: Late last night\n");
        $this->log('laravel-2026-09-27.log', "[2026-09-27T08:00:00.123456+00:00] production.ERROR: Early this morning\n");
        $this->log('notes.txt', "[2026-09-27 08:00:00] production.ERROR: Not a log\n");

        $this->assertSame(2, app(ErrorCount::class)->lastDay());
    }

    public function test_it_keeps_the_ten_loudest(): void
    {
        foreach (range(1, 12) as $error) {
            foreach (range(1, $error) as $time) {
                $this->log('laravel.log', "[2026-09-27 11:00:00] production.ERROR: Error number {$error}\n");
            }
        }

        $recent = app(ErrorCount::class)->recent();

        /* "Error number 1" to "12" differ only in their number: they are one error. */
        $this->assertCount(1, $recent);
        $this->assertSame(78, $recent[0]['count']);

        File::put($this->storage.'/logs/laravel.log', '');

        foreach (range(1, 12) as $error) {
            foreach (range(1, $error) as $time) {
                $this->log('laravel.log', '[2026-09-27 11:00:00] production.ERROR: Error in '.str_repeat('x', $error)."\n");
            }
        }

        $recent = app(ErrorCount::class)->recent();

        $this->assertCount(10, $recent);
        $this->assertSame(12, $recent[0]['count']);
        $this->assertSame(3, $recent[9]['count']);
    }

    public function test_a_multi_line_exception_message_is_still_recognised(): void
    {
        $file = base_path('app/Jobs/Import.php');

        $this->log('laravel.log', "[2026-09-27 11:00:00] production.ERROR: The import failed:\nrow 4 is bad {\"exception\":\"[object] (App\\\\Exceptions\\\\ImportFailed(code: 0): The import failed:\nrow 4 is bad at {$file}:51)\n[stacktrace]\n#0 {main}\n\"}\n");

        $recent = app(ErrorCount::class)->recent();

        $this->assertSame('App\Exceptions\ImportFailed', $recent[0]['class']);
        $this->assertSame('The import failed:', $recent[0]['message']);
        $this->assertSame('app/Jobs/Import.php', $recent[0]['file']);
        $this->assertSame(51, $recent[0]['line']);
    }

    public function test_long_messages_are_cut_to_three_hundred_characters(): void
    {
        $this->log('laravel.log', '[2026-09-27 11:00:00] production.ERROR: '.str_repeat('word ', 200)."\n");

        $this->assertSame(300, mb_strlen(app(ErrorCount::class)->recent()[0]['message']));
    }

    public function test_only_the_tail_of_a_huge_log_is_read(): void
    {
        $this->log('laravel.log', "[2026-09-27 01:00:00] production.ERROR: Buried at the top\n");
        $this->log('laravel.log', str_repeat("[2026-09-27 02:00:00] production.INFO: Chatter\n", 120_000));
        $this->log('laravel.log', "[2026-09-27 11:00:00] production.ERROR: At the end\n");

        $recent = app(ErrorCount::class)->recent();

        $this->assertSame(['At the end'], array_column($recent, 'message'));
    }

    public function test_no_logs_or_unreadable_logs_are_simply_nothing(): void
    {
        $this->assertSame(['last_day' => 0, 'recent' => []], app(ErrorCount::class)->summary());

        $this->log('laravel.log', "[2026-09-27 11:00:00] production.ERROR: Hidden\n");
        chmod($this->storage.'/logs/laravel.log', 0000);

        if (is_readable($this->storage.'/logs/laravel.log')) {
            $this->markTestSkipped('Running as a user who can read anything.');
        }

        $this->assertSame(['last_day' => 0, 'recent' => []], app(ErrorCount::class)->summary());
    }

    public function test_messages_are_redacted_before_they_are_grouped(): void
    {
        $this->log('laravel.log', implode('', [
            "[2026-09-27 10:00:00] production.ERROR: Could not mail ada@example.com\n",
            "[2026-09-27 11:00:00] production.ERROR: Could not mail grace@example.org\n",
        ]));

        $recent = app(ErrorCount::class)->recent();

        $this->assertCount(1, $recent);
        $this->assertSame('Could not mail [email]', $recent[0]['message']);
        $this->assertSame(2, $recent[0]['count']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function secrets(): array
    {
        return [
            'email' => ['Unknown user ada.lovelace+test@example.co.uk', 'Unknown user [email]'],
            'password in a URL' => ['Could not connect to redis://default:hunter2@cache.internal:6379', 'Could not connect to redis://default:[redacted]@cache.internal:6379'],
            'password in a DSN' => ['SQLSTATE[HY000] mysql://forge:Pa55w0rd!@127.0.0.1/site', 'SQLSTATE[HY000] mysql://forge:[redacted]@127.0.0.1/site'],
            'bearer token' => ['Stripe refused Bearer sk_test_abc.def-123', 'Stripe refused Bearer [redacted]'],
            'JWT' => ['Bad token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl here', 'Bad token [redacted] here'],
            'password=' => ['Login failed with password=hunter2&user=ada', 'Login failed with password=[redacted]&user=ada'],
            'api_key in JSON' => ['Payload {"api_key":"live_12345","name":"Ada"}', 'Payload {"api_key":"[redacted]","name":"Ada"}'],
            'secret:' => ['client secret: s3cr3tvalue', 'client secret: [redacted]'],
            'access_token in a query' => ['GET /callback?access_token=abc123&state=x', 'GET /callback?access_token=[redacted]&state=x'],
            'long hex' => ['Signature '.str_repeat('a1b2', 16).' did not match', 'Signature [redacted] did not match'],
            'base64 secret' => ['Key base64:pLPJeyLdBjLb+tWTXtydIWtCSasdNdSO/rUQ3zSpUJ0= is wrong', 'Key base64:[redacted] is wrong'],
            'long random token' => ['Webhook whsec_4eC39HqLyjWDarjtT1zdp7dc4eC39HqLyjWD rejected', 'Webhook [redacted] rejected'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_it_redacts(string $message, string $expected): void
    {
        $this->assertSame($expected, ErrorCount::redact($message));
    }

    public function test_ordinary_messages_are_left_alone(): void
    {
        foreach ([
            'Undefined array key 12',
            'Class "App\Http\Controllers\Admin\SomeVeryLongControllerNameForTesting2" not found',
            'View [pages.home] not found in resources/views/components/layouts/application-shell2.blade.php',
            'Call to a member function format() on null',
        ] as $message) {
            $this->assertSame($message, ErrorCount::redact($message));
        }
    }

    public function test_numbers_and_identifiers_are_normalised_for_grouping(): void
    {
        $this->assertSame(
            ErrorCount::normalise('Order 12 for 5c0e2a1e-8a1b-4c56-9d7e-1f2a3b4c5d6e at 0x1f failed'),
            ErrorCount::normalise('Order 4410 for 9f0e2a1e-8a1b-4c56-9d7e-1f2a3b4c5d6f at 0xff failed'),
        );
    }
}
