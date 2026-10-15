<?php

namespace Eduardokum\LaravelBoleto\Tests\Retorno;

use Carbon\Carbon;
use Eduardokum\LaravelBoleto\Pessoa;
use Eduardokum\LaravelBoleto\Tests\TestCase;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Factory;
use Eduardokum\LaravelBoleto\Cnab\Retorno\FakeRetorno;
use Eduardokum\LaravelBoleto\Boleto\Banco\Caixa as BoletoCaixa;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Cnab240\Banco\Caixa as RetornoCnab240;
use Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab240\Banco\Caixa as RemessaCnab240;

/**
 * Round-trip do retorno fake da Caixa em CNAB240: gera a remessa pela lib, transforma em retorno
 * pelo FakeRetorno e lê de volta pelo parser da própria lib. Na Caixa o nosso número e o número
 * do documento mudam de faixa entre o segmento P e o segmento T, então é o que garante que o
 * gerador grava onde o parser lê.
 */
class CaixaFakeRetornoTest extends TestCase
{
    protected static $pagador;

    protected static $beneficiario;

    /** Número com 15 dígitos distintos: qualquer truncamento do nosso número aparece no fim. */
    const NUMERO = 123456789012345;

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

    private function boleto()
    {
        return new BoletoCaixa([
            'agencia'         => 1111,
            'conta'           => 123456,
            'carteira'        => 'RG',
            'codigoCliente'   => 999999,
            'numero'          => self::NUMERO,
            'numeroDocumento' => 1023,
            'numeroControle'  => 98765,
            'dataVencimento'  => new Carbon('2026-03-10'),
            'dataDocumento'   => new Carbon('2026-02-10'),
            'valor'           => 150.00,
            'especieDoc'      => 'DM',
            'aceite'          => 'N',
            'beneficiario'    => self::$beneficiario,
            'pagador'         => self::$pagador,
        ]);
    }

    /**
     * Gera a remessa CNAB240 da Caixa e devolve o caminho do arquivo
     */
    private function remessa()
    {
        $remessa = new RemessaCnab240([
            'agencia'       => 1111,
            'conta'         => 123456,
            'carteira'      => 'RG',
            'codigoCliente' => 999999,
            'idremessa'     => 1,
            'beneficiario'  => self::$beneficiario,
        ]);
        $remessa->addBoleto($this->boleto());

        $path = tempnam(sys_get_temp_dir(), 'caixa') . '.rem';
        $remessa->save($path);

        return $path;
    }

    /**
     * Escreve o retorno fake num arquivo e devolve o primeiro detalhe lido pelo parser
     */
    private function detalhe($spec)
    {
        $path = tempnam(sys_get_temp_dir(), 'caixa') . '.ret';
        file_put_contents($path, FakeRetorno::gerar('104', '240', $this->remessa(), $spec));

        $retorno = Factory::make($path);
        $this->assertInstanceOf(RetornoCnab240::class, $retorno);

        return $retorno->getDetalhes()->first();
    }

    /**
     * Todas as linhas precisam ter exatamente 240 posições
     */
    public function testTamanhoDasLinhas()
    {
        $conteudo = FakeRetorno::gerar('104', '240', $this->remessa());

        foreach (explode("\r\n", rtrim($conteudo, "\r\n")) as $linha) {
            $this->assertEquals(240, strlen($linha));
        }
    }

    /**
     * A entrada confirmada devolve controle, documento, nosso número completo, vencimento e valor
     */
    public function testEntradaPreservaOsDadosDoTitulo()
    {
        $detalhe = $this->detalhe('confirm');

        $this->assertEquals('02', $detalhe->getOcorrencia());
        $this->assertEquals($detalhe::OCORRENCIA_ENTRADA, $detalhe->getOcorrenciaTipo());
        $this->assertEquals('98765', $detalhe->getNumeroControle());
        $this->assertEquals('1023', $detalhe->getNumeroDocumento());
        // O segmento P grava o nosso número em 18 posições com zero à esquerda; nenhum dígito pode faltar
        $this->assertEquals($this->boleto()->getNossoNumero(), ltrim($detalhe->getNossoNumero(), '0'));
        $this->assertEquals('10/03/2026', $detalhe->getDataVencimento());
        $this->assertEquals('150.00', $detalhe->getValor());
    }

    /**
     * A liquidação preenche valor recebido e data de crédito no segmento U
     */
    public function testLiquidacaoTrazValorRecebido()
    {
        $detalhe = $this->detalhe('pay');

        $this->assertEquals('06', $detalhe->getOcorrencia());
        $this->assertEquals($detalhe::OCORRENCIA_LIQUIDADA, $detalhe->getOcorrenciaTipo());
        $this->assertEquals('98765', $detalhe->getNumeroControle());
        $this->assertEquals('150.00', $detalhe->getValorRecebido());
        // Tarifa = valor pago - valor líquido: o fake liquida sem tarifa
        $this->assertEquals(0, (float) $detalhe->getValorTarifa());
        $this->assertEquals(date('d/m/Y'), $detalhe->getDataCredito());
    }

    /**
     * A rejeição carrega o motivo default do profile (63) lido da tabela da Caixa
     */
    public function testRejeicaoTrazOMotivo()
    {
        $detalhe = $this->detalhe('reject');

        $this->assertEquals('03', $detalhe->getOcorrencia());
        $this->assertEquals($detalhe::OCORRENCIA_ERRO, $detalhe->getOcorrenciaTipo());
        $this->assertTrue($detalhe->hasError());
        $this->assertStringContainsString('Entrada para Título já Cadastrado', $detalhe->getError());
    }
}
