<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-Mail-Vorschau</title>
    <style>
        body { margin: 0; background: #eef5f3; font-family: Arial, Helvetica, sans-serif; color: #17384a; }
        .wrap { max-width: 860px; margin: 0 auto; padding: 40px 20px 60px 20px; }
        h1 { margin: 0 0 6px 0; font-size: 26px; }
        p.lead { margin: 0 0 28px 0; color: #557080; font-size: 15px; line-height: 1.6; }
        ul { list-style: none; margin: 0; padding: 0; }
        li { background: #ffffff; border: 1px solid #dbece8; border-radius: 14px; margin-bottom: 12px; }
        a.item { display: block; padding: 16px 20px; text-decoration: none; color: inherit; }
        a.item:hover { background: #fbfefd; }
        .label { font-size: 17px; font-weight: 700; }
        .meta { margin-top: 4px; font-size: 13px; color: #557080; }
        .subject { margin-top: 8px; font-size: 14px; color: #17384a; }
        code { background: #e6f8f4; color: #0b4f49; padding: 2px 6px; border-radius: 6px; font-size: 12px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>E-Mail-Vorschau</h1>
    <p class="lead">Nur in der lokalen Umgebung verfügbar. Alle Vorlagen werden mit Beispieldaten gerendert.</p>

    <ul>
        @foreach ($previews as $key => $preview)
            <li>
                <a class="item" href="{{ route('dev.emails.show', ['key' => $key]) }}" target="_blank">
                    <div class="label">{{ $preview['label'] }}</div>
                    <div class="meta"><code>{{ $preview['mailable'] }}</code></div>
                    <div class="subject">Betreff: {{ $preview['subject'] }}</div>
                </a>
            </li>
        @endforeach
    </ul>
</div>
</body>
</html>
