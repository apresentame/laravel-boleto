<?php

namespace Eduardokum\LaravelBoleto\Boleto\Banco;

use Eduardokum\LaravelBoleto\Util;
use Eduardokum\LaravelBoleto\CalculoDV;
use Eduardokum\LaravelBoleto\Boleto\AbstractBoleto;
use Eduardokum\LaravelBoleto\Exception\ValidationException;
use Eduardokum\LaravelBoleto\Contracts\Boleto\Boleto as BoletoContract;

class Caixa extends AbstractBoleto implements BoletoContract
{
    public function __construct(array $params = [])
    {
        parent::__construct($params);
        $this->setCamposObrigatorios('numero', 'agencia', 'carteira', 'codigoCliente');
    }

    /**
     * Código do banco
     *
     * @var string
     */
    protected $codigoBanco = self::COD_BANCO_CEF;

    /**
     * Define as carteiras disponíveis para este banco
     *
     * @var array
     */
    protected $carteiras = ['RG'];

    /**
     * Espécie do documento, coódigo para remessa
     *
     * @var string
     */
    protected $especiesCodigo240 = [
        'CH' => '01', // Cheque
        'DM' => '02', // Duplicata Mercantil
        'DMI' => '03', // Duplicata Mercantil p/ Indicação
        'DS' => '04', // Duplicata de Serviço 05 DSI Duplicata de Serviço p/ Indicação
        'DR' => '06', // Duplicata Rural
        'LC' => '07', // Letra de Câmbio 08 NCC Nota de Crédito Comercial
        'NCE' => '09', // Nota de Crédito à Exportação
        'NCI' => '10', // Nota de Crédito Industrial
        'NCR' => '11', // Nota de Crédito Rural
        'NP' => '12', // Nota Promissória 13 NPR Nota Promissória Rural
        'TM' => '14', // Triplicata Mercantil
        'TS' => '15', // Triplicata de Serviço
        'NS' => '16', // Nota de Seguro
        'RC' => '17', // Recibo
        'FAT' => '18', // Bloqueto
        'ND' => '19', // Nota de Débito
        'AP' => '20', // Apólice de Seguro 21 ME Mensalidade Escolar 22 PC Parcela de Consórcio 23 NF Nota Fiscal
        'DD' => '24', // Documento de Dívida
    ];

    /**
     * Espécie do documento, coódigo para remessa
     *
     * @var string
     */
    protected $especiesCodigo400 = [
        'DM' => '01', // Duplicata Mercantil
        'NP' => '02', // Nota Promissória
        'DS' => '03', // Duplicata de Prestação de Serviços
        'CH' => '04', // Cheque
        'NS' => '05', // Nota de Seguro
        'LC' => '06', // Letra de Câmbio
        'DMI' => '07', // Duplicata Mercantil p/ Indicação
        'NCC' => '08', // Nota de Crédito Comercial
        'OU' => '09', // Outros
        'NCI' => '10', // Nota de Crédito Industrial
        'NCR' => '11', // Nota de Crédito Rural
        'DSI' => '12', // Duplicata de Serviço
        'NPR' => '13', // Nota Promissória Rural
        'TM' => '14', // Triplicata Mercantil
        'TS' => '15', // Triplicata de Serviço
        'DR' => '16', // Duplicata Rural
        'RC' => '17', // Recibo
        'FAT' => '18', // Fatura
        'ND' => '19', // Nota de Débito
        'AP' => '20', // Apólice de Seguro
        'ME' => '21', // Mensalidade Escolar
        'PC' => '22', // Parcela de Consórcio
        'NF' => '23', // Nota Fiscal
        'DD' => '24', // Documento de Dívida
        'CPR' => '25', // Cédula de Produto Rural
        'NCE' => '26', // Nota de Crédito à Exportação
        'EC' => '30', // Encargos Condominiais
        'CC' => '31', // Cartão de Crédito
        'BP' => '32', // Boleto Proposta
    ];

    /**
     * Codigo do cliente junto ao banco.
     *
     * @var string
     */
    protected $codigoCliente;

    /**
     * Seta o código do cliente.
     *
     * @param mixed $codigoCliente
     *
     * @return Caixa
     */
    public function setCodigoCliente($codigoCliente)
    {
        $this->codigoCliente = $codigoCliente;

        return $this;
    }

    /**
     * Retorna o codigo do cliente.
     *
     * @return string
     */
    public function getCodigoCliente()
    {
        return $this->codigoCliente;
    }

    /**
     * Retorna o codigo do cliente como se fosse a conta
     * ja que a caixa não faz uso da conta para nada.
     *
     * @return string
     */
    public function getConta()
    {
        return $this->getCodigoCliente();
    }

    /**
     * Gera o Nosso Número.
     *
     * @return string
     * @throws ValidationException
     */
    protected function gerarNossoNumero()
    {
        $numero_boleto = Util::numberFormatGeral($this->getNumero(), 15);
        $composicao = '1';
        if ($this->getCarteira() == 'SR') {
            $composicao = '2';
        }

        $carteira = $composicao . '4';
        // As 15 próximas posições no nosso número são a critério do beneficiário, utilizando o sequencial
        // Depois, calcula-se o código verificador por módulo 11
        $numero = $carteira . Util::numberFormatGeral($numero_boleto, 15);

        return $numero;
    }

    /**
     * Método que retorna o nosso numero usado no boleto. alguns bancos possuem algumas diferenças.
     *
     * @return string
     */
    public function getNossoNumeroBoleto()
    {
        return $this->getNossoNumero() . '-' . CalculoDV::cefNossoNumero($this->getNossoNumero());
    }

    /**
     * Na CEF deve retornar agência (sem o DV) / código beneficiário (com DV)
     * @return [type] [description]
     */
    public function getAgenciaCodigoBeneficiario()
    {
        return $this->getAgencia() . ' / ' .
            $this->getCodigoCliente() . '-' .
            Util::modulo11($this->getCodigoCliente());
    }

    /**
     * Seta dia para baixa automática
     *
     * @param int $baixaAutomatica
     *
     * @return Caixa
     * @throws ValidationException
     */
    public function setDiasBaixaAutomatica($baixaAutomatica)
    {
        if ($this->getDiasProtesto() > 0) {
            throw new ValidationException('Você deve usar dias de protesto ou dias de baixa, nunca os 2');
        }
        $baixaAutomatica = (int) $baixaAutomatica;
        $this->diasBaixaAutomatica = $baixaAutomatica > 0 ? $baixaAutomatica : 0;

        return $this;
    }

    /**
     * Método para gerar o código da posição de 20 a 44
     *
     * @return string
     * @throws ValidationException
     */
    protected function getCampoLivre()
    {
        if ($this->campoLivre) {
            return $this->campoLivre;
        }

        $nossoNumero = Util::numberFormatGeral($this->gerarNossoNumero(), 17);
        $beneficiario = Util::numberFormatGeral($this->getCodigoCliente(), 6);
        $beneficiario .= Util::modulo11($beneficiario);
        if ($this->getCodigoCliente() > 1100000) {
            $beneficiario = Util::numberFormatGeral($this->getCodigoCliente(), 7);
        }

        $campoLivre = $beneficiario;
        $campoLivre .= substr($nossoNumero, 2, 3);
        $campoLivre .= substr($nossoNumero, 0, 1);
        $campoLivre .= substr($nossoNumero, 5, 3);
        $campoLivre .= substr($nossoNumero, 1, 1);
        $campoLivre .= substr($nossoNumero, 8, 9);
        $campoLivre .= Util::modulo11($campoLivre);

        return $this->campoLivre = $campoLivre;
    }

    /**
     * Método onde qualquer boleto deve extender para gerar o código da posição de 20 a 44
     *
     * @param $campoLivre
     *
     * @return array
     */
    public static function parseCampoLivre($campoLivre)
    {
        return [
            'convenio'        => null,
            'agencia'         => null,
            'agenciaDv'       => null,
            'contaCorrente'   => null,
            'contaCorrenteDv' => null,
            'codigoCliente7'  => substr($campoLivre, 0, 7),
            'codigoCliente'   => substr($campoLivre, 0, 6),
            'carteira'        => substr($campoLivre, 10, 1),
            'nossoNumero'     => substr($campoLivre, 7, 3) . substr($campoLivre, 11, 3) . substr($campoLivre, 15, 8),
            'nossoNumeroDv'   => substr($campoLivre, 23, 1),
            'nossoNumeroFull' => substr($campoLivre, 7, 3) . substr($campoLivre, 11, 3) . substr($campoLivre, 15, 8),
        ];
    }
}
