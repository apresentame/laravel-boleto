<?php

namespace Eduardokum\LaravelBoleto\Tests\Retorno;

use Carbon\Carbon;
use Eduardokum\LaravelBoleto\Pessoa;
use Eduardokum\LaravelBoleto\Tests\TestCase;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Factory;
use Eduardokum\LaravelBoleto\Cnab\Retorno\FakeRetorno;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Cresol as BoletoCresol;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Cnab240\Banco\Cresol as RetornoCnab240;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Cnab400\Banco\Cresol as RetornoCnab400;

/**
 * Round-trip do retorno fake da Cresol: gera a remessa pela lib, transforma em retorno
 * pelo FakeRetorno e lê de volta pelo parser da própria lib. É o que garante que as
 * posições gravadas pelo gerador batem com as posições lidas pelo parser nos dois layouts.
 */
class CresolFakeRetornoTest extends TestCase
{
    protected static $pagador;

    protected static $beneficiario;

    /** Nossos números com dígito verificador numérico — o CNAB 240 recusa o dígito "P". */
    const NUMEROS = [11, 12, 13, 14];

    const LAYOUTS = ['400', '240'];

    public static function setUpBeforeClass(): void
    {
        self::$beneficiario = new Pessoa([
            'nome'      => 'BENEFICIARIO TESTE LTDA',
            'endereco'  => 'Rua um, 123',
            'cep'       => '85000-000',
            'uf'        => 'PR',
            'cidade'    => 'CIDADE',
            'documento' => '12.345.678/0001-95',
        ]);

        self::$pagador = new Pessoa([
            'nome'      => 'PAGADOR TESTE',
            'endereco'  => 'Rua dois, 456',
            'bairro'    => 'CENTRO',
            'cep'       => '85010-000',
            'uf'        => 'PR',
            'cidade'    => 'CIDADE',
            'documento' => '077.651.119-06',
        ]);
    }

    /**
     * Gera a remessa do layout pedido e devolve o caminho do arquivo
     */
    private function remessa($cnab)
    {
        $class = "\\Eduardokum\\LaravelBoleto\\Cnab\\Remessa\\Cnab$cnab\\Banco\\Cresol";

        $remessa = new $class([
            'agencia'      => 1069,
            'conta'        => 28245,
            'contaDv'      => 6,
            'carteira'     => '09',
            'idremessa'    => 1,
            'beneficiario' => self::$beneficiario,
        ]);

        foreach (self::NUMEROS as $numero) {
            $remessa->addBoleto(new BoletoCresol([
                'agencia'         => 1069,
                'conta'           => 28245,
                'contaDv'         => 6,
                'carteira'        => '09',
                'numero'          => $numero,
                'numeroDocumento' => $numero,
                'numeroControle'  => 7000 + $numero,
                'dataVencimento'  => new Carbon('2026-03-10'),
                'dataDocumento'   => new Carbon('2026-02-10'),
                'valor'           => 150.00 + $numero,
                'especieDoc'      => 'DM',
                'beneficiario'    => self::$beneficiario,
                'pagador'         => self::$pagador,
            ]));
        }

        $path = tempnam(sys_get_temp_dir(), 'cresol') . '.rem';
        $remessa->save($path);

        return $path;
    }

    /**
     * Escreve o retorno fake num arquivo e devolve o parser já processado
     */
    private function parse($cnab, $spec = null)
    {
        $path = tempnam(sys_get_temp_dir(), 'cresol') . '.ret';
        file_put_contents($path, FakeRetorno::gerar('133', $cnab, $this->remessa($cnab), $spec));

        return Factory::make($path);
    }

    /**
     * A factory precisa reconhecer o arquivo fake como retorno Cresol do layout certo
     */
    public function testFactoryReconheceORetornoFake()
    {
        $this->assertInstanceOf(RetornoCnab400::class, $this->parse('400'));
        $this->assertInstanceOf(RetornoCnab240::class, $this->parse('240'));
    }

    /**
     * Todas as linhas precisam ter exatamente o tamanho do layout
     */
    public function testTamanhoDasLinhas()
    {
        foreach (self::LAYOUTS as $cnab) {
            $conteudo = FakeRetorno::gerar('133', $cnab, $this->remessa($cnab));

            foreach (explode("\r\n", rtrim($conteudo, "\r\n")) as $linha) {
                $this->assertEquals((int) $cnab, strlen($linha), "CNAB$cnab");
            }
        }
    }

    /**
     * O match do retorno depende do número de controle: ele tem que voltar intacto,
     * junto do nosso número, do vencimento e do valor de cada título
     */
    public function testPreservaControleNossoNumeroEValor()
    {
        foreach (self::LAYOUTS as $cnab) {
            $detalhes = $this->parse($cnab)->getDetalhes();

            $this->assertCount(count(self::NUMEROS), $detalhes, "CNAB$cnab");

            foreach (array_values($detalhes->all()) as $i => $detalhe) {
                $numero = self::NUMEROS[$i];

                $this->assertEquals(7000 + $numero, (int) $detalhe->getNumeroControle(), "CNAB$cnab");
                // O retorno carrega nosso número + dígito verificador; o último caractere é o DV
                $this->assertEquals($numero, (int) substr($detalhe->getNossoNumero(), 0, -1), "CNAB$cnab");
                $this->assertEquals('10/03/2026', $detalhe->getDataVencimento(), "CNAB$cnab");
                $this->assertEquals(number_format(150.00 + $numero, 2, '.', ''), $detalhe->getValor(), "CNAB$cnab");
            }
        }
    }

    /**
     * O mix default percorre confirm, pay, reject e cancel — cada um precisa cair no
     * tipo de ocorrência que o parser da Cresol classifica
     */
    public function testMixDefaultCobreOsQuatroTiposDeOcorrencia()
    {
        foreach (self::LAYOUTS as $cnab) {
            $d = array_values($this->parse($cnab)->getDetalhes()->all());

            $this->assertEquals('02', $d[0]->getOcorrencia(), "CNAB$cnab");
            $this->assertEquals($d[0]::OCORRENCIA_ENTRADA, $d[0]->getOcorrenciaTipo(), "CNAB$cnab");

            $this->assertEquals('06', $d[1]->getOcorrencia(), "CNAB$cnab");
            $this->assertEquals($d[1]::OCORRENCIA_LIQUIDADA, $d[1]->getOcorrenciaTipo(), "CNAB$cnab");

            $this->assertEquals('03', $d[2]->getOcorrencia(), "CNAB$cnab");
            $this->assertEquals($d[2]::OCORRENCIA_ERRO, $d[2]->getOcorrenciaTipo(), "CNAB$cnab");

            $this->assertEquals('09', $d[3]->getOcorrencia(), "CNAB$cnab");
            $this->assertEquals($d[3]::OCORRENCIA_BAIXADA, $d[3]->getOcorrenciaTipo(), "CNAB$cnab");
        }
    }

    /**
     * Só a liquidação preenche valor pago e data de crédito
     */
    public function testApenasALiquidacaoTrazValorPagoEDataDeCredito()
    {
        foreach (self::LAYOUTS as $cnab) {
            $d = array_values($this->parse($cnab)->getDetalhes()->all());

            $this->assertEquals('162.00', $d[1]->getValorRecebido(), "CNAB$cnab");
            $this->assertEquals(date('d/m/Y'), $d[1]->getDataCredito(), "CNAB$cnab");

            $this->assertEquals(0, (float) $d[0]->getValorRecebido(), "CNAB$cnab");
            $this->assertEquals(0, (float) $d[3]->getValorRecebido(), "CNAB$cnab");
        }
    }

    /**
     * A rejeição carrega o motivo lido da tabela do banco, tanto o default do profile
     * quanto o informado no spec
     */
    public function testMotivoDaRejeicao()
    {
        foreach (self::LAYOUTS as $cnab) {
            $detalhe = array_values($this->parse($cnab)->getDetalhes()->all())[2];
            $this->assertStringContainsString('Entrada para Título já Cadastrado', $detalhe->getError(), "CNAB$cnab");

            $detalhe = $this->parse($cnab, 'reject:08')->getDetalhes()->first();
            $this->assertEquals('03', $detalhe->getOcorrencia(), "CNAB$cnab");
            $this->assertTrue($detalhe->hasError(), "CNAB$cnab");
            $this->assertStringContainsString('Nosso Número Inválido', $detalhe->getError(), "CNAB$cnab");
        }
    }

    /**
     * A confirmação de alteração de vencimento (14) tem que cair em OCORRENCIA_ALTERACAO
     * nos dois layouts — o manual lista o código nas duas tabelas
     */
    public function testAlteracaoDeVencimentoNosDoisLayouts()
    {
        foreach (self::LAYOUTS as $cnab) {
            $detalhe = $this->parse($cnab, 'change')->getDetalhes()->first();

            $this->assertEquals('14', $detalhe->getOcorrencia(), "CNAB$cnab");
            $this->assertEquals($detalhe::OCORRENCIA_ALTERACAO, $detalhe->getOcorrenciaTipo(), "CNAB$cnab");
        }
    }

    /**
     * O spec associativo endereça o título pelo número de controle
     */
    public function testSpecPorNumeroDeControle()
    {
        foreach (self::LAYOUTS as $cnab) {
            $pago = $this->parse($cnab, ['7013' => 'pay'])
                ->getDetalhes()
                ->first(fn ($d) => (int) $d->getNumeroControle() === 7013);

            $this->assertEquals('06', $pago->getOcorrencia(), "CNAB$cnab");
            $this->assertEquals($pago::OCORRENCIA_LIQUIDADA, $pago->getOcorrenciaTipo(), "CNAB$cnab");
        }
    }

    /**
     * Banco sem profile continua falhando claro, em vez de gerar arquivo inválido
     */
    public function testBancoSemProfileFalhaComMensagemClara()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('não suporta');

        FakeRetorno::gerar('341', '400', $this->remessa('400'));
    }
}
