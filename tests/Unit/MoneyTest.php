<?php

use App\Support\Money;

it('formatea guaraníes sin decimales y con separador de miles', function () {
    expect(Money::pyg(150000)->format())->toBe('₲ 150.000')
        ->and(Money::pyg(0)->format())->toBe('₲ 0')
        ->and(Money::pyg(-25000)->format())->toBe('-₲ 25.000');
});

it('suma y resta montos de la misma moneda', function () {
    $total = Money::pyg(100000)->plus(Money::pyg(50000))->minus(Money::pyg(30000));

    expect($total->equals(Money::pyg(120000)))->toBeTrue();
});

it('calcula porcentajes redondeando al guaraní entero', function () {
    expect(Money::pyg(150000)->percentage(20)->amount)->toBe(30000)
        ->and(Money::pyg(99999)->percentage(33.3)->amount)->toBe(33300);
});

it('rechaza monedas no soportadas', function () {
    new Money(100, 'XXX');
})->throws(InvalidArgumentException::class);
