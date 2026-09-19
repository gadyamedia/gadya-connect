<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/** Get help on a Laravel site without Filament. */
class PlainHelpPageTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gadya-connect.help_page', true);
    }

    /** A real site has a login page for guests to be sent to. */
    protected function defineRoutes($router): void
    {
        $router->get('/login', fn (): string => 'Log in')->name('login');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Connection::query()->create(['site_id' => 17, 'portal_url' => 'https://portal.test', 'secret' => str_repeat('s', 64)]);

        Http::fake([
            'portal.test/api/connect/v1/tickets?*' => Http::response(['data' => []]),
            'portal.test/api/connect/v1/tickets/T-1002?*' => Http::response(['data' => [
                'reference' => 'T-1002', 'subject' => 'Checkout is slow', 'status_label' => 'Received',
                'messages' => [['author' => 'Ada', 'from_team' => false, 'body' => 'It takes a minute.', 'files' => ['cart.png'], 'at' => now()->toIso8601String()]],
            ]]),
            'portal.test/api/connect/v1/tickets/T-1002/replies' => Http::response(['data' => []], 201),
            'portal.test/api/connect/v1/tickets' => Http::response(['data' => ['reference' => 'T-1002']], 201),
        ]);
    }

    public function test_a_signed_in_user_asks_for_help_and_follows_the_conversation(): void
    {
        $this->get('/gadya-connect/help')->assertRedirect('/login');

        $this->actingAs($this->admin());

        $this->get('/gadya-connect/help')->assertOk()->assertSee('Get help from Gadya Media');

        $this->post('/gadya-connect/help', [
            'type' => 'bug',
            'subject' => 'Checkout is slow',
            'body' => 'It takes a minute.',
            'files' => [UploadedFile::fake()->image('cart.png')],
        ])->assertRedirect('/gadya-connect/help/T-1002');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/tickets')
            && $request['attachments'][0]['filename'] === 'cart.png'
            && $request['requester']['email'] === 'ada@example.com');

        $this->get('/gadya-connect/help/T-1002')->assertOk()->assertSee('It takes a minute.')->assertSee('cart.png');

        $this->post('/gadya-connect/help/T-1002', ['body' => 'Still slow.'])->assertRedirect();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/T-1002/replies') && $request['body'] === 'Still slow.');
    }
}
