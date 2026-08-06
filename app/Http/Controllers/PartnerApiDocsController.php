<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the generated Partner API reference from private storage.
 *
 * The bundle is built by `php artisan partner:docs` into the directory named
 * by `scribe_partner.static.output_path` — deliberately outside `public/`, so
 * the only way to it is through this controller and the gate in front of it
 * (see routes/partner_docs.php).
 *
 * Every method resolves a *fixed* filename. `asset()` is the only one that
 * takes anything from the URL, and what it takes is constrained to a known
 * directory and a `[A-Za-z0-9._-]+` filename by the route, then checked again
 * here against the resolved real path — a file server that trusts a caller's
 * path is a directory traversal waiting to be found.
 */
class PartnerApiDocsController extends Controller
{
    public function index(): Response
    {
        return response($this->read('index.html'))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            // Regenerated on demand; a cached copy is a stale contract.
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function openapi(): BinaryFileResponse
    {
        return $this->file('openapi.yaml', 'application/yaml');
    }

    public function postman(): BinaryFileResponse
    {
        return $this->file('collection.json', 'application/json');
    }

    public function events(): BinaryFileResponse
    {
        return $this->file('events.json', 'application/json');
    }

    public function asset(string $directory, string $file): BinaryFileResponse
    {
        return $this->file($directory.'/'.$file, $this->mimeFor($file));
    }

    private function file(string $relativePath, string $contentType): BinaryFileResponse
    {
        return response()->file($this->resolve($relativePath), [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents($this->resolve($relativePath));
    }

    /**
     * The single place a path is turned into a file, and the single place the
     * "is it still inside the docs directory" question is asked.
     */
    private function resolve(string $relativePath): string
    {
        $root = realpath(base_path((string) config('scribe_partner.static.output_path')));

        abort_if($root === false, 404, 'The Partner API reference has not been generated yet.');

        $path = realpath($root.DIRECTORY_SEPARATOR.$relativePath);

        abort_if($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR), 404);
        abort_unless(is_file($path), 404);

        return $path;
    }

    private function mimeFor(string $file): string
    {
        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            'css' => 'text/css',
            'js' => 'text/javascript',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
