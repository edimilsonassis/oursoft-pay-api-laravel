<?php

namespace App\Domain\Payments\Enums;

enum PaymentStatus: string
{
    /** Criada localmente, ainda não registrada no banco. */
    case Pending = 'pending';
    /** Registrada no banco, aguardando o pagador (Pix/boleto em aberto, cartão em análise). */
    case WaitingPayment = 'waiting_payment';
    /** Cartão aprovado, aguardando confirmação/captura. */
    case Authorized = 'authorized';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Failed = 'failed';
    /** Cartão contestado pelo portador. */
    case Chargeback = 'chargeback';

    public function isFinal(): bool
    {
        return in_array($this, [self::Refunded, self::Canceled, self::Expired, self::Failed, self::Chargeback], true);
    }

    public function isCancelable(): bool
    {
        return in_array($this, [self::Pending, self::WaitingPayment, self::Authorized], true);
    }

    public function isRefundable(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    /** Situações que ainda podem mudar no banco e por isso são reconsultadas periodicamente. */
    public function needsSync(): bool
    {
        return in_array($this, [self::WaitingPayment, self::Authorized], true);
    }
}
