<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Smoke test: a aplicação responde. A raiz não é página, é porta de
     * entrada — quem não está logado vai para o login.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
