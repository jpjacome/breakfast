<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root URL currently forwards to the portal, which in turn bounces
     * guests to the login screen. This changes once the marketing site
     * (home, servicios, blog) is built.
     */
    public function test_the_root_url_sends_guests_to_the_login_screen(): void
    {
        $this->get('/')
            ->assertRedirect('/portal');

        $this->get('/portal')
            ->assertRedirect('/login');
    }
}
