<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // $this->authorize(...) nos controllers: toda entidade da casa passa
    // por Policy (invariante 4 do modelo de domínio).
    use AuthorizesRequests;
}
