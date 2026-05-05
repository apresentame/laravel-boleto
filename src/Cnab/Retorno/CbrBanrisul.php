<?php

namespace Eduardokum\LaravelBoleto\Cnab\Retorno;

use Illuminate\Support\Collection;
use Eduardokum\LaravelBoleto\Cnab\Retorno\Cnab400\Detalhe;
use Eduardokum\LaravelBoleto\Contracts\Cnab\Retorno\Detalhe as DetalheContract;

/**
 * Retorno Banrisul no formato proprietário CBR (160 posições por linha).
 *
 * Formato diferente dos padrões CNAB240/400 — linhas de 160 chars geradas
 * pelo internet banking Banrisul como arquivo de cobrança.
 */
class CbrBanrisul
{
    const COD_BANCO = '041';

    protected array $detalhes = [];

    public function __construct(protected string $file) {}

    public static function isCbrFile(string $path): bool
    {
        if (!is_file($path) || !file_exists($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines || !isset($lines[0])) {
            return false;
        }

        $first = rtrim($lines[0], "\r\n");
        return strlen($first) === 160 && str_contains($first, 'CBR724');
    }

    public function processar(): static
    {
        $lines = file($this->file, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");

            if (strlen($line) < 90) {
                continue;
            }

            $tipo = substr($line, 0, 2);

            // Processa apenas linhas de transação (37 = principais, " 7" = demais)
            if ($tipo !== '37' && $tipo !== ' 7') {
                continue;
            }

            $this->detalhes[] = $this->parseDetalhe($line);
        }

        return $this;
    }

    protected function parseDetalhe(string $linha): Detalhe
    {
        $d = new Detalhe();

        // Nosso número: posições 21-25 (0-indexed), 5 chars
        $nossoNumero = (int) trim(substr($linha, 21, 5));

        // Código da operação: posições 83-85 (3 chars)
        $opCode = trim(substr($linha, 83, 3));

        // Data vencimento: posições 63-70 (ddmmyyyy)
        $dataStr = trim(substr($linha, 63, 8));

        // Valor documento: posições 86-105 (20 chars, direita-alinhado)
        $valor = $this->parseAmount(substr($linha, 86, 20));

        [$ocorrenciaTipo, $ocorrenciaDesc] = $this->mapOperacao($opCode);

        $d->setNossoNumero($nossoNumero)
            ->setNumeroControle($nossoNumero)
            ->setOcorrencia($opCode)
            ->setOcorrenciaDescricao($ocorrenciaDesc)
            ->setOcorrenciaTipo($ocorrenciaTipo);

        // Formato CBR: ddmmyyyy (4 dígitos no ano) → usar 'dmY'
        if ($dataStr && strlen($dataStr) === 8) {
            $d->setDataVencimento($dataStr, 'dmY');
            $d->setDataOcorrencia($dataStr, 'dmY');
        }

        if ($ocorrenciaTipo === DetalheContract::OCORRENCIA_LIQUIDADA) {
            $d->setValor($valor);
            $d->setValorRecebido($valor);
        } elseif ($ocorrenciaTipo === DetalheContract::OCORRENCIA_ENTRADA) {
            $d->setValor($valor);
            $d->setValorRecebido(0);
        } else {
            $d->setValor(0);
            $d->setValorRecebido(0);
        }

        return $d;
    }

    protected function mapOperacao(string $code): array
    {
        return match ($code) {
            'LQB'   => [DetalheContract::OCORRENCIA_LIQUIDADA, 'Liquidação'],
            'BX'    => [DetalheContract::OCORRENCIA_BAIXADA,   'Baixa'],
            'MTV'   => [DetalheContract::OCORRENCIA_ENTRADA,   'Entrada/Registro'],
            default => [DetalheContract::OCORRENCIA_OUTROS,    $code ?: 'Outros'],
        };
    }

    protected function parseAmount(string $value): float
    {
        $value = trim($value);
        if (!$value) {
            return 0.0;
        }
        // Remove separador de milhares (.) e troca separador decimal (, → .)
        $value = str_replace(['.', ',', '*'], ['', '.', ''], $value);
        return (float) $value;
    }

    public function getDetalhes(): Collection
    {
        return collect($this->detalhes);
    }

    public function getCodigoBanco(): string
    {
        return self::COD_BANCO;
    }
}
