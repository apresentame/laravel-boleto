<?php

namespace Eduardokum\LaravelBoleto\Tests\Remessa;

use Carbon\Carbon;
use Eduardokum\LaravelBoleto\Pessoa;
use Eduardokum\LaravelBoleto\Tests\TestCase;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Boleto\Banco\Sicredi as BoletoSicredi;
use Eduardokum\LaravelBoleto\Cnab\Remessa\Cnab400\Banco\Sicredi as RemessaSicredi;

/**
 * Cobre o boleto híbrido (com QrCode PIX) do Sicredi na remessa CNAB 400.
 *
 * O layout conferido aqui vem do arquivo de remessa híbrida homologado pelo Sicredi
 * (COBModeloCNAB400hbrido.txt), que é a fonte mais confiável quando diverge do
 * Manual CNAB 400 Cobrança v3.1 (maio/2026). As divergências estão anotadas nos
 * comentários do registro tipo 8 em Cnab/Remessa/Cnab400/Banco/Sicredi.php.
 *
 * Linha de detalhe do arquivo homologado (recorte das 20 primeiras posições):
 *   "1AAA H          AAA "  ->  posição 006 = "H" (Tipo de Boleto = Híbrido)
 *
 * Registro tipo 8 do arquivo homologado:
 *   "8212000016000000 H            004303233  [...]  000003"
 */
class SicrediHibridoCnab400Test extends TestCase
{
    /**
     * Sequencial do registro tipo 8 no arquivo homologado (header=1, detalhe=2, tipo 8=3)
     */
    const SEQUENCIAL_TIPO_8 = '000003';

    /**
     * "Seu número" gravado no detalhe (111-120) e repetido no registro tipo 8 (031-040)
     */
    const SEU_NUMERO = '004303233';

    protected static $pagador;

    protected static $beneficiario;

    public static function setUpBeforeClass(): void
    {
        self::$beneficiario = new Pessoa([
            'nome'      => 'ACME',
            'endereco'  => 'Rua um, 123',
            'cep'       => '99999-999',
            'uf'        => 'UF',
            'cidade'    => 'CIDADE',
            'documento' => '99.999.999/9999-99',
        ]);

        self::$pagador = new Pessoa([
            'nome'      => 'Cliente',
            'endereco'  => 'Rua um, 123',
            'bairro'    => 'Bairro',
            'cep'       => '99999-999',
            'uf'        => 'UF',
            'cidade'    => 'CIDADE',
            'documento' => '999.999.999-99',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // A posição 063-070 do detalhe grava Carbon::now(); congelar o relógio é o que torna a comparação com o arquivo-ouro determinística.
        Carbon::setTestNow(Carbon::create(2021, 10, 28, 0, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Recorta um campo do registro usando as posições do manual (1-indexed, inclusivas)
     *
     * @param string $registro
     * @param int    $inicio
     * @param int    $fim
     *
     * @return string
     */
    private function campo($registro, $inicio, $fim)
    {
        return substr($registro, $inicio - 1, $fim - $inicio + 1);
    }

    /**
     * @param array $params
     *
     * @return BoletoSicredi
     */
    private function boleto(array $params = [])
    {
        return new BoletoSicredi(array_merge([
            'dataVencimento'    => Carbon::create(2021, 12, 18, 0, 0, 0),
            'dataDocumento'     => Carbon::create(2021, 10, 20, 0, 0, 0),
            'dataProcessamento' => Carbon::create(2021, 10, 28, 0, 0, 0),
            'valor'             => 2.08,
            'multa'             => 0,
            'juros'             => 0.01,
            'numero'            => 1,
            'numeroDocumento'   => 4303233,
            'numeroControle'    => self::SEU_NUMERO,
            'pagador'           => self::$pagador,
            'beneficiario'      => self::$beneficiario,
            'carteira'          => 'A',
            'byte'              => 2,
            'agencia'           => 1111,
            'posto'             => 11,
            'conta'             => 11111,
            'codigoCliente'     => 90000,
            'aceite'            => 'S',
            'especieDoc'        => 'DM',
        ], $params));
    }

    /**
     * @return RemessaSicredi
     */
    private function remessa()
    {
        return new RemessaSicredi([
            'agencia'       => 2606,
            'carteira'      => 'A',
            'conta'         => 12510,
            'codigoCliente' => 90000,
            'idremessa'     => 1,
            'beneficiario'  => self::$beneficiario,
        ]);
    }

    /**
     * @param BoletoSicredi $boleto
     *
     * @return array as linhas do arquivo de remessa, sem o fim de linha
     */
    private function linhasRemessa(BoletoSicredi $boleto)
    {
        $remessa = $this->remessa();
        $remessa->addBoleto($boleto);

        return explode("\r\n", rtrim($remessa->gerar(), "\r\n"));
    }

    public function testFlagHibridoVemDesligadaPorPadrao()
    {
        $this->assertFalse($this->boleto()->isPixHibrido());
        $this->assertFalse($this->boleto()->getPixHibrido());
        $this->assertTrue($this->boleto(['pixHibrido' => true])->isPixHibrido());
        $this->assertTrue($this->boleto()->setPixHibrido(true)->isPixHibrido());
    }

    public function testDetalheGravaHNaPosicao006QuandoHibrido()
    {
        $linhas = $this->linhasRemessa($this->boleto(['pixHibrido' => true]));
        $detalhe = $linhas[1];

        $this->assertEquals('H', $this->campo($detalhe, 6, 6), 'Posição 006 (Tipo de Boleto) deve ser "H"');
        // Compara o bloco inteiro com o recorte do arquivo homologado, garantindo que o "H" não deslocou os campos vizinhos
        $this->assertEquals('1AAA H          AAA ', $this->campo($detalhe, 1, 20));
        $this->assertEquals(400, strlen($detalhe));
    }

    public function testRegistroTipo8ConfereComArquivoHomologado()
    {
        $boleto = $this->boleto(['pixHibrido' => true]);
        $linhas = $this->linhasRemessa($boleto);

        $this->assertCount(4, $linhas, 'Header + detalhe + registro tipo 8 + trailer');

        $detalhe = $linhas[1];
        $tipo8 = $linhas[2];

        $this->assertEquals(400, strlen($tipo8));
        $this->assertEquals('8', $this->campo($tipo8, 1, 1), 'Posição 001 = identificação do registro');

        // 002-016: nosso número alinhado à ESQUERDA com zeros à direita, conforme o arquivo homologado ("212000016000000"), e não zeros à esquerda como sugere o manual ao chamar o campo de numérico
        $nossoNumero = $boleto->getNossoNumero();
        $this->assertEquals(9, strlen($nossoNumero));
        $this->assertEquals($nossoNumero . '000000', $this->campo($tipo8, 2, 16));
        $this->assertEquals($nossoNumero, $this->campo($detalhe, 48, 56), 'O tipo 8 deve carregar o mesmo nosso número do detalhe');

        $this->assertEquals(' ', $this->campo($tipo8, 17, 17));
        $this->assertEquals('H', $this->campo($tipo8, 18, 18), 'Posição 018 = Híbrido');
        $this->assertEquals(str_repeat(' ', 12), $this->campo($tipo8, 19, 30));

        // 031-040: "seu número" repetido exatamente como gravado em 111-120 do detalhe (alinhado à esquerda, como no arquivo homologado)
        $this->assertEquals(self::SEU_NUMERO . ' ', $this->campo($tipo8, 31, 40));
        $this->assertEquals($this->campo($detalhe, 111, 120), $this->campo($tipo8, 31, 40));

        // 041-075: TXID em branco - quem gera e vincula o TXID ao título é o Sicredi
        $this->assertEquals(str_repeat(' ', 35), $this->campo($tipo8, 41, 75));
        $this->assertEquals(str_repeat(' ', 319), $this->campo($tipo8, 76, 394));

        // O registro tipo 8 conta no sequencial do arquivo e, por consequência, no total do trailer
        $this->assertEquals(self::SEQUENCIAL_TIPO_8, $this->campo($tipo8, 395, 400));
        $this->assertEquals('000002', $this->campo($detalhe, 395, 400));
        $this->assertEquals('000004', $this->campo($linhas[3], 395, 400));
    }

    public function testHibridoNaoPreencheDatasDeInicioDeJurosEMulta()
    {
        // Datas de início de juros (021-028) e de multa (029-036) posteriores a vencimento+1 fazem o Sicredi cadastrar o título SEM QrCode; devem continuar em branco
        $linhas = $this->linhasRemessa($this->boleto(['pixHibrido' => true]));
        $detalhe = $linhas[1];

        $this->assertEquals(str_repeat(' ', 8), $this->campo($detalhe, 21, 28), 'Data de início de juros deve ficar em branco');
        $this->assertEquals(str_repeat(' ', 8), $this->campo($detalhe, 29, 36), 'Data de início de multa deve ficar em branco');
    }

    public function testHibridoAlteraApenasAPosicao006DoDetalhe()
    {
        $semHibrido = $this->linhasRemessa($this->boleto());
        $comHibrido = $this->linhasRemessa($this->boleto(['pixHibrido' => true]));

        $this->assertEquals($semHibrido[0], $comHibrido[0], 'Header não muda');

        // Fora da posição 006, o detalhe tem de ser idêntico ao de hoje
        $this->assertEquals(
            substr_replace($semHibrido[1], 'H', 5, 1),
            $comHibrido[1],
            'O híbrido só pode alterar a posição 006 do registro detalhe'
        );

        // O trailer só muda no total de registros, por causa do registro tipo 8
        $this->assertEquals(substr($semHibrido[2], 0, 394), substr($comHibrido[3], 0, 394));
        $this->assertEquals('000003', $this->campo($semHibrido[2], 395, 400));
        $this->assertEquals('000004', $this->campo($comHibrido[3], 395, 400));
    }

    public function testRegistroTipo8VemAntesDoRegistroMensagem()
    {
        // byte = 1 significa impressão pelo Sicredi, que dispara o registro mensagem (tipo 2); o tipo 8 tem de ficar logo após o detalhe do título, sem furo no sequencial
        $linhas = $this->linhasRemessa($this->boleto([
            'pixHibrido' => true,
            'byte'       => 1,
            'instrucoes' => ['instrucao 1', 'instrucao 2', 'instrucao 3', 'instrucao 4'],
        ]));

        $this->assertCount(5, $linhas);
        $this->assertEquals(['0', '1', '8', '2', '9'], array_map(function ($linha) {
            return $linha[0];
        }, $linhas));

        foreach (['000001', '000002', '000003', '000004', '000005'] as $i => $sequencial) {
            $this->assertEquals($sequencial, $this->campo($linhas[$i], 395, 400), 'Sequencial da linha ' . ($i + 1));
        }
    }

    public function testRegistroTipo8MantemOsSeus400CaracteresComDetalheEstendidoPelaNfe()
    {
        // Com chave de NF-e o registro detalhe passa a ter 444 caracteres; o registro tipo 8 não pode acompanhar essa extensão
        $linhas = $this->linhasRemessa($this->boleto([
            'pixHibrido' => true,
            'chaveNfe'   => '35210612345678000199550010000000011000000017',
        ]));

        $this->assertCount(4, $linhas);
        $this->assertEquals(444, strlen($linhas[1]), 'Detalhe estendido pela chave da NF-e');
        $this->assertEquals(400, strlen($linhas[2]), 'Registro tipo 8 segue com 400 caracteres');
        $this->assertEquals('8', $linhas[2][0]);
    }

    public function testSemHibridoGeraArquivoIdenticoAoDeHoje()
    {
        $arquivoOuro = implode(DIRECTORY_SEPARATOR, [__DIR__, 'files', 'sicredi', 'remessa_cnab400_sem_hibrido.txt']);
        $this->assertFileExists($arquivoOuro);

        $remessa = $this->remessa();
        $remessa->addBoleto($this->boleto());

        // Arquivo-ouro capturado da lib ANTES da implementação do híbrido: comparação byte a byte
        $this->assertSame(file_get_contents($arquivoOuro), $remessa->gerar());
    }

    public function testHibridoRejeitaBoletoProposta()
    {
        $this->expectException(ValidationException::class);

        // O mapa de espécies do boleto Sicredi ainda não expõe "O - Boleto Proposta"; a subclasse força o código resolvido para exercitar a guarda dos itens 5.3 e 11 do manual
        $boleto = new BoletoSicrediBoletoProposta([
            'dataVencimento'    => Carbon::create(2021, 12, 18, 0, 0, 0),
            'dataDocumento'     => Carbon::create(2021, 10, 20, 0, 0, 0),
            'dataProcessamento' => Carbon::create(2021, 10, 28, 0, 0, 0),
            'valor'             => 2.08,
            'numero'            => 1,
            'numeroDocumento'   => 4303233,
            'numeroControle'    => self::SEU_NUMERO,
            'pagador'           => self::$pagador,
            'beneficiario'      => self::$beneficiario,
            'carteira'          => 'A',
            'byte'              => 2,
            'agencia'           => 1111,
            'posto'             => 11,
            'conta'             => 11111,
            'codigoCliente'     => 90000,
            'aceite'            => 'S',
            'pixHibrido'        => true,
        ]);

        $this->linhasRemessa($boleto);
    }
}

/**
 * Boleto Sicredi que resolve a espécie de documento como "O - Boleto Proposta",
 * espécie que o manual (itens 5.3 e 11) proíbe combinar com boleto híbrido.
 */
class BoletoSicrediBoletoProposta extends BoletoSicredi
{
    public function getEspecieDocCodigo($default = 99, $tipo = 240)
    {
        return RemessaSicredi::ESPECIE_BOLETO_PROPOSTA;
    }
}
