<?php

namespace Beyondcode\LaravelProseLinter\Linter;

use Beyondcode\LaravelProseLinter\Exceptions\LinterException;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Class Vale.
 */
class Vale
{
    /**
     * @var string
     */
    protected string $valePath;

    /**
     * @var string
     */
    protected string $valeExecutable;

    /**
     * Directory separator depending on the operating system.
     *
     * @var string
     */
    protected string $directorySeparator;

    /**
     * @throws LinterException
     */
    public function __construct()
    {
        $this->valePath = __DIR__.'/../../bin/vale-ai';
        $this->resolveValeExecutable();
        $this->writeValeIni();
        $this->handleFileSystem();
    }

    /**
     * @throws LinterException
     */
    private function resolveValeExecutable()
    {
        $this->valeExecutable = match (PHP_OS_FAMILY) {
            'Darwin' => './vale-macos ',
            'Windows' => 'vale.exe ',
            'Linux' => './vale-linux ',
            default => throw new LinterException('Operating system is not supported: ' . PHP_OS_FAMILY),
        };
    }

    private function handleFileSystem()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->directorySeparator = '\\';
        } else {
            $this->directorySeparator = '/';
        }
    }

    /**
     * @param  string  $textToLint
     * @param  string|null  $textIdentifier
     * @return array|null
     *
     * @throws LinterException
     */
    public function lintString(string $textToLint, ?string $textIdentifier = null): array|null
    {
        $process = Process::fromShellCommandline(
            $this->valeExecutable . ' --output=JSON --ext=".md" "' . $textToLint . '"'
        );

        $process->setWorkingDirectory($this->valePath);
        $process->run();

        if (!$process->isSuccessful() && $process->getOutput() === null && json_decode($process->getOutput(), true) === null) {
            throw new ProcessFailedException($process);
        }

        $result = json_decode($process->getOutput(), true);

        if (!empty($result)) {
            return LintingResult::fromJsonOutput($textIdentifier ?? 'Text', $result)->toArray();
        }
        if (!is_array($result)) {
            throw new LinterException('1 Invalid vale output: ' . print_r($process->getOutput(), true));
        }

        return null;
    }

    /**
     * @param $filePath
     * @param $textIdentifier
     * @return array|null
     *
     * @throws Exception
     */
    public function lintFile($filePath, $textIdentifier): array|null
    {
        if (!File::exists($filePath)) {
            throw new Exception('File does not exist: ' . $filePath);
        }
        $content = File::get($filePath);

        // remove quotes from the content
        $content = Str::replace('"', '', $content);

        return $this->lintString($content, $textIdentifier);
    }

    /**
     * Build .vale.ini dynamically based on the configuration.
     *
     * @throws Exception
     */
    protected function getAppliedStyles(): string
    {
        $configuredStyles = config('linter.styles', [\Beyondcode\LaravelProseLinter\Styles\Vale::class]);

        if (count($configuredStyles) == 0) {
            throw new Exception('No styles defined. Please check your config (linter.styles)!');
        }

        $styles = [];
        foreach ($configuredStyles as $configuredStyle) {
            $styleClass = new $configuredStyle();
            $styles[] = $styleClass->getStyleDirectoryName();
        }

        return implode(',', $styles);
    }

    private function writeStyles()
    {
        $stylePath = $this->valePath . '/styles';

        // clear temporary vale style directory
        File::deleteDirectory($stylePath);

        // copy resources from application styles if existing
        if (File::exists(resource_path('laravel-prose-linter'))) {
            File::copyDirectory(
                resource_path('laravel-prose-linter'),
                $stylePath
            );
        } else {
            // copy resources from default
            File::copyDirectory(__DIR__ . '/../../resources/styles', $stylePath);
        }
    }

    /**
     * Create .vale.ini during runtime.
     *
     * @throws Exception
     */
    public function writeValeIni()
    {
        $appliedStyles = $this->getAppliedStyles();

        $this->writeStyles();

        $valeIni = "
StylesPath = styles
MinAlertLevel = suggestion

[*]
BasedOnStyles = {$appliedStyles}
";
        File::put($this->valePath . '/.vale.ini', $valeIni);
    }
}
