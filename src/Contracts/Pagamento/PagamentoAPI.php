<?php

namespace Eduardokum\LaravelBoleto\Contracts\Pagamento;

use Eduardokum\LaravelBoleto\Pagamento\AbstractPagamento;

interface PagamentoAPI extends Pagamento
{
    /**
     * Return boleto as a Array.
     *
     * @return array
     */
    public function toAPI();

    /**
     * @param $boleto
     * @param $appends
     *
     * @return AbstractPagamento
     */
    public static function fromAPI($boleto, $appends);
}
