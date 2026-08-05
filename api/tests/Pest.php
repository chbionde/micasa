<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest — configuração base
|--------------------------------------------------------------------------
| Testes em Feature/ ganham o TestCase do Laravel (app bootado, HTTP fake,
| banco etc.). Testes em Unit/ ficam puros — sem framework, mais rápidos.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
