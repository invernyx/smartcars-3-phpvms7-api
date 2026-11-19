<?php

namespace Modules\SmartCARS3phpVMS7Api\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS3phpVMS7Api\Support\AutoUpdateSettings;
use Modules\SmartCARS3phpVMS7Api\Support\AutoUpdateSource;
use ParseError;
use RuntimeException;
use ZipArchive;

/**
 * Installs the latest published release of the module: the nudge is only a trigger, so a replayed,
 * redelivered or out-of-order nudge can never pick the code. Download, lint, swap with a backup,
 * migrate, clear caches, check GET / over HTTP, and put the backup back when anything after the
 * swap fails. A release that was rolled back is not tried again until a newer one is published or the
 * failed-commit marker file is deleted.
 * @package Modules\SmartCARS3phpVMS7Api\Jobs
 */
class AutoUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MODULE = 'SmartCARS3phpVMS7Api';
    private const COMMIT_FILE = 'Support/InstalledCommit.php';
    private const FAILED_COMMIT_FILE = 'failed-commit';
    private const UNKNOWN_VERSION = 'unknown';
    // phpVMS runs queue:work with its default 60 s timeout, which kills the process without running
    // finally or the rollback; these keep the whole install well under it.
    private const HEAD_TIMEOUT_SEC = 4;
    private const STALE_HEAD_RETRY_SEC = 3;
    private const DOWNLOAD_TIMEOUT_SEC = 20;
    private const CHECK_TIMEOUT_SEC = 10;

    public $tries = 1;

    public ?string $tagHint = null;

    public function __construct(?string $tagHint = null)
    {
        $this->tagHint = $tagHint;
    }

    public function handle(): void
    {
        // With the sync queue this runs inside the nudge's web request: a closed connection must not abort the install.
        ignore_user_abort(true);
        if (!AutoUpdateSettings::isEnabled()) {
            return;
        }

        $workDir = storage_path('app/smartcars-auto-update');
        File::ensureDirectoryExists($workDir);
        $pendingFile = $workDir.'/pending';
        // A lock file, not Cache::lock: the cache clear during the install would drop a cache lock.
        $lock = fopen($workDir.'/update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            File::put($pendingFile, (string) $this->tagHint);
            Log::info('smartCARS auto-update: an update is running; another release check is queued behind it');
            return;
        }

        try {
            File::delete($pendingFile);
            $this->run($workDir);
        } finally {
            File::deleteDirectory($workDir.'/incoming');
            File::delete($workDir.'/archive.zip');
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if (File::exists($pendingFile)) {
            $tagHint = trim((string) File::get($pendingFile));
            self::dispatch($tagHint === '' ? null : $tagHint);
        }
    }

    private function run(string $workDir): void
    {
        $installed = AutoUpdateSettings::installedCommit();
        try {
            $release = $this->latestRelease();
            // GitHub's API can answer from a cache for up to a minute: a nudge for another release
            // that reports the installed commit as the latest is asked about once more before it
            // is skipped.
            if ($release !== null && $release['sha'] === $installed
                && $this->tagHint !== null && $this->tagHint !== $release['tag']) {
                sleep(self::STALE_HEAD_RETRY_SEC);
                $release = $this->latestRelease();
            }
        } catch (\Throwable $e) {
            $this->recordFailure('', 'failed before install: '.$e->getMessage());
            return;
        }
        if ($release === null) {
            AutoUpdateSettings::saveStatus('', 'skipped: no release published');
            return;
        }
        $sha = $release['sha'];
        if ($sha === $installed) {
            AutoUpdateSettings::saveStatus($sha, 'skipped: already installed');
            return;
        }
        $failedCommitFile = $workDir.'/'.self::FAILED_COMMIT_FILE;
        if (File::exists($failedCommitFile)) {
            $marker = explode("\n", (string) File::get($failedCommitFile), 2);
            if (trim($marker[0]) === $sha) {
                $previousOutcome = trim($marker[1] ?? '');
                AutoUpdateSettings::saveStatus($sha, 'skipped: this release was rolled back before'
                    .($previousOutcome === '' ? '' : ' ('.$previousOutcome.')'));
                return;
            }
        }
        $this->install($workDir, $sha);
    }

    private function latestRelease(): ?array
    {
        $response = Http::timeout(self::HEAD_TIMEOUT_SEC)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(AutoUpdateSource::latestReleaseUrl());
        if ($response->status() === 404) {
            return null;
        }
        $tag = $response->successful() ? $response->json('tag_name') : null;
        if (!is_string($tag) || $tag === '') {
            throw new RuntimeException('could not read the latest release (HTTP '.$response->status().')');
        }

        $response = Http::timeout(self::HEAD_TIMEOUT_SEC)
            ->withHeaders(['Accept' => 'application/vnd.github.sha'])
            ->get(AutoUpdateSource::commitUrl($tag));
        $sha = trim($response->body());
        if (!$response->successful() || !preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new RuntimeException('could not read the commit of release '.$tag.' (HTTP '.$response->status().')');
        }

        return ['tag' => $tag, 'sha' => $sha];
    }

    private function install(string $workDir, string $sha): void
    {
        $moduleDir = base_path('modules/'.self::MODULE);
        if (!is_writable($moduleDir) || !is_writable(dirname($moduleDir))) {
            $this->recordFailure($sha, 'refused: the module folder is not writable by the web server user');
            return;
        }

        try {
            $newDir = $this->download($workDir, $moduleDir, $sha);
            $this->lint($newDir);
            File::put($newDir.'/'.self::COMMIT_FILE, "<?php\n\nreturn '".$sha."';\n");
        } catch (\Throwable $e) {
            $this->recordFailure($sha, 'failed before install: '.$e->getMessage());
            return;
        }
        $newVersion = $this->moduleVersion($newDir);
        $currentVersion = $this->moduleVersion($moduleDir);
        if ($newVersion !== self::UNKNOWN_VERSION && $currentVersion !== self::UNKNOWN_VERSION
            && version_compare($newVersion, $currentVersion, '<')) {
            $this->recordFailure($sha, 'refused: release '.$newVersion.' is older than the installed '.$currentVersion, true);
            return;
        }

        $backupDir = $workDir.'/backup-'.now()->format('YmdHis');
        try {
            $this->move($moduleDir, $backupDir);
        } catch (\Throwable $e) {
            $this->recordFailure($sha, 'failed before install: '.$e->getMessage());
            return;
        }

        try {
            $this->move($newDir, $moduleDir);
            $exitCode = Artisan::call('module:migrate', ['module' => self::MODULE, '--force' => true]);
            if ($exitCode !== 0) {
                throw new RuntimeException('module:migrate exited with '.$exitCode);
            }
            $this->clearCaches();
            $this->checkHealth($sha);
        } catch (\Throwable $e) {
            $this->rollBack($workDir, $moduleDir, $backupDir, $sha, $e->getMessage());
            return;
        }

        AutoUpdateSettings::saveCommit($sha);
        File::delete($workDir.'/'.self::FAILED_COMMIT_FILE);
        AutoUpdateSettings::saveStatus($sha, 'updated to version '.$newVersion);
        Log::info('smartCARS auto-update: installed the release at '.$sha.' (version '.$newVersion.')');
        $this->pruneBackups($workDir, $backupDir);
    }

    private function download(string $workDir, string $moduleDir, string $sha): string
    {
        $zipPath = $workDir.'/archive.zip';
        $incoming = $workDir.'/incoming';
        File::deleteDirectory($incoming);

        $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SEC)
            ->withOptions(['sink' => $zipPath])
            ->get(AutoUpdateSource::archiveUrl($sha));
        if (!$response->successful()) {
            throw new RuntimeException('download answered '.$response->status());
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('the archive could not be opened');
        }
        $isExtracted = $zip->extractTo($incoming);
        $zip->close();
        if (!$isExtracted) {
            throw new RuntimeException('the archive could not be extracted');
        }

        $roots = File::directories($incoming);
        if (count($roots) !== 1) {
            throw new RuntimeException('the archive has '.count($roots).' top folders, expected 1');
        }
        $newDir = $roots[0];
        if ($this->moduleName($newDir) !== $this->moduleName($moduleDir)) {
            throw new RuntimeException('the archive is not this module');
        }

        return $newDir;
    }

    // The health check only loads the routes file, so a syntax error anywhere else (a controller,
    // this job) would install as healthy; parse every file before touching the live module.
    private function lint(string $dir): void
    {
        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            try {
                token_get_all(File::get($file->getPathname()), TOKEN_PARSE);
            } catch (ParseError $e) {
                throw new RuntimeException('syntax error in '.$file->getRelativePathname().' line '.$e->getLine());
            }
        }
    }

    private function move(string $from, string $to): void
    {
        if (File::exists($to)) {
            throw new RuntimeException(basename($to).' already exists');
        }
        if (@rename($from, $to)) {
            return;
        }
        // rename() fails across filesystems (storage/ and modules/ may be on different mounts).
        if (!File::copyDirectory($from, $to)) {
            File::deleteDirectory($to);
            throw new RuntimeException('could not copy '.basename($from));
        }
        // deleteDirectory() reports success even when some files stay behind.
        File::deleteDirectory($from);
        if (File::exists($from)) {
            throw new RuntimeException('could not remove '.basename($from).' after copying it');
        }
    }

    private function clearCaches(): void
    {
        foreach (['cache:clear', 'route:clear', 'config:clear', 'view:clear'] as $command) {
            Artisan::call($command);
        }
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    private function checkHealth(string $sha): void
    {
        $url = rtrim((string) config('app.url'), '/').'/api/smartcars';
        $response = Http::timeout(self::CHECK_TIMEOUT_SEC)->acceptJson()->get($url);
        if ($response->status() !== 200) {
            throw new RuntimeException('health check answered '.$response->status());
        }
        $handler = $response->json('handler');
        $commit = $response->json('commit');
        if ($handler !== 'phpvms7') {
            throw new RuntimeException('health check got handler '.json_encode($handler));
        }
        if ($commit !== $sha) {
            // With opcache.validate_timestamps=0 the web server keeps the old code until PHP-FPM restarts.
            throw new RuntimeException('health check reports commit '.json_encode($commit)
                .' instead of '.$sha.' (the web server may be serving cached PHP code)');
        }
    }

    private function rollBack(string $workDir, string $moduleDir, string $backupDir, string $sha, string $reason): void
    {
        try {
            File::deleteDirectory($moduleDir);
            if (File::exists($moduleDir)) {
                throw new RuntimeException('could not remove the new module folder');
            }
            $this->move($backupDir, $moduleDir);
            $this->clearCaches();
            $outcome = 'rolled back: '.$reason;
        } catch (\Throwable $e) {
            $outcome = 'ROLLBACK FAILED, restore '.$backupDir.' by hand: '.$reason.'; '.$e->getMessage();
        }
        File::put($workDir.'/'.self::FAILED_COMMIT_FILE, $sha."\n".$outcome);
        $this->recordFailure($sha, $outcome);
    }

    private function pruneBackups(string $workDir, string $keep): void
    {
        foreach (File::directories($workDir) as $dir) {
            if ($dir !== $keep && str_starts_with(basename($dir), 'backup-')) {
                File::deleteDirectory($dir);
            }
        }
    }

    private function recordFailure(string $sha, string $outcome, bool $isRepeating = false): void
    {
        AutoUpdateSettings::saveStatus($sha, $outcome);
        $message = 'smartCARS auto-update'.($sha === '' ? '' : ' '.$sha).': '.$outcome;
        if ($isRepeating) {
            Log::warning($message);
        } else {
            Log::error($message);
        }
    }

    private function moduleName(string $dir): ?string
    {
        return $this->moduleJson($dir)['name'] ?? null;
    }

    private function moduleVersion(string $dir): string
    {
        return (string) ($this->moduleJson($dir)['version'] ?? self::UNKNOWN_VERSION);
    }

    private function moduleJson(string $dir): array
    {
        $path = $dir.'/module.json';

        return File::exists($path) ? (json_decode(File::get($path), true) ?? []) : [];
    }
}
