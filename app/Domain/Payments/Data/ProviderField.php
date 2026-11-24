<?php

namespace App\Domain\Payments\Data;

use App\Domain\Payments\Enums\Environment;

/**
 * Campo de `credentials` ou `settings` de uma conta, descrito pelo adaptador do banco.
 * É a fonte das regras de validação do cadastro e do catálogo (`GET /v1/providers`) que os
 * clientes usam para montar o formulário sem conhecer cada banco.
 */
final readonly class ProviderField
{
    public const CREDENTIALS = 'credentials';

    public const SETTINGS = 'settings';

    /**
     * @param  'credentials'|'settings'  $group
     * @param  'text'|'secret'|'file'|'integer'  $type  `file` chega em base64; credenciais nunca são devolvidas
     * @param  list<Environment>  $requiredIn  obrigatório só nestes ambientes
     * @param  list<string>  $requiredWithout  dispensado quando um destes campos (mesmo grupo) for enviado
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public string $type = 'text',
        public bool $required = false,
        public array $requiredIn = [],
        public array $requiredWithout = [],
        public ?int $max = null,
        public ?string $description = null,
        public ?string $placeholder = null,
        public ?string $accept = null,
    ) {}

    public static function credential(string $key, string $label, string $type = 'secret', mixed ...$options): self
    {
        return new self($key, self::CREDENTIALS, $label, $type, ...$options);
    }

    public static function setting(string $key, string $label, string $type = 'text', mixed ...$options): self
    {
        return new self($key, self::SETTINGS, $label, $type, ...$options);
    }

    /**
     * Regras do campo. `$environment` é o ambiente da conta (ou o enviado); em atualização
     * parcial as obrigatoriedades viram `sometimes`, pois o valor já está no gateway.
     */
    public function rules(?Environment $environment, bool $partial): array
    {
        $type = $this->type === 'integer' ? ['integer'] : ['string'];
        $max = $this->max ? ["max:{$this->max}"] : [];

        if ($partial) {
            return ['sometimes', $this->required ? 'required' : 'nullable', ...$type, ...$max];
        }

        $required = $this->required
            || ($this->requiredIn !== [] && $environment !== null && in_array($environment, $this->requiredIn, true));

        if (! $required) {
            return ['nullable', ...$type, ...$max];
        }

        if ($this->requiredWithout !== []) {
            $others = implode(',', array_map(fn ($key) => "{$this->group}.{$key}", $this->requiredWithout));

            return ["required_without_all:{$others}", 'nullable', ...$type, ...$max];
        }

        return ['required', ...$type, ...$max];
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'required_in' => array_map(fn (Environment $env) => $env->value, $this->requiredIn),
            'required_without' => $this->requiredWithout,
            'max' => $this->max,
            'description' => $this->description,
            'placeholder' => $this->placeholder,
            'accept' => $this->accept,
        ];
    }
}
