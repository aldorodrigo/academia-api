<?php

beforeEach(fn () => $this->withoutVite());

it('la raíz muestra la landing de Tuku', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Cuotas, asistencia y avisos de clase en una sola app')
        ->assertSee('Gratis hasta el 31 de octubre de 2027')
        ->assertSee(route('filament.admin.auth.register'), escape: false)
        ->assertSee('Club Jakare');
});

it('la landing muestra los tres planes con su precio y su tope de alumnos', function () {
    $this->get('/')
        ->assertSeeInOrder(['Instructores', '₲ 50.000', 'Hasta 40 alumnos'])
        ->assertSeeInOrder(['Academias', '₲ 150.000', 'Hasta 150 alumnos'])
        ->assertSeeInOrder(['Clubes, organizaciones y escuelas', '₲ 350.000', 'Hasta 500 alumnos'])
        ->assertSee('Desde noviembre de 2027');
});

it('el botón de WhatsApp aparece solo si hay número', function () {
    $this->get('/')->assertDontSee('wa.me', escape: false);

    config(['tuku.whatsapp' => '595981123456']);

    $this->get('/')->assertSee('https://wa.me/595981123456', escape: false);
});

it('el healthcheck responde', function () {
    $this->get('/up')->assertOk();
});
