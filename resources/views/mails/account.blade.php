<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $subject ?? config('app.name') }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            width: 100% !important;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        table {
            border-collapse: collapse;
        }

        img {
            border: 0;
            line-height: 100%;
            outline: none;
            text-decoration: none;
            max-width: 100%;
            height: auto;
        }

        /* Styles for the HTML passed in via $body */
        .content p {
            margin: 0 0 16px;
        }

        .content h1,
        .content h2,
        .content h3 {
            margin: 0 0 16px;
            color: #111827;
            line-height: 1.3;
        }

        .content a {
            color: #4f46e5;
            text-decoration: underline;
        }

        .content ul,
        .content ol {
            margin: 0 0 16px;
            padding-left: 20px;
        }

        .content hr {
            border: 0;
            border-top: 1px solid #e5e7eb;
            margin: 24px 0;
        }

        .content .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #4f46e5;
            color: #ffffff !important;
            text-decoration: none;
            font-weight: 600;
            border-radius: 8px;
        }

        @media only screen and (max-width: 620px) {
            .container {
                width: 100% !important;
            }

            .px {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
        }

        @media (prefers-color-scheme: dark) {
            .bg-page {
                background-color: #0f172a !important;
            }

            .bg-card {
                background-color: #1e293b !important;
            }

            .content,
            .content h1,
            .content h2,
            .content h3 {
                color: #e2e8f0 !important;
            }

            .muted {
                color: #94a3b8 !important;
            }

            .border {
                border-color: #334155 !important;
            }
        }
    </style>
</head>

<body class="bg-page" style="margin:0; padding:0; background-color:#f3f4f6;">

    {{-- Hidden preheader text shown in inbox previews --}}
    @isset($preheader)
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
            {{ $preheader }}
        </div>
    @endisset

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-page"
        style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                    class="container" style="width:600px; max-width:600px;">

                    {{-- Header --}}
                    <tr>
                        <td align="center" style="padding:0 0 24px;">
                            <a href="{{ config('app.frontend_url') }}" target="_blank"
                                style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:22px; font-weight:700; color:#111827; text-decoration:none; letter-spacing:-0.3px;">
                                {{ config('app.name') }}
                            </a>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td class="bg-card border"
                            style="background-color:#ffffff; border:1px solid #e5e7eb; border-radius:12px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="content px"
                                        style="padding:40px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:1.6; color:#374151;">
                                        {!! $body !!}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" class="muted px"
                            style="padding:24px 16px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.5; color:#6b7280;">
                            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            @isset($footer)
                                <br>{!! $footer !!}
                            @endisset
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>

</html>
