# OurSoft Pay

Gateway de pagamentos da OurSoft: uma **API única** para Pix, boleto e cartão,
independente do banco que processa a cobrança. Bancos suportados: **Efí** (antiga
Gerencianet) e **Sicoob**.

- Produção: `https://pay.oursoft.com.br`
- Dev (monorepo CFC): `https://dev-pay.oursoft.com.br` (container `pay-api`)

Aplicação Laravel 13 isolada, com banco MySQL próprio. Não compartilha código nem
banco com o CFC: os sistemas do CFC são apenas **clientes** dela.

## Conceitos

| Conceito | O que é |
|---|---|
| **Cliente** | Aplicação que consome o gateway (ex.: API do CFC). Autentica com chave de API e recebe webhooks. |
| **Chave de API** | `osp_…`, enviada em `Authorization: Bearer` ou `X-Api-Key`. Só o hash SHA-256 é guardado. |
| **Conta** (`account`) | Credenciais de um banco (Efí/Sicoob) pertencentes a um cliente. Um cliente pode ter várias, por exemplo uma por CFC. Credenciais são criptografadas com a `APP_KEY` e nunca são devolvidas. |
| **Cobrança** (`payment`) | Pix, boleto ou cartão criado numa conta. Valores sempre em **centavos**. |

## Matriz de suporte

| Método | Efí | Sicoob |
|---|---|---|
| `pix` | ✅ (requer certificado + `pix_key`) | ✅ (requer `pix_key`) |
| `boleto` | ✅ | ✅ (requer `numero_cliente`, `numero_conta_corrente` e endereço do pagador) |
| `credit_card` | ✅ (requer `payee_code`; token gerado no front) | ❌ |
| `debit_card` | ❌ | ❌ |

`debit_card` já faz parte do contrato, mas nenhum dos dois bancos oferece débito via
API. Pedir um método não suportado pela conta retorna `422 method_not_supported`.
`GET /v1/accounts/{id}` informa os `supported_methods` de cada conta.

## Administração (CLI)

```bash
docker exec pay-api php artisan pay:client:create "CFC API" --webhook-url=https://cfc-api.oursoft.com.br/api/webhooks/pay
docker exec pay-api php artisan pay:key:issue <client_id> --name=producao
docker exec pay-api php artisan pay:key:revoke osp_xxxxxxxx
docker exec pay-api php artisan pay:client:list
```

`pay:client:create` exibe uma única vez a chave de API e o `webhook_secret`.

## API v1

Respostas de sucesso: `{ "data": … }`. Erros: `{ "error": { "code", "message", … } }`.

| Código | HTTP | Quando |
|---|---|---|
| `unauthenticated` | 401 | chave ausente, inválida ou revogada |
| `validation_error` | 422 | campos inválidos (`error.fields`) |
| `method_not_supported`, `invalid_payment_state`, `invalid_refund_amount`, `refund_not_supported`, `payer_address_required`, `account_not_found`, `account_misconfigured` | 422 | regra de negócio |
| `provider_error` | 502 | o banco recusou ou falhou (`provider`, `provider_status`, `provider_response`) |
| `not_found` | 404 | recurso inexistente ou de outro cliente |

### Bancos

`GET /v1/providers` lista os bancos suportados com `methods`, `environments` e `fields`:
os campos de `credentials` e `settings` de cada banco (`key`, `group`, `label`, `type`
= `text|secret|file|integer`, `required`, `required_in` = ambientes em que é obrigatório,
`required_without` = dispensado se outro campo do grupo for enviado, `max`, `description`,
`placeholder`, `accept`). O cliente monta o formulário de conta a partir disso; as mesmas
definições geram a validação de `POST /v1/accounts`.

### Contas

```http
POST /v1/accounts
{
  "provider": "efi",                 // efi | sicoob
  "environment": "production",       // sandbox | production
  "name": "CFC Centro",
  "external_reference": "tenant-42", // id no sistema do cliente (opcional)
  "is_default": true,
  "credentials": {
    "client_id": "...", "client_secret": "...",
    "certificate": "<base64 do .p12/.pfx ou PEM>", "certificate_password": "..."
  },
  "settings": { "pix_key": "...", "payee_code": "..." }
}
```

- Efí: `credentials.client_id`, `client_secret`, `certificate` (só Pix); `settings.pix_key`, `payee_code` (cartão).
- Sicoob: `credentials.client_id`, `certificate` (produção) ou `sandbox_token` (sandbox); `settings.pix_key`, `numero_cliente`, `numero_conta_corrente`, `codigo_modalidade` (padrão 1).

`GET /v1/accounts`, `GET|PATCH|DELETE /v1/accounts/{id}` (o PATCH é parcial e mescla
credenciais; o DELETE apenas desativa). `POST /v1/accounts/{id}/webhooks` registra no
banco a URL de notificação Pix da conta. A URL é devolvida em `webhook_url`.

### Cobranças

```http
POST /v1/payments
Idempotency-Key: mensalidade-2026-10-aluno-55
{
  "method": "pix",                    // pix | boleto | credit_card | debit_card
  "amount": 15000,                    // centavos
  "account_id": "01k…",               // opcional: usa a conta padrão
  "description": "Mensalidade outubro",
  "external_reference": "charge-10",
  "payer": {
    "name": "Maria da Silva", "document": "123.456.789-09",
    "email": "maria@example.com", "phone": "11988887777", "birth_date": "1990-05-01",
    "address": { "street": "Rua A", "number": "10", "district": "Centro",
                 "city": "São Paulo", "state": "SP", "zip_code": "01001-000" }
  },
  "pix":    { "expires_in": 3600 },
  "boleto": { "due_date": "2026-11-10", "instructions": "Não receber após 30 dias" },
  "card":   { "token": "<payment_token do banco>", "installments": 3 },
  "metadata": { "student_id": 55 }
}
```

`payer` é obrigatório para boleto e cartão. A resposta traz o bloco do método:

- `pix`: `qr_code` (copia-e-cola), `qr_code_image` (Efí), `txid`, `end_to_end_id`
- `boleto`: `digitable_line`, `barcode`, `pdf_url`, `due_date`, `pix_qr_code` (boleto híbrido)
- `card`: `installments`, `installment_value`, `mask`

O cabeçalho `Idempotency-Key` é recomendado: repetir a mesma chave devolve a cobrança
já criada (HTTP 200) em vez de criar outra.

| Endpoint | Ação |
|---|---|
| `GET /v1/payments` | lista, com filtros `status` (vírgula), `method`, `account_id`, `external_reference`, `created_from`, `created_to`, `per_page` |
| `GET /v1/payments/{id}` | consulta |
| `POST /v1/payments/{id}/cancel` | cancela uma cobrança em aberto (Pix removido, boleto baixado) |
| `POST /v1/payments/{id}/sync` | reconsulta no banco |
| `POST /v1/payments/{id}/refunds` | estorno total ou parcial `{ "amount"?, "reason"? }` (Pix e cartão) |
| `GET /v1/payments/{id}/boleto.pdf` | PDF do boleto, quando o banco devolve o arquivo (Sicoob) |

**Status:** `pending` → `waiting_payment` → `paid` (ou `authorized` no cartão),
`partially_refunded`, `refunded`, `canceled`, `expired`, `failed`, `chargeback`.

### Cartão (tokenização)

O gateway **nunca recebe o número do cartão**. O front gera o `payment_token` com a
biblioteca JS da Efí usando o `payee_code` e o ambiente da conta (`GET /v1/accounts/{id}`),
e envia esse token em `card.token`.

## Webhooks para o cliente

A cada mudança de status, o gateway faz um `POST` no `webhook_url` do cliente:

```json
{ "id": "01k…", "event": "payment.paid", "created_at": "…", "data": { …cobrança… } }
```

Cabeçalhos: `X-OurSoft-Pay-Event`, `X-OurSoft-Pay-Delivery` e
`X-OurSoft-Pay-Signature: t=<unix>,v1=<hex>`, em que
`v1 = HMAC-SHA256(webhook_secret, "<t>.<corpo bruto>")`. Valide a assinatura e
recuse `t` muito antigo. Respostas fora de 2xx são retentadas (10 tentativas,
backoff de até 8 h). Use `data.id` para deduplicar.

## Notificações dos bancos

`POST /webhooks/{account}/{token}/{sufixo?}`: a URL carrega um token secreto por conta.
A notificação é gravada (`inbound_webhooks`) e processada na fila. O gateway **sempre
reconsulta a cobrança no banco** e nunca confia no conteúdo da notificação. O comando
`pay:sync-payments`, executado a cada 5 min pelo agendador, cobre notificações perdidas e
o boleto do Sicoob, que não tem notificação configurada.

## Desenvolvimento

O dev roda pelo `docker-compose.yml` da raiz do monorepo CFC, com os serviços
`pay-api`, `pay-worker`, `pay-scheduler` e `pay-mysql`:

```bash
docker compose up -d pay-mysql pay-api pay-worker pay-scheduler
docker exec pay-api php artisan migrate
docker exec pay-api php artisan test
docker exec pay-api vendor/bin/pint
```

Os testes usam SQLite em memória (`tests/bootstrap.php` impede que o `.env` do
container os aponte para o MySQL) e `Http::fake` para os bancos.

## Produção (servidor próprio)

```bash
cp .env.example .env   # preencher APP_KEY, DB_* e MYSQL_*
docker compose -f docker-compose.prod.yml up -d --build
```

A **`APP_KEY` precisa ser fixa e ter backup**: ela criptografa as credenciais bancárias.
Trocá-la torna todas as contas ilegíveis. O container web roda as migrations
(`RUN_MIGRATIONS=true`), e a fila e o agendador usam a mesma imagem.
