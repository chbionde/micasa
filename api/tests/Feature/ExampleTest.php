<?php

it('responde a raiz com sucesso', function () {
    $this->get('/')->assertStatus(200);
});
