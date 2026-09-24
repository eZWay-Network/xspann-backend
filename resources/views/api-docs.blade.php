<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XSpann RNB API Docs</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f7f7f8;
            --panel: #ffffff;
            --text: #171717;
            --muted: #666d75;
            --line: #e5e7eb;
            --accent: #0f766e;
            --code: #111827;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        main {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        header {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-start;
            margin-bottom: 28px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 32px;
            line-height: 1.15;
        }

        p {
            margin: 0;
            color: var(--muted);
        }

        .status {
            display: grid;
            grid-template-columns: repeat(3, minmax(130px, 1fr));
            gap: 10px;
            min-width: 420px;
        }

        .metric {
            padding: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .metric span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 4px;
        }

        .metric strong {
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 18px;
        }

        .scope-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px;
            border-bottom: 1px solid var(--line);
        }

        .scope-header h2 {
            margin: 0;
            font-size: 18px;
            line-height: 1.2;
        }

        .scope-header span {
            color: var(--muted);
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            background: #fbfbfc;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        code {
            display: inline-block;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: var(--code);
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 3px 6px;
            overflow-wrap: anywhere;
        }

        .method {
            display: inline-flex;
            justify-content: center;
            min-width: 62px;
            margin: 0 4px 4px 0;
            color: #ffffff;
            background: var(--accent);
            border-radius: 6px;
            padding: 4px 7px;
            font-weight: 700;
            font-size: 12px;
        }

        .middleware {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .middleware code {
            color: var(--muted);
        }

        @media (max-width: 820px) {
            header {
                display: block;
            }

            .status {
                min-width: 0;
                grid-template-columns: 1fr;
                margin-top: 18px;
            }

            .panel {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>XSpann RNB API Docs</h1>
            <p>Laravel Blade reference for the current Phase 1 backend routes.</p>
        </div>

        <section class="status" aria-label="API status">
            <div class="metric">
                <span>Base URL</span>
                <strong>{{ $baseUrl }}</strong>
            </div>
            <div class="metric">
                <span>Video Storage</span>
                <strong>{{ $storageDisk }}</strong>
            </div>
            <div class="metric">
                <span>Queue</span>
                <strong>{{ $queueConnection }}</strong>
            </div>
        </section>
    </header>

    @foreach ($scopes as $scope => $scopeRoutes)
        <section class="panel">
            <div class="scope-header">
                <h2>{{ $scope }}</h2>
                <span>{{ $scopeRoutes->count() }} routes</span>
            </div>

            <table>
                <thead>
                <tr>
                    <th>Method</th>
                    <th>Endpoint</th>
                    <th>Controller</th>
                    <th>Middleware</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($scopeRoutes as $route)
                    <tr>
                        <td>
                            @foreach ($route['methods'] as $method)
                                <span class="method">{{ $method }}</span>
                            @endforeach
                        </td>
                        <td><code>{{ $route['uri'] }}</code></td>
                        <td><code>{{ class_basename($route['action']) }}</code></td>
                        <td>
                            <div class="middleware">
                                @foreach ($route['middleware'] as $middleware)
                                    <code>{{ $middleware }}</code>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
</main>
</body>
</html>
