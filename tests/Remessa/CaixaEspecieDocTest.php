<?php

namespace Eduardokum\LaravelBoleto\Tests\Remessa;

use Carbon\Carbon;
use Eduardokum\LaravelBoleto\Pessoa;
use Eduardokum\LaravelBoleto\Tests\TestCase;
use Eduardokum\LaravelBoleto\Boleto\Banco\Caixa as BoletoCaixa;
use Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Caixa as RemessaCaixa240;
use Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Caixa as RemessaCaixa400;

class CaixaEspecieDocTest extends TestCase
{
    protected static $pagador;

    protected static $beneficiario;

    public static function setUpBeforeClass(): void
    {
        self::$beneficiario = new Pessoa([
            'nome'      => 'BENEFICIARIO LTDA',
            'endereco'  => 'Rua um, 123',
            'cep'       => '85000-000',
            'uf'        => 'PR',
            'cidade'    => 'CIDADE',
            'documento' => '11.643.817/0001-02',
        ]);

        self::$pagador = new Pessoa([
            'nome'      => 'PAGADOR TESTE',
            'endereco'  => 'Rua dois, 456',
            'bairro'    => 'CENTRO',
            'cep'       => '85010-000',
            'uf'        => 'PR',
            'cidade'    => 'CIDADE',
            'documento' => '123.456.789-09',
        ]);
    }

    private function boleto($especieDoc)
    {
        return new BoletoCaixa([
            'agencia'         => 1111,
            'conta'           => 123456,
            'carteira'        => 'RG',
            'codigoCliente'   => 999999,
            'numero'          => 1,
            'numeroDocumento' => 1,
            'dataVencimento'  => new Carbon('2026-03-10'),
            'dataDocumento'   => new Carbon('2026-02-10'),
            'valor'           => 150.00,
            'especieDoc'      => $especieDoc,
            'aceite'          => 'N',
            'beneficiario'    => self::$beneficiario,
            'pagador'         => self::$pagador,
        ]);
    }

    private function remessaParams()
    {
        return [
            'agencia'       => 1111,
            'conta'         => 123456,
            'carteira'      => 'RG',
            'codigoCliente' => 999999,
            'idremessa'     => 1,
            'beneficiario'  => self::$beneficiario,
        ];
    }

    private function linhas($remessa)
    {
        return explode("\r\n", rtrim($remessa->gerar(), "\r\n"));
    }

    // No CNAB 240 da Caixa (C015) DM é 02; 01 seria Cheque
    public function testEspecieDuplicataMercantilCnab240()
    {
        $remessa = new RemessaCaixa240($this->remessaParams());
        $remessa->addBoleto($this->boleto('DM'));
        $segmentoP = $this->linhas($remessa)[2];

        $this->assertEquals('P', substr($segmentoP, 13, 1));
        $this->assertEquals('02', substr($segmentoP, 106, 2));
    }

    // No CNAB 400 da Caixa DM continua 01
    public function testEspecieDuplicataMercantilCnab400()
    {
        $remessa = new RemessaCaixa400($this->remessaParams());
        $remessa->addBoleto($this->boleto('DM'));
        $detalhe = $this->linhas($remessa)[1];

        $this->assertEquals('1', substr($detalhe, 0, 1));
        $this->assertEquals('01', substr($detalhe, 147, 2));
    }
}
