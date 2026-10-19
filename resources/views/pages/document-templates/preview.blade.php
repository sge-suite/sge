<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Visualização do template</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; background: #e4e4e7; color: #18181b; }
        .paper { margin: 0 auto; background: #fff; box-shadow: 0 1px 8px #0002; font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.2; display: flex; flex-direction: column; }
        .document-header { margin-bottom: 24pt; }
        .document-body { flex: 1; }
        .document-footer { margin-top: 32pt; }
        p { min-height: 1em; margin: 0 0 8pt; white-space: pre-wrap; overflow-wrap: anywhere; }
        img { max-width: 100%; height: auto; vertical-align: middle; }
        table { width: 100%; border-collapse: collapse; margin: 10pt 0; table-layout: fixed; }
        td { padding: 5pt 6pt; vertical-align: top; overflow-wrap: anywhere; }
        td > p:last-child { margin-bottom: 0; }
        .error { max-width: 44rem; margin: 2rem auto; padding: 1.5rem; background: #fff; border-radius: 8px; font: 16px/1.5 Arial, sans-serif; }
    </style>
</head>
<body>
    @if ($error)
        <div class="error" role="alert"><strong>Não foi possível abrir a prévia</strong><p>{{ $error }}</p></div>
    @elseif ($preview)
        <article class="paper" style="{{ $preview['page_style'] }}" aria-label="Conteúdo original do template">
            @if ($preview['header'])<header class="document-header">@foreach ($preview['header'] as $node)<x-document-template-preview-node :node="$node" />@endforeach</header>@endif
            <main class="document-body">@foreach ($preview['body'] as $node)<x-document-template-preview-node :node="$node" />@endforeach</main>
            @if ($preview['footer'])<footer class="document-footer">@foreach ($preview['footer'] as $node)<x-document-template-preview-node :node="$node" />@endforeach</footer>@endif
        </article>
    @endif
</body>
</html>
