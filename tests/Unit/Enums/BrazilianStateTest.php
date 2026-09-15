<?php

use App\Enums\BrazilianState;
use Tests\TestCase;

uses(TestCase::class);

test('defines Brazilian state cases, values, labels and options', function () {
    expect(BrazilianState::cases())->toBe([
        BrazilianState::Acre,
        BrazilianState::Alagoas,
        BrazilianState::Amapa,
        BrazilianState::Amazonas,
        BrazilianState::Bahia,
        BrazilianState::Ceara,
        BrazilianState::DistritoFederal,
        BrazilianState::EspiritoSanto,
        BrazilianState::Goias,
        BrazilianState::Maranhao,
        BrazilianState::MatoGrosso,
        BrazilianState::MatoGrossoDoSul,
        BrazilianState::MinasGerais,
        BrazilianState::Para,
        BrazilianState::Paraiba,
        BrazilianState::Parana,
        BrazilianState::Pernambuco,
        BrazilianState::Piaui,
        BrazilianState::RioDeJaneiro,
        BrazilianState::RioGrandeDoNorte,
        BrazilianState::RioGrandeDoSul,
        BrazilianState::Rondonia,
        BrazilianState::Roraima,
        BrazilianState::SantaCatarina,
        BrazilianState::SaoPaulo,
        BrazilianState::Sergipe,
        BrazilianState::Tocantins,
    ])
        ->and(BrazilianState::values())->toBe([
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
            'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
        ])
        ->and(BrazilianState::options())->toBe([
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins',
        ])
        ->and(BrazilianState::Acre->label())->toBe('Acre')
        ->and(BrazilianState::Amapa->label())->toBe('Amapá')
        ->and(BrazilianState::DistritoFederal->label())->toBe('Distrito Federal')
        ->and(BrazilianState::MatoGrossoDoSul->label())->toBe('Mato Grosso do Sul')
        ->and(BrazilianState::SaoPaulo->label())->toBe('São Paulo')
        ->and(BrazilianState::Tocantins->label())->toBe('Tocantins');
});
