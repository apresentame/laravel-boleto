<?php

namespace Eduardokum\LaravelBoleto\Contracts\Cnab;

interface Pagamento extends Cnab
{
    public function gerar();
}
