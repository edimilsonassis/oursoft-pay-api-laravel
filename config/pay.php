<?php

return [
    /*
    | Timeout (s) das chamadas às APIs dos bancos.
    */
    'http_timeout' => (int) env('PAY_HTTP_TIMEOUT', 30),

    /*
    | Validade padrão (s) do QR Code Pix quando o cliente não informa pix.expires_in.
    */
    'pix_default_expiration' => (int) env('PAY_PIX_DEFAULT_EXPIRATION', 86400),

    /*
    | Requisições por minuto por cliente na API v1.
    */
    'rate_limit' => (int) env('PAY_RATE_LIMIT', 600),
];
