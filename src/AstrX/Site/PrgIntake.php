<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Comment\CommentPrgHandler;
use AstrX\Http\HttpStatus;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\Http\UploadedFile;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Session\Diagnostic\InvalidPrgIdDiagnostic;
use AstrX\Session\PrgHandler;

/**
 * The site's POST/redirect/GET intake.
 *
 * Every form posts a `prg_id` naming the page that will process it. A POST
 * is never handled in place: its payload (and any uploaded files, moved to a
 * private temp path) is parked in the session under a one-time token and the
 * browser is redirected to that page with the token in the URL. The settling
 * GET then processes it, so reloads and back-button never resubmit.
 * Comment forms use their own PRG namespace so another controller on the
 * page cannot consume the token first.
 */
final class PrgIntake
{
    public const string ID_INVALID_PRG_ID = 'astrx.session/invalid_prg_id';
    public const DiagnosticLevel LVL_INVALID_PRG_ID = DiagnosticLevel::WARNING;

    public function __construct(private readonly DiagnosticsCollector $collector) {}

    /** Is this a form POST that must go through the PRG redirect? */
    public function isPrgPost(Request $request): bool
    {
        return $request->body()->all() !== [] && $request->body()->has('prg_id');
    }

    /**
     * Park the POST and send the redirect to its settling GET.
     *
     * @param \Closure(): bool $lockedDuringPanic whether a panic lockdown seals
     *        this caller (then uploaded files are not spooled to temp at all);
     *        evaluated only when the request actually carries files
     * @return HttpStatus|null null when the redirect was sent (the caller must
     *         end the request), otherwise the error status to render
     */
    public function redirect(Request $request, PrgHandler $prg, \Closure $lockedDuringPanic): ?HttpStatus
    {
        $prgIdResult = $request->body()->getString('prg_id')->drainTo($this->collector);
        $prgIdRaw = $prgIdResult->valueOr(null);
        $prgId = is_string($prgIdRaw) ? $prgIdRaw : null;

        // Comment forms include '_comment=1' in their body — route them through
        // CommentPrgHandler (separate session namespace + _cp query key) so that
        // other page controllers cannot steal the token before CommentController runs.
        $isCommentForm = $request->body()->has('_comment');

        if ($isCommentForm) {
            $commentPrg = new CommentPrgHandler();
            if ($prgId === null || !$commentPrg->hasTarget($prgId)) {
                $this->collector->emit(new InvalidPrgIdDiagnostic(
                                           self::ID_INVALID_PRG_ID,
                                           self::LVL_INVALID_PRG_ID,
                                           $prgId,
                                       ));
                return HttpStatus::BAD_REQUEST;
            }
            $token = $commentPrg->storeFromPayload($request->body()->all());
            $sendResult = Response::redirect($commentPrg->getUrl($prgId, $token))->send()
                ->drainTo($this->collector);
        } else {
            if ($prgId === null || !$prg->hasTarget($prgId)) {
                $this->collector->emit(new InvalidPrgIdDiagnostic(
                                           self::ID_INVALID_PRG_ID,
                                           self::LVL_INVALID_PRG_ID,
                                           $prgId,
                                       ));
                return HttpStatus::BAD_REQUEST;
            }
            // Persist uploaded files through the PRG cycle: move each file to a
            // persistent temp path and store its metadata in __files__ so the
            // GET side can reconstruct UploadedFile objects before routing.
            // During a lockdown a non-operator's settling GET is sealed anyway,
            // so don't move their files to temp at all — otherwise a pre-obtained
            // upload token could spool junk into the temp dir while locked. The
            // panicActive() check only runs for requests that actually carry
            // files, so the common (file-less) POST intake is untouched.
            $files = $request->files()->all();
            $lockedForActor = $files !== [] && $lockedDuringPanic();
            $payload = $request->body()->all();
            $fileMeta = [];
            foreach (($lockedForActor ? [] : $files) as $fieldName => $uploadedFile) {
                if (!$uploadedFile instanceof UploadedFile || $uploadedFile->hasError()) {
                    continue;
                }
                $tmpDest = sys_get_temp_dir() . '/astrx_upload_' . bin2hex(random_bytes(8));
                if (move_uploaded_file($uploadedFile->tempPath(), $tmpDest)) {
                    $fileMeta[(string) $fieldName] = [
                        'client_filename'   => $uploadedFile->clientFilename(),
                        'client_media_type' => $uploadedFile->clientMediaType(),
                        'temp_path'         => $tmpDest,
                        'size'              => $uploadedFile->size(),
                    ];
                }
            }
            // ALWAYS overwrite the reserved __files__ key (even with []) so a
            // user-supplied __files__ (e.g. a crafted temp_path) can never
            // survive into the stored PRG payload and later reach @unlink (PRG
            // GC) or a file read via UploadedFile::fromTempPath.
            $payload['__files__'] = $fileMeta;
            $token = $prg->storeFromPayload($payload);
            $sendResult = Response::redirect($prg->getUrl($prgId, $token))->send()
                ->drainTo($this->collector);
        }
        if (!$sendResult->isOk()) {
            return HttpStatus::INTERNAL_SERVER_ERROR;
        }
        return null;
    }

    /**
     * On the settling GET, put the files parked by redirect() back into the
     * request's FileBag (peeking at the payload, not consuming it) so
     * controllers read them through $request->files() as usual.
     */
    public function restoreUploads(Request $request, PrgHandler $prg): void
    {
    // Restore uploaded files that were persisted through the PRG cycle.
    // ContentManager stored them as __files__ in the PRG payload on POST.
    // We peek at the payload here (without consuming it) and inject the
    // reconstructed UploadedFile objects into the request FileBag so
    // controllers can read them via $request->files() as normal.
    $prgTokenForFiles = $request->query()->get($prg->tokenQueryKey());
    if (is_string($prgTokenForFiles) && $prgTokenForFiles !== '') {
        $peeked = $prg->get($prgTokenForFiles);
        if (is_array($peeked) && isset($peeked['__files__'])) {
            $filesRaw = $peeked['__files__'];
            {
                /** @var array<string,array<string,mixed>> $filesArr */
                $filesArr = $filesRaw;
                foreach ($filesArr as $fieldName => $rawMeta) {
                    /** @var array<string,mixed> $meta */
                    $meta = $rawMeta;
                    if (!array_key_exists('temp_path', $meta)) { continue; }
                    $tmpPathRaw = $meta['temp_path'] ?? '';
                    if (!is_string($tmpPathRaw) || !file_exists($tmpPathRaw)) { continue; }
                    $tmpPath   = $tmpPathRaw;
                    $clientFnR = $meta['client_filename'] ?? '';
                    $clientFn  = is_string($clientFnR) ? $clientFnR : '';
                    $mediaTypeR = $meta['client_media_type'] ?? 'application/octet-stream';
                    $mediaType = is_string($mediaTypeR) ? $mediaTypeR : 'application/octet-stream';
                    $szR = $meta['size'] ?? 0;
                    $sz  = is_int($szR) ? $szR : 0;
                    $request->files()->set(
                        (string)$fieldName,
                        UploadedFile::fromTempPath($clientFn, $mediaType, $tmpPath, $sz),
                    );
                }
            }
        }
    }
    }
}
