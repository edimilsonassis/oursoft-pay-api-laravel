# AGENTS.md — OurSoft Pay

Gateway de pagamentos independente (Laravel 13, PHP 8.4, MySQL próprio). Não acoplar a
nada do CFC: ele é só um cliente via chave de API.

## Arquitetura

- `app/Domain/Payments`: enums, contrato `PaymentProvider`, objetos de retorno
  (`ProviderCharge`, `ProviderRefund`, `CardData`) e exceções (`PaymentException` → 422,
  `ProviderException` → 502).
- `app/Infrastructure/Providers`: adaptadores dos bancos. Cada provedor (`EfiProvider`,
  `SicoobProvider`) compõe `ChargeGateway`s por método. O Pix dos dois segue o padrão
  Bacen em `Pix/BacenPixGateway` (as subclasses só definem URL e autenticação).
  `ProviderManager` resolve o adaptador pelo enum `Provider`.
- `app/Application/*/Actions`: casos de uso. **Toda** mudança de estado vinda do banco
  passa por `ApplyProviderChargeAction`, que grava, registra o evento e dispara o webhook
  do cliente.
- `app/Http`: controllers finos, `FormRequest` → DTO, presenters para o JSON.
- Novo banco: criar um `XProvider extends AbstractProvider` + gateways (com `label()`,
  `methods()` e `fields()`), adicionar o caso no enum `Provider` e no `ProviderManager`, e
  cobrir com testes usando `Http::fake`. `fields()` (`ProviderField`) é a fonte única da
  validação da conta e do catálogo `GET /v1/providers`, que os clientes usam para montar o
  formulário: um banco novo não exige mudança nos clientes.

## Regras

- Dinheiro sempre em centavos (`int`). Converter para decimal só na borda do banco (`Support\Money`).
- Nunca confiar no conteúdo de webhook de banco: extrair referências e reconsultar a cobrança.
- Credenciais só via cast `encrypted:array`. Nunca logar, devolver na API ou gravar em claro.
- Não receber nem guardar número de cartão. Só tokens do banco.
- Greenfield: ajustar migrations diretamente em vez de empilhar patches.

## Testes

`docker exec pay-api php artisan test` (SQLite em memória, `RefreshDatabase`,
`Http::preventStrayRequests`). Helpers em `Tests\TestCase`: `createClient()`,
`createAccount(Provider)`, `withKey()`, `payer()`, `pem()`. Toda mudança de comportamento
precisa de teste de feature.
