<?php

namespace Eduardokum\LaravelBoleto\Tests\Remessa;

use Carbon\Carbon;
use Eduardokum\LaravelBoleto\Pessoa;
use Eduardokum\LaravelBoleto\Tests\TestCase;
use Eduardokum\LaravelBoleto\Pagamento\Banco\Banco as Pagamento;
use Eduardokum\LaravelBoleto\Cnab\Pagamento\Cnab240\Banco\Bancoob;

/**
 * G029 (pág. 40 do guia Sicoob CNAB 240) não aceita código de TED (41/43) quando o crédito é
 * interno à cooperativa: um favorecido cuja conta de destino também está no Sicoob (756) exige
 * o código 01 (crédito em conta corrente). Antes desta correção, PaymentBatchService nunca
 * chamava setFormaLancamento(), e Bancoob.php aplicava um único valor (o default histórico
 * '41') a todos os favorecidos do lote — inclusive os do próprio 756, que o Sicoob recusava.
 */
class BancoobPagamentoCnab240Test extends TestCase
{
    protected static $pagador;

    public static function setUpBeforeClass(): void
    {
        self::$pagador = new Pessoa([
            'nome'      => 'EMPRESA PAGADORA LTDA',
            'endereco'  => 'Rua um, 123',
            'cep'       => '85000-000',
            'uf'        => 'PR',
            'cidade'    => 'CIDADE',
            'documento' => '11.643.817/0001-02',
        ]);
    }

    private function beneficiario(array $params = [])
    {
        return new Pessoa(array_merge([
            'nome'      => 'FAVORECIDO TESTE',
            'endereco'  => 'Rua dois, 456',
            'bairro'    => 'CENTRO',
            'cep'       => '85010-000',
            'uf'        => 'PR',
            'cidade'    => 'CIDADE',
            'documento' => '123.456.789-09',
        ], $params));
    }

    private function pagamento(array $params = [])
    {
        return new Pagamento(array_merge([
            'agencia'        => 1234,
            'conta'          => 56789,
            'contaDv'        => 0,
            'codigoBanco'    => '341',
            'valor'          => 150.00,
            'dataPagamento'  => new Carbon('2026-03-10'),
            'dataVencimento' => new Carbon('2026-03-10'),
            'numeroControle' => '000000001',
            'beneficiario'   => $this->beneficiario(),
        ], $params));
    }

    private function remessa(array $params = [])
    {
        return new Bancoob(array_merge([
            'idremessa' => 1,
            'agencia'   => 3011,
            'conta'     => 12345,
            'contaDv'   => 6,
            'convenio'  => '999999',
            'pagador'   => self::$pagador,
        ], $params));
    }

    private function linhas(Bancoob $remessa)
    {
        return explode("\r\n", rtrim($remessa->gerar(), "\r\n"));
    }

    /**
     * TED para outro banco e crédito para favorecido também no Sicoob (756) caem em lotes
     * separados, cada um com sua própria Forma de Lançamento (campo 06.1, posições 12-13).
     */
    public function testFavorecidoMesmoBancoUsaFormaLancamentoCreditoContaCorrente()
    {
        $remessa = $this->remessa();
        $remessa->addPagamento($this->pagamento(['codigoBanco' => '341'])); // TED, outro banco
        $remessa->addPagamento($this->pagamento(['codigoBanco' => '756'])); // crédito interno Sicoob

        $linhas = $this->linhas($remessa);

        $headersLote = array_values(array_filter($linhas, fn ($l) => substr($l, 7, 1) === '1'));

        $this->assertCount(2, $headersLote, 'Esperados dois lotes: um por banco de destino');

        $this->assertEquals('0001', substr($headersLote[0], 3, 4));
        $this->assertEquals('41', substr($headersLote[0], 11, 2), 'Lote do favorecido em outro banco deve manter 41 (TED outra titularidade)');

        $this->assertEquals('0002', substr($headersLote[1], 3, 4));
        $this->assertEquals('01', substr($headersLote[1], 11, 2), 'Lote do favorecido no próprio Sicoob deve virar 01 (crédito em conta corrente)');
    }

    /**
     * Câmara Centralizadora do Segmento A (campo 08.3A, posições 18-20) deriva da Forma de
     * Lançamento do lote: só TED usa câmara (018); crédito interno ao Sicoob vai zerada (000).
     */
    public function testCamaraCentralizadoraSegueLoteDoFavorecido()
    {
        $remessa = $this->remessa();
        $remessa->addPagamento($this->pagamento(['codigoBanco' => '341']));
        $remessa->addPagamento($this->pagamento(['codigoBanco' => '756']));

        $linhas = $this->linhas($remessa);

        $segmentosA = array_values(array_filter(
            $linhas,
            fn ($l) => substr($l, 7, 1) === '3' && substr($l, 13, 1) === 'A'
        ));

        $this->assertCount(2, $segmentosA);
        $this->assertEquals('018', substr($segmentosA[0], 17, 3), 'TED para outro banco transita pela câmara STR/CIP');
        $this->assertEquals('000', substr($segmentosA[1], 17, 3), 'Crédito interno ao Sicoob não transita por câmara');
    }

    /**
     * Um lote só com favorecidos de outros bancos continua se comportando como antes desta
     * correção: um único lote, código 41.
     */
    public function testSemFavorecidoNoMesmoBancoGeraUmUnicoLote()
    {
        $remessa = $this->remessa();
        $remessa->addPagamento($this->pagamento(['codigoBanco' => '341']));
        $remessa->addPagamento($this->pagamento(['codigoBanco' => '001']));

        $linhas = $this->linhas($remessa);

        $headersLote = array_values(array_filter($linhas, fn ($l) => substr($l, 7, 1) === '1'));

        $this->assertCount(1, $headersLote);
        $this->assertEquals('41', substr($headersLote[0], 11, 2));
    }
}
