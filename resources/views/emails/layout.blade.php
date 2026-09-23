<!doctype html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title', config('mail.from.name'))</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0 !important; padding: 0 !important; background: #eef5f3;
            font-family: Arial, Helvetica, sans-serif; color: #17384a;
            -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;
        }
        table { border-collapse: collapse; }
        img { border: 0; outline: none; text-decoration: none; }
        a { color: #0bb995; }
        .button:hover { opacity: .94; }
        .shadow { box-shadow: 0 10px 30px rgba(11, 69, 63, .14); }
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; }
            .content { padding: 28px 22px !important; }
            .hero { padding: 34px 22px !important; }
            .footer { padding: 22px 22px 28px 22px !important; }
            .logo { width: 190px !important; }
            .mobile-small { font-size: 23px !important; }
            .detail-label, .detail-value { display: block !important; width: 100% !important; padding-right: 0 !important; }
            .detail-value { padding-top: 2px !important; padding-bottom: 10px !important; }
            .button { display: block !important; text-align: center !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .email-bg { background: #061f1d !important; }
            .container, .card { background: #102f2b !important; }
            .content, h1, p, td { color: #f0f8f6 !important; }
            .muted { color: #b9cbc8 !important; }
            .footer { background: #0a2825 !important; border-color: #235049 !important; }
        }
    </style>
</head>
<body>
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">@yield('preheader')</div>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="email-bg" style="background:#eef5f3;">
    <tr>
        <td align="center" style="padding:30px 12px;">
            <table role="presentation" class="container shadow" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;">
                <tr>
                    <td class="hero" align="center" style="background:#0b4f49;padding:44px 28px 38px 28px;">
                        <img class="logo"
                             src="{{ config('mail_notifications.branding.logo_url') ?: asset(config('mail_notifications.branding.logo_asset')) }}"
                             width="230" alt="{{ config('mail.from.name') }}"
                             style="display:block;width:230px;max-width:100%;height:auto;">
                    </td>
                </tr>

                <tr>
                    <td class="content" style="padding:38px 44px 28px 44px;font-size:16px;line-height:1.65;color:#17384a;">
                        @yield('content')
                    </td>
                </tr>

                <tr>
                    <td class="footer" style="padding:24px 44px 34px 44px;background:#f8fbfa;border-top:1px solid #dbece8;font-size:13px;line-height:1.6;color:#557080;">
                        <strong style="color:#17384a;">{{ config('mail_notifications.branding.company_name') }}</strong><br>
                        {{ config('mail_notifications.branding.company_address') }}<br>
                        Telefon: <a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) config('mail_notifications.support.phone')) }}" style="color:#0bb995;text-decoration:underline;">{{ config('mail_notifications.support.phone') }}</a><br>
                        E-Mail: <a href="mailto:{{ config('mail_notifications.support.email') }}" style="color:#0bb995;text-decoration:underline;">{{ config('mail_notifications.support.email') }}</a><br>
                        Website: <a href="{{ config('mail_notifications.branding.website_url') }}" target="_blank" style="color:#0bb995;text-decoration:underline;">{{ preg_replace('#^https?://#', '', (string) config('mail_notifications.branding.website_url')) }}</a>
                        <div class="muted" style="margin-top:14px;font-size:12px;line-height:1.6;color:#7c909a;">
                            @yield('footer-note', 'Sie erhalten diese E-Mail, weil zu Ihrem Fahrzeug im LeasyBack-Portal ein neues Ereignis vorliegt.')
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
