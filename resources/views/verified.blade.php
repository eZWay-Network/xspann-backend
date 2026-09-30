<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light dark">
    <title>Email verified · {{ config('app.name') }}</title>
    <noscript>
        <meta http-equiv="refresh" content="5;url={{ config('app.frontend_url') }}">
    </noscript>
    <style>
        :root {
            --bg: #f6f7f6;
            --card: #ffffff;
            --border: #e6e9e7;
            --text: #111a15;
            --muted: #5f6b64;

            --accent: #7545e8;
            --accent-hover: #6235ca;
            --accent-soft: #eee9fc;
            --on-accent: #ffffff;

            --track: #edf0ee;
            --ring: #7545e855;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1210;
                --card: #151c18;
                --border: #232d27;
                --text: #eef3f0;
                --muted: #93a19a;

                --accent: #9a78ef;
                --accent-hover: #ae91f3;
                --accent-soft: #2b2148;
                --on-accent: #ffffff;

                --track: #1f2823;
                --ring: #9a78ef66;
            }
        }

        * {
            box-sizing: border-box;
        }

        html {
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body {
            display: grid;
            place-items: center;
            min-height: 100vh;
            min-height: 100svh;
            margin: 0;
            padding: 24px;
            color: var(--text);
            background: var(--bg);
        }

        main {
            width: 100%;
            max-width: 400px;
            padding: 40px 32px 28px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: var(--card);
            text-align: center;
            box-shadow: 0 1px 2px rgb(0 0 0 / .04), 0 12px 32px rgb(0 0 0 / .05);
        }

        .brand {
            margin: 0 0 36px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
            letter-spacing: -.1px;
        }

        .icon {
            display: grid;
            place-items: center;
            width: 56px;
            height: 56px;
            margin: 0 auto 20px;
            border-radius: 50%;
            color: var(--accent);
            background: var(--accent-soft);
        }

        .icon path {
            stroke-dasharray: 24;
            stroke-dashoffset: 24;
            animation: draw .5s .15s ease-out forwards;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
            font-weight: 650;
            letter-spacing: -.5px;
            line-height: 1.25;
        }

        .message {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .continue {
            display: block;
            margin-top: 28px;
            padding: 13px 20px;
            border-radius: 10px;
            color: var(--on-accent);
            background: var(--accent);
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: background-color .15s;
        }

        .continue:hover {
            background: var(--accent-hover);
        }

        .continue:focus-visible {
            outline: 3px solid var(--ring);
            outline-offset: 3px;
        }

        .note {
            margin: 14px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .progress {
            height: 2px;
            margin-top: 20px;
            overflow: hidden;
            border-radius: 2px;
            background: var(--track);
        }

        .progress::after {
            display: block;
            height: 100%;
            background: var(--accent);
            content: "";
            transform-origin: left;
            animation: fill 5s linear forwards;
        }

        @keyframes draw {
            to {
                stroke-dashoffset: 0;
            }
        }

        @keyframes fill {
            from {
                transform: scaleX(0);
            }

            to {
                transform: scaleX(1);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .icon path {
                animation: none;
                stroke-dashoffset: 0;
            }

            .progress::after {
                animation: none;
                transform: scaleX(1);
            }

            .continue {
                transition: none;
            }
        }

        @media (max-width: 400px) {
            main {
                padding: 32px 24px 24px;
            }
        }
    </style>
</head>

<body>
    <main aria-labelledby="title">
        <p class="brand">{{ config('app.name') }}</p>

        <div class="icon" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                <path d="m5 12.5 4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                    stroke-linejoin="round" />
            </svg>
        </div>

        <h1 id="title">Email verified</h1>
        <p class="message">Your email address is confirmed. You can now sign in to your account.</p>

        <a id="continue-link" class="continue" href="{{ config('app.frontend_url') }}">Continue to sign in</a>

        <p class="note" role="status">Redirecting in <span id="countdown">5</span>s</p>
        <div class="progress" aria-hidden="true"></div>
    </main>

    <script>
        (() => {
            const destination = document.getElementById('continue-link').href;
            const countdown = document.getElementById('countdown');
            const deadline = Date.now() + 5000;

            const timer = window.setInterval(() => {
                countdown.textContent = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            }, 250);

            window.setTimeout(() => {
                window.clearInterval(timer);
                window.location.replace(destination);
            }, 5000);
        })();
    </script>
</body>

</html>
