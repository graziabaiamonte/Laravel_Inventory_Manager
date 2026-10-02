<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

class UserManualController extends Controller
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        // Configure CommonMark with GitHub Flavored Markdown
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Display the documentation index (README.md)
     */
    public function index()
    {
        return $this->show('readme');
    }

    /**
     * Display a specific documentation page
     */
    public function show($page)
    {
        // Sanitize page name to prevent directory traversal
        $page = basename($page);

        // Convert to uppercase for file lookup
        $fileName = strtoupper($page);

        $filePath = base_path("docs/user-manual/{$fileName}.md");

        if (! File::exists($filePath)) {
            abort(404, 'Pagina di documentazione non trovata');
        }

        $markdown = File::get($filePath);

        // Convert markdown to HTML
        $html = $this->converter->convert($markdown)->getContent();

        // Fix image paths: img/xxx.png -> /user-manual/image/xxx.png (authenticated route)
        $html = preg_replace_callback(
            '/src="img\/([^"]+)"/',
            function ($matches) {
                return 'src="'.route('user-manual.image', $matches[1]).'"';
            },
            $html
        );

        // Fix internal markdown links: RECORDS.md -> /user-manual/records
        $html = preg_replace_callback(
            '/href="([A-Z_]+)\.md"/',
            function ($matches) {
                return 'href="'.route('user-manual.show', strtolower($matches[1])).'"';
            },
            $html
        );

        // Fix README.md links specifically
        $html = str_replace(
            'href="'.route('user-manual.show', 'readme').'"',
            'href="'.route('user-manual.index').'"',
            $html
        );

        $title = $this->getPageTitle($fileName);

        return view('user-manual', [
            'content' => $html,
            'title' => $title,
            'isIndex' => $fileName === 'README',
        ]);
    }

    /**
     * Serve documentation images (authenticated)
     */
    public function image($filename)
    {
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);

        $imagePath = base_path("docs/user-manual/img/{$filename}");

        if (! File::exists($imagePath)) {
            abort(404, 'Immagine non trovata');
        }

        $mimeType = File::mimeType($imagePath);

        return response()->file($imagePath, [
            'Content-Type' => $mimeType,
        ]);
    }

    /**
     * Get a friendly title for the page
     */
    private function getPageTitle(string $page): string
    {
        $titles = [
            'README' => 'Guida all\'utilizzo',
            'RECORDS' => 'Gestione Records',
            'VENDITE' => 'Gestione Vendite',
            'CARICHI' => 'Gestione Carichi',
            'SCARICO_BACKORDER' => 'Scarico e Backorder',
            'LOCATIONS' => 'Gestione Locations',
            'AREE' => 'Gestione Aree',
            'UTENTI' => 'Gestione Utenti',
            'ARTISTI' => 'Gestione Artisti',
            'ETICHETTE' => 'Gestione Etichette',
            'FORMATI' => 'Gestione Formati',
            'CLIENTI' => 'Gestione Clienti',
            'FORNITORI' => 'Gestione Fornitori',
            'DISCOGS' => 'Sincronizzazione Discogs',
        ];

        return $titles[$page] ?? ucfirst(strtolower(str_replace('_', ' ', $page)));
    }
}
