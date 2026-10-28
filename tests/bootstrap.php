<?php

/*
 * O container de dev injeta o .env como variáveis de ambiente reais, que o Laravel
 * lê de $_SERVER antes de $_ENV. O PHPUnit só força os valores do phpunit.xml em
 * $_ENV/putenv; aqui os copiamos para $_SERVER para os testes nunca tocarem no
 * MySQL de desenvolvimento.
 */
foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
