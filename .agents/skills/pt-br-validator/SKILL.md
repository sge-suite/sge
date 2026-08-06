---
name: pt-br-validator
description: "Use this skill when validating Brazilian data formats such as CPF, CNPJ, Placa de Carro, CEP, Telefone, Celular, etc. Triggers when working with form validation involving Brazilian documents, phone numbers, addresses (CEP), or states (UF)."
license: MIT
metadata:
  author: LaravelLegends
---

# pt-br-validator: Validações brasileiras para Laravel

This skill helps with Brazilian validation rules in Laravel using the `laravellegends/pt-br-validator` library.
Use these rules when you need to validate Brazilian specific formats instead of writing custom Regex.

## Available Rules

The following validation rules are available to use as strings (e.g. `'cpf'`, `'celular_com_ddd'`):

- `celular`: Validates if the field is in the format (`99999-9999` or `9999-9999`).
- `celular_com_ddd`: Validates format (`(99)99999-9999`, `(99)9999-9999`, `(99) 99999-9999`, or `(99) 9999-9999`).
- `celular_com_codigo`: Validates format `+99(99)99999-9999` or `+99(99)9999-9999`.
- `cnpj`: Validates if the field is a valid CNPJ (checks digits).
- `cpf`: Validates if the field is a valid CPF (checks digits).
- `cns`: Validates if the field is a valid CNS (checks digits).
- `formato_cnpj`: Validates if the field has a correct CNPJ mask (`99.999.999/9999-99`).
- `formato_cpf`: Validates if the field has a correct CPF mask (`999.999.999-99`).
- `formato_cep`: Validates if the field has a correct CEP mask (`99999-999` or `99.999-999`).
- `telefone`: Validates if the field has a telephone mask (`9999-9999`).
- `telefone_com_ddd`: Validates if the field has a telephone with DDD mask (`(99)9999-9999`).
- `telefone_com_codigo`: Validates if the field has a telephone with country code mask (`+55(99)9999-9999`).
- `formato_placa_de_veiculo`: Validates if the field has a valid vehicle license plate format (including Mercosur standard).
- `formato_pis`: Validates if the field has a PIS format.
- `pis`: Validates if the PIS is valid (checks digits).
- `cpf_ou_cnpj`: Validates if the field is a valid CPF or CNPJ.
- `formato_cpf_ou_cnpj`: Validates if the field contains a valid CPF or CNPJ format mask.
- `uf`: Validates if the field contains a valid State acronym (UF).

## Usage Examples

### Using Validator::make

```php
$validator = \Validator::make(
    ['telefone' => '(77)9999-3333'],
    ['telefone' => 'required|telefone_com_ddd']
);
```

### Using Request validation or Form Requests

```php
use Illuminate\Http\Request;

Route::get('testando', function (Request $request) {
    $dados = $request->validate([
        'telefone' => 'required|telefone',
        'cpf' => 'required|cpf',
        'cnpj' => 'nullable|cnpj',
    ]);
});
```

### Customizing Messages

You can override the default error messages either directly in the `Validator::make` call or in a Form Request `messages()` method.

```php
Validator::make($valor, $regras, ['celular_com_ddd' => 'O campo :attribute não é um celular válido']);
```

In a Form Request:
```php
public function messages() {
    return [
        'telefone.telefone' => 'Telefone inválido!',
        'cpf.cpf' => 'O CPF informado é inválido.',
    ];
}
```

### Using Rule Classes Directly

You can use the rule classes directly instead of string rules. This is particularly useful for IDE autocompletion and strong typing, or when combining with Laravel's `Rule` object.

Available classes in the `LaravelLegends\PtBrValidator\Rules` namespace:
- `Celular`
- `CelularComDdd`
- `CelularComCodigo`
- `Cnh`
- `Cnpj`
- `Cpf`
- `Cns`
- `FormatoCnpj`
- `FormatoCpf`
- `Telefone`
- `TelefoneComDdd`
- `TelefoneComCodigo`
- `FormatoCep`
- `FormatoPlacaDeVeiculo`
- `FormatoPis`
- `Pis`
- `CpfOuCnpj`
- `FormatoCpfOuCnpj`
- `Uf`

Example usage:

```php
use Illuminate\Http\Request;
use LaravelLegends\PtBrValidator\Rules\FormatoCpf;

Route::get('testando', function (Request $request) {
    $dados = $request->validate([
        'cpf'  => ['required', new FormatoCpf]
    ]);
});
```
