<?php

it('la raíz redirige al panel', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('el healthcheck responde', function () {
    $this->get('/up')->assertOk();
});
