<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Filament\Pages\GetHelp;
use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\RequestSignature;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class GetHelpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Connection::query()->create(['site_id' => 17, 'portal_url' => 'https://portal.test', 'secret' => str_repeat('s', 64)]);

        Http::fake([
            'portal.test/api/connect/v1/tickets?*' => Http::response(['data' => [
                ['reference' => 'T-1001', 'subject' => 'Photo will not upload', 'status' => 'in_progress', 'status_label' => 'Being worked on'],
            ]]),
            'portal.test/api/connect/v1/tickets/T-1001?*' => Http::response(['data' => [
                'reference' => 'T-1001', 'subject' => 'Photo will not upload', 'status_label' => 'Being worked on',
                'messages' => [
                    ['author' => 'Ada', 'from_team' => false, 'body' => 'The gallery says error.', 'files' => [], 'at' => now()->toIso8601String()],
                    ['author' => 'Antony', 'from_team' => true, 'body' => 'Fixed: try again.', 'files' => [], 'at' => now()->toIso8601String()],
                ],
            ]]),
            'portal.test/api/connect/v1/tickets/T-1001/replies' => Http::response(['data' => []], 201),
            'portal.test/api/connect/v1/tickets' => Http::response(['data' => ['reference' => 'T-1002']], 201),
        ]);
    }

    public function test_anyone_in_the_admin_can_ask_for_help_with_a_screenshot(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/get-help')->assertOk()->assertSee('What do you need?')->assertSee('Photo will not upload')->assertSee('Get help');

        Livewire::test(GetHelp::class)
            ->set('type', 'bug')
            ->set('subject', 'Menu is missing')
            ->set('body', 'The menu vanished on phones.')
            ->set('files', [UploadedFile::fake()->image('menu.png')])
            ->call('send')
            ->assertSet('open', 'T-1002');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://portal.test/api/connect/v1/tickets'
                && $request['requester']['email'] === 'ada@example.com'
                && $request['type'] === 'bug'
                && $request['attachments'][0]['filename'] === 'menu.png'
                && $request->hasHeader(RequestSignature::SIGNATURE_HEADER);
        });
    }

    public function test_the_conversation_reads_back_and_takes_a_reply(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(GetHelp::class, ['open' => 'T-1001'])
            ->assertSee('Fixed: try again.')
            ->set('reply', 'Works now, thanks!')
            ->call('sendReply')
            ->assertHasNoErrors();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/T-1001/replies')
            && $request['body'] === 'Works now, thanks!'
            && $request['author']['email'] === 'ada@example.com');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'T-1001?email=')) {
                return false;
            }

            $expected = RequestSignature::sign(str_repeat('s', 64), (int) $request->header(RequestSignature::TIMESTAMP_HEADER)[0], $request->header(RequestSignature::NONCE_HEADER)[0], 'GET', '/api/connect/v1/tickets/T-1001?email=ada%40example.com', '');

            return hash_equals($expected, $request->header(RequestSignature::SIGNATURE_HEADER)[0]);
        });
    }

    public function test_an_unconnected_site_offers_the_help_address_instead(): void
    {
        Connection::query()->delete();
        $this->actingAs($this->admin());

        $this->get('/admin/get-help')->assertOk()->assertSee('help@support.gadya.media');
    }
}
