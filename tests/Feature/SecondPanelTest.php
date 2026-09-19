<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Tests\Fixtures\OtherPanelProvider;
use Gadya\Connect\Tests\TestCase;

/** A site with a second Filament panel that has no Get help page. */
class SecondPanelTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), OtherPanelProvider::class];
    }

    public function test_the_help_button_only_shows_on_the_panel_that_has_the_page(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/gadya-support')->assertOk()->assertSee('data-gadya-help', false);
        $this->get('/other')->assertOk()->assertDontSee('data-gadya-help', false);
    }
}
