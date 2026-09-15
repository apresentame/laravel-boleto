<?php

namespace Eduardokum\LaravelBoleto\Tests\Retorno;

use ReflectionClass;
use Eduardokum\LaravelBoleto\Tests\TestCase;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Cnab400\Detalhe;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Cnab400\Banco\Sicredi;

/**
 * Cobre a leitura do retorno CNAB 400 do Sicredi com registro híbrido (tipo 8) e a
 * tabela de ocorrências/motivos do Manual CNAB 400 Cobrança v3.1 (maio/2026).
 *
 * ATENÇÃO: não existe arquivo de retorno híbrido REAL do Sicredi neste repositório.
 * Os arquivos usados aqui são montados a partir do item 9.3 do manual. Isso valida o
 * parser contra o layout documentado, não contra o que o banco de fato envia - só a
 * homologação fecha esse ponto.
 */
class SicrediHibridoRetornoTest extends TestCase
{
    const NOSSO_NUMERO = '212000016';

    const LOCATION = 'pix-qrcode-h.sicredi.com.br/qr/v2/cobv/abc123def456';

    const EMV = '00020101021126580014br.gov.bcb.pix0136EXEMPLO-EMV-COPIA-E-COLA6304ABCD';

    protected $arquivos = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivos as $arquivo) {
            @unlink($arquivo);
        }
        $this->arquivos = [];
        parent::tearDown();
    }

    /**
     * Grava $valor a partir da posição $i (1-indexed) do registro
     *
     * @param string $registro
     * @param int    $i
     * @param string $valor
     */
    private function gravaCampo(&$registro, $i, $valor)
    {
        $valor = (string) $valor;

        for ($k = 0; $k < strlen($valor); $k++) {
            $registro[$i - 1 + $k] = $valor[$k];
        }
    }

    /**
     * @param string $alinhamento 'direita' = zeros à esquerda, 'esquerda' = zeros à direita
     *
     * @return string nosso número com os 15 caracteres do campo
     */
    private function nossoNumeroCampo($alinhamento)
    {
        return $alinhamento === 'direita'
            ? str_pad(self::NOSSO_NUMERO, 15, '0', STR_PAD_LEFT)
            : str_pad(self::NOSSO_NUMERO, 15, '0', STR_PAD_RIGHT);
    }

    /**
     * Monta um retorno com um título, opcionalmente seguido do registro híbrido (tipo 8)
     *
     * @param array $opcoes
     *
     * @return string caminho do arquivo gerado
     */
    private function arquivoRetorno(array $opcoes = [])
    {
        $opcoes = array_merge([
            'ocorrencia'        => '02',
            'motivos'           => '00',
            'comTipo8'          => true,
            'alinhamentoDetalhe' => 'direita',
            'alinhamentoTipo8'  => 'direita',
        ], $opcoes);

        $header = str_repeat(' ', 400);
        $this->gravaCampo($header, 1, '0');
        $this->gravaCampo($header, 2, '2');
        $this->gravaCampo($header, 3, 'RETORNO');
        $this->gravaCampo($header, 10, '01');
        $this->gravaCampo($header, 12, 'COBRANCA       ');
        $this->gravaCampo($header, 27, '90000');
        $this->gravaCampo($header, 32, '39000386000177');
        $this->gravaCampo($header, 77, '748');
        $this->gravaCampo($header, 80, 'BANSICREDI     ');
        $this->gravaCampo($header, 95, '20211028');
        $this->gravaCampo($header, 111, '0000001');
        $this->gravaCampo($header, 390, '99.99');
        $this->gravaCampo($header, 395, '000001');

        $detalhe = str_repeat(' ', 400);
        $this->gravaCampo($detalhe, 1, '1');
        $this->gravaCampo($detalhe, 2, 'A');
        $this->gravaCampo($detalhe, 14, 'A');
        $this->gravaCampo($detalhe, 25, '2');
        $this->gravaCampo($detalhe, 48, $this->nossoNumeroCampo($opcoes['alinhamentoDetalhe']));
        $this->gravaCampo($detalhe, 109, $opcoes['ocorrencia']);
        $this->gravaCampo($detalhe, 111, '281021');
        $this->gravaCampo($detalhe, 117, '004303233 ');
        $this->gravaCampo($detalhe, 147, '181221');
        $this->gravaCampo($detalhe, 153, '0000000000208');
        foreach ([176, 189, 228, 241, 267, 280] as $posicao) {
            $this->gravaCampo($detalhe, $posicao, str_repeat('0', 13));
        }
        $this->gravaCampo($detalhe, 202, str_repeat('0', 26));
        $this->gravaCampo($detalhe, 254, '0000000000208');
        $this->gravaCampo($detalhe, 319, str_pad($opcoes['motivos'], 10, '0', STR_PAD_RIGHT));
        $this->gravaCampo($detalhe, 329, '00000000');
        $this->gravaCampo($detalhe, 395, '000002');

        $linhas = [$header, $detalhe];

        if ($opcoes['comTipo8']) {
            // Item 9.3: 002-016 nosso número · 018 "H" · 021-055 TXID · 057-133 URL do QrCode · 135-390 copia e cola
            $tipo8 = str_repeat(' ', 400);
            $this->gravaCampo($tipo8, 1, '8');
            $this->gravaCampo($tipo8, 2, $this->nossoNumeroCampo($opcoes['alinhamentoTipo8']));
            $this->gravaCampo($tipo8, 18, 'H');
            $this->gravaCampo($tipo8, 21, str_pad('cobranca_titulo_txid_123456', 35));
            $this->gravaCampo($tipo8, 57, str_pad(self::LOCATION, 77));
            $this->gravaCampo($tipo8, 135, str_pad(self::EMV, 256));
            $this->gravaCampo($tipo8, 395, '000003');
            $linhas[] = $tipo8;
        }

        $trailer = str_repeat(' ', 400);
        $this->gravaCampo($trailer, 1, '9');
        $this->gravaCampo($trailer, 2, '2');
        $this->gravaCampo($trailer, 3, '748');
        $this->gravaCampo($trailer, 6, '90000');
        $this->gravaCampo($trailer, 395, str_pad((string) (count($linhas) + 1), 6, '0', STR_PAD_LEFT));
        $linhas[] = $trailer;

        $arquivo = tempnam(sys_get_temp_dir(), 'sicredi_hibrido_') . '.ret';
        file_put_contents($arquivo, implode("\r\n", $linhas) . "\r\n");
        $this->arquivos[] = $arquivo;

        return $arquivo;
    }

    /**
     * @param array $opcoes
     *
     * @return Detalhe
     */
    private function detalhe(array $opcoes = [])
    {
        $retorno = new Sicredi($this->arquivoRetorno($opcoes));
        $retorno->processar();

        return $retorno->getDetalhes()->first();
    }

    public function testRegistroTipo8EnriqueceOTituloComOsDadosDoPix()
    {
        $retorno = new Sicredi($this->arquivoRetorno());
        $retorno->processar();

        // O tipo 8 não pode virar um título solto na coleção: ele enriquece o detalhe do título
        $this->assertCount(1, $retorno->getDetalhes());

        $detalhe = $retorno->getDetalhes()->first();
        $this->assertEquals(self::LOCATION, trim($detalhe->getPixLocation()));
        $this->assertEquals(self::EMV, trim($detalhe->getPixQrCode()));
    }

    public function testRetornoSemRegistroTipo8NaoTrazPix()
    {
        $detalhe = $this->detalhe(['comTipo8' => false]);

        $this->assertEmpty(trim((string) $detalhe->getPixQrCode()));
    }

    /**
     * O casamento do nosso número entre o detalhe (048-062) e o tipo 8 (002-016) normaliza
     * os dois lados, então funciona em qualquer alinhamento, desde que seja o MESMO nos dois campos.
     */
    public function testCasamentoDoNossoNumeroIndependeDoAlinhamentoQuandoConsistente()
    {
        foreach (['direita', 'esquerda'] as $alinhamento) {
            $detalhe = $this->detalhe([
                'alinhamentoDetalhe' => $alinhamento,
                'alinhamentoTipo8'   => $alinhamento,
            ]);

            $this->assertEquals(self::EMV, trim($detalhe->getPixQrCode()), 'Alinhamento ' . $alinhamento);
        }
    }

    public function testMotivosDePixAparecemNaDescricaoDaOcorrencia()
    {
        $comQrCode = $this->detalhe(['ocorrencia' => '02', 'motivos' => 'P1']);
        $semQrCode = $this->detalhe(['ocorrencia' => '02', 'motivos' => 'P2']);

        // P1 e P2 chegam os dois na ocorrência 02; sem os motivos mapeados eram indistinguíveis
        $this->assertStringContainsString('Confirmado com QrCode', $comQrCode->getOcorrenciaDescricao());
        $this->assertStringContainsString('Confirmado sem QrCode', $semQrCode->getOcorrenciaDescricao());
        $this->assertNotEquals($comQrCode->getOcorrenciaDescricao(), $semQrCode->getOcorrenciaDescricao());

        $liquidadoViaPix = $this->detalhe(['ocorrencia' => '06', 'motivos' => 'PX']);
        $this->assertStringContainsString('Liquidação via QrCode', $liquidadoViaPix->getOcorrenciaDescricao());
        $this->assertEquals(Detalhe::OCORRENCIA_LIQUIDADA, $liquidadoViaPix->getOcorrenciaTipo());
    }

    public function testOcorrenciasNovasDeixamDeSerDesconhecidas()
    {
        // Lista de pares (e não array associativo) porque o PHP converteria as chaves "07", "78"... em int, perdendo o zero à esquerda
        $esperado = [
            ['07', 'Intenção de pagamento'],
            ['78', 'Confirmação de recebimento de pedido de negativação'],
            ['80', 'Confirmação de entrada de negativação'],
            ['82', 'Confirmação de exclusão de negativação'],
            ['84', 'Exclusão de negativação por outros motivos'],
            ['85', 'Ocorrência informacional por outros motivos'],
        ];

        foreach ($esperado as $caso) {
            list($ocorrencia, $descricao) = $caso;
            $detalhe = $this->detalhe(['ocorrencia' => $ocorrencia]);
            $this->assertEquals($descricao, $detalhe->getOcorrenciaDescricao(), 'Ocorrência ' . $ocorrencia);
        }
    }

    public function testRejeicoesDeNegativacaoSaoClassificadasComoErro()
    {
        foreach (['81', '83'] as $ocorrencia) {
            $detalhe = $this->detalhe(['ocorrencia' => $ocorrencia, 'motivos' => 'S1']);

            $this->assertEquals(Detalhe::OCORRENCIA_ERRO, $detalhe->getOcorrenciaTipo(), 'Ocorrência ' . $ocorrencia);
            $this->assertStringContainsString('Rejeitado pela empresa de negativação parceira', $detalhe->getError());
        }
    }

    public function testRejeicaoSempreChegaComMotivo()
    {
        $detalhe = $this->detalhe(['ocorrencia' => '03', 'motivos' => 'L7']);

        $this->assertEquals(Detalhe::OCORRENCIA_ERRO, $detalhe->getOcorrenciaTipo());
        $this->assertNotEmpty($detalhe->getRejeicao());
        $this->assertStringContainsString('negativação automática e protesto automático', $detalhe->getRejeicao());
    }

    public function testMotivoForaDaTabelaNaoSomeEmSilencio()
    {
        // "B2" é citado no item 7.2 entre os motivos da ocorrência 28, mas não existe nas tabelas 7.3/7.4
        $detalhe = $this->detalhe(['ocorrencia' => '03', 'motivos' => 'B2']);

        $this->assertEquals(Detalhe::OCORRENCIA_ERRO, $detalhe->getOcorrenciaTipo());
        $this->assertStringContainsString('B2', $detalhe->getRejeicao(), 'O código cru tem de chegar ao consumidor');
    }

    public function testMotivoZeradoNaoPoluiADescricao()
    {
        $detalhe = $this->detalhe(['ocorrencia' => '02', 'motivos' => '00']);

        $this->assertEquals('Entrada confirmada', $detalhe->getOcorrenciaDescricao());
        $this->assertEmpty($detalhe->getRejeicao());
    }

    /**
     * Guarda de cobertura: a tabela da lib não pode ficar atrás do manual de novo.
     * As listas abaixo são os itens 7.2, 7.3 e 7.4 do Manual CNAB 400 Cobrança v3.1 (maio/2026).
     */
    public function testTabelasCobremOManualV31()
    {
        $reflection = new ReflectionClass(Sicredi::class);
        $tabela = function ($nome) use ($reflection) {
            $propriedade = $reflection->getProperty($nome);
            $propriedade->setAccessible(true);

            return array_keys($propriedade->getValue($reflection->newInstanceWithoutConstructor()));
        };

        $ocorrencias = ['02', '03', '06', '07', '09', '10', '12', '13', '14', '15', '17', '19', '20', '23', '24', '27', '28', '29', '30', '32', '33', '34', '35', '78', '79', '80', '81', '82', '83', '84', '85'];

        $motivos = array_merge(
            ['01', '02', '03', '04', '05', '07', '08', '09', '10', '14', '15', '16', '17', '18', '20', '21', '22', '24', '29', '31', '33', '34', '36', '38', '39', '40', '41', '44', '45', '46', '47', '48', '49', '50', '53', '54', '60', '63'],
            ['0A', '0D'],
            ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8'],
            ['B4', 'B5', 'B6', 'B7', 'B8', 'B9'],
            ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9'],
            ['D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'D9'],
            ['E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9'],
            ['F1', 'F2', 'F3', 'F4', 'F6', 'F7', 'F8', 'F9'],
            ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9'],
            ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'H7', 'H8', 'H9'],
            ['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'I9'],
            ['J1', 'J2', 'J3', 'J4', 'J5', 'J6', 'J7', 'J8', 'J9'],
            ['K1', 'K2', 'K3', 'K4', 'K5', 'K6', 'K7', 'K8', 'K9'],
            ['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7'],
            ['M1', 'M2', 'M3'],
            ['N1', 'N2', 'N3', 'N4', 'N5'],
            ['P1', 'P2', 'P6', 'PX'],
            ['S1'],
            ['X0', 'X1', 'X2', 'X3', 'X4', 'X5', 'X6', 'X7', 'X8', 'X9', 'XA', 'XB']
        );

        $tarifas = ['03', '04', '08', 'A9', 'B1', 'B3', 'F5', 'S4', 'S5'];

        $this->assertEmpty(array_diff($ocorrencias, $tabela('ocorrencias')), 'Ocorrências do item 7.2 faltando');
        $this->assertEmpty(array_diff($motivos, $tabela('rejeicoes')), 'Motivos do item 7.3 faltando');
        $this->assertEmpty(array_diff($tarifas, $tabela('ocorrenciasTarifas')), 'Motivos de tarifa do item 7.4 faltando');
    }
}
