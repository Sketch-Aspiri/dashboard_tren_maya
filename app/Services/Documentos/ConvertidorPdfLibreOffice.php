<?php

namespace App\Services\Documentos;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Converts .docx to PDF with LibreOffice in headless mode, so the PDF is
 * rendered from the very same file the user downloads as Word.
 *
 * Every run gets its own throw-away user profile and output folder inside
 * the private scratch dir (see DirectorioTemporal): two conversions never
 * fight over LibreOffice's profile lock, and nothing is left behind.
 * Arguments go as an array (no shell), the only paths involved are
 * server-generated, and the child process does not inherit the app's
 * secrets. Conversions are serialized through a cache lock: each one costs
 * hundreds of MB and a couple of CPU seconds, and the VPS is small.
 */
final class ConvertidorPdfLibreOffice implements ConvertidorPdf
{
    private const LOCK = 'libreoffice-conversion';

    /** Environment variables whose name looks like a secret are stripped from the child. */
    private const PATRON_SECRETO = '/KEY|PASS|SECRET|TOKEN|CREDENTIAL|DSN|DATABASE_URL/i';

    private const STDERR_MAX = 500;

    public function __construct(
        private readonly string $binario,
        private readonly int $timeoutSegundos,
    ) {}

    public function convertir(string $docx, string $pdfDestino): void
    {
        try {
            Cache::lock(self::LOCK, $this->timeoutSegundos + 30)
                ->block($this->timeoutSegundos, fn () => $this->convertirConTemporales($docx, $pdfDestino));
        } catch (LockTimeoutException) {
            Log::warning('Conversión a PDF en espera demasiado tiempo: LibreOffice está ocupado.');
            throw new ConversionPdfException('El sistema está ocupado generando otros documentos. Inténtalo de nuevo en un momento.');
        }
    }

    private function convertirConTemporales(string $docx, string $pdfDestino): void
    {
        $trabajo = DirectorioTemporal::ruta().DIRECTORY_SEPARATOR.'lo_'.Str::uuid()->toString();
        $salida = $trabajo.DIRECTORY_SEPARATOR.'out';
        File::ensureDirectoryExists($salida, 0700, true);

        try {
            $this->ejecutar($docx, $trabajo, $salida);

            $generado = $salida.DIRECTORY_SEPARATOR.pathinfo($docx, PATHINFO_FILENAME).'.pdf';
            if (! is_file($generado)) {
                Log::error('LibreOffice no produjo el PDF esperado.');
                throw new ConversionPdfException('No se pudo generar el PDF.');
            }

            File::ensureDirectoryExists(dirname($pdfDestino));
            File::move($generado, $pdfDestino);
        } finally {
            File::deleteDirectory($trabajo);
        }
    }

    private function ejecutar(string $docx, string $trabajo, string $salida): void
    {
        $proceso = new Process(
            [
                $this->binario,
                '--headless',
                '--norestore',
                '--nologo',
                '-env:UserInstallation='.$this->urlDePerfil($trabajo.DIRECTORY_SEPARATOR.'perfil'),
                '--convert-to', 'pdf:writer_pdf_Export',
                '--outdir', $salida,
                $docx,
            ],
            null,
            // LibreOffice writes to $HOME; a site user's home may be read-only.
            ['HOME' => $trabajo] + $this->variablesSecretasAnuladas(),
            null,
            $this->timeoutSegundos,
        );

        try {
            $proceso->run();
        } catch (ProcessTimedOutException) {
            Log::error('LibreOffice excedió el tiempo límite al convertir a PDF.', ['timeout' => $this->timeoutSegundos]);
            throw new ConversionPdfException('La conversión a PDF tardó demasiado.');
        } catch (\Throwable $e) {
            Log::error('No se pudo ejecutar LibreOffice.', ['error' => Str::limit($e->getMessage(), self::STDERR_MAX), 'binario' => $this->binario]);
            throw new ConversionPdfException('No se pudo generar el PDF.', 0, $e);
        }

        if (! $proceso->isSuccessful()) {
            Log::error('LibreOffice terminó con error.', [
                'codigo' => $proceso->getExitCode(),
                'stderr' => Str::limit($proceso->getErrorOutput(), self::STDERR_MAX),
            ]);
            throw new ConversionPdfException('No se pudo generar el PDF.');
        }
    }

    /**
     * Symfony Process merges the given env over the inherited one; a value
     * of false unsets that variable in the child (APP_KEY, DB_PASSWORD, …).
     *
     * @return array<string, false>
     */
    private function variablesSecretasAnuladas(): array
    {
        $anuladas = [];

        foreach (array_keys(array_merge($_ENV, getenv())) as $nombre) {
            if (preg_match(self::PATRON_SECRETO, (string) $nombre) === 1) {
                $anuladas[(string) $nombre] = false;
            }
        }

        return $anuladas;
    }

    /**
     * LibreOffice wants the profile as a file:// URL, on Windows too.
     */
    private function urlDePerfil(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        $ruta = implode('/', array_map('rawurlencode', explode('/', $ruta)));

        return 'file:///'.ltrim(str_replace('%3A', ':', $ruta), '/');
    }
}
