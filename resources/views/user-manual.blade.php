<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} - Manuale Utente</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/github-markdown-css/5.5.1/github-markdown.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif;
            background-color: #f6f8fa;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 980px;
            margin: 0 auto;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 16px;
            color: #0969da;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .markdown-body {
            box-sizing: border-box;
            min-width: 200px;
            max-width: 980px;
            margin: 0 auto;
            padding: 45px;
            background-color: #ffffff;
            border: 1px solid #d0d7de;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <div class="container">
        @unless($isIndex)
        <a href="{{ route('user-manual.index') }}" class="back-link">
            ← Torna all'indice
        </a>
        @endunless
        <div class="markdown-body">
            {!! $content !!}
        </div>
    </div>
</body>
</html>
