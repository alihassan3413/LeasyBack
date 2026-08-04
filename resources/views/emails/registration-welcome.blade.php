<!doctype html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <meta name="color-scheme" content="light dark">
  <title>Willkommen im Leasyback-Portal</title>
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
      .hero { padding: 36px 22px !important; }
      .logo { width: 190px !important; }
      .mobile-small { font-size: 24px !important; }
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
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
    Ihre Registrierung im Leasyback-Portal war erfolgreich.
  </div>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="email-bg" style="background:#eef5f3;">
    <tr>
      <td align="center" style="padding:30px 12px;">
        <table role="presentation" class="container shadow" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;">
          <!-- Header -->
          <tr>
            <td class="hero" align="center" style="background:#0b4f49;padding:46px 28px 40px 28px;">
              <img class="logo"
                src="{{ config('mail_notifications.branding.logo_url') ?: asset(config('mail_notifications.branding.logo_asset')) }}"
                width="230" alt="{{ config('mail.from.name') }}" style="display:block;width:230px;max-width:100%;height:auto;">
            </td>
          </tr>

          <!-- Content -->
          <tr>
            <td class="content" style="padding:38px 44px 24px 44px;font-size:16px;line-height:1.65;">
              <div style="display:inline-block;margin:0 0 14px 0;padding:7px 13px;border-radius:999px;background:#e6f8f4;color:#0b4f49;font-size:13px;font-weight:700;">
                Registrierung erfolgreich
              </div>

              <h1 class="mobile-small" style="margin:0 0 22px 0;font-size:28px;line-height:1.25;color:#17384a;font-weight:700;">
                Willkommen im Leasyback-Portal
              </h1>

              <p style="margin:0 0 16px 0;">Hallo {{ $userName }},</p>
              <p style="margin:0 0 16px 0;">herzlich willkommen im Leasyback-Portal.</p>
              <p style="margin:0 0 16px 0;">Ihre Registrierung war erfolgreich.</p>
              <p style="margin:0 0 16px 0;">Ab sofort können Sie sich im Portal anmelden und alle relevanten Informationen zu Ihrem Leasingfahrzeug einsehen.</p>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" class="card" style="margin:24px 0;border:1px solid #dbece8;border-radius:14px;background:#fbfefd;">
                <tr>
                  <td style="padding:18px 18px;font-size:15px;line-height:1.6;color:#17384a;">
                    <strong>Ihre Portal-Registrierung ist abgeschlossen.</strong><br>
                    Zugang: ab sofort möglich
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 16px 0;">Zum Portal gelangen Sie über folgenden Link:</p>

              <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 18px 0;">
                <tr>
                  <td align="center" bgcolor="#0bb995" style="border-radius:13px;">
                    <a class="button" href="{{ $loginUrl }}" target="_blank" style="display:inline-block;padding:18px 36px;font-size:18px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:13px;">
                      Zum Portal
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 16px 0;">Vielen Dank.</p>
              <p style="margin:0;">
                Freundliche Grüße<br>
                Ihr Leasyback-Team
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td class="footer" style="padding:24px 44px 34px 44px;background:#f8fbfa;border-top:1px solid #dbece8;font-size:13px;line-height:1.6;color:#557080;">
              <strong style="color:#17384a;">LeasyBack GmbH</strong><br>
              Rolshover Str. 45, 51105 Köln, NRW, Deutschland<br>
              Telefon: <a href="tel:+4922188750755" style="color:#0bb995;text-decoration:underline;">0221 88750755</a><br>
              E-Mail: <a href="mailto:Office@Leasyback.com" style="color:#0bb995;text-decoration:underline;">Office@Leasyback.com</a><br>
              Website: <a href="https://www.leasyback.com" target="_blank" style="color:#0bb995;text-decoration:underline;">www.leasyback.com</a><br>
              Registernummer: HRB 120068<br>
              Registergericht: Köln<br>
              USt-ID: DE452645584<br>
              Geschäftsführer: Jannis Luca Gremler, Massih Mosayer
              <div class="muted" style="margin-top:14px;font-size:12px;color:#7c909a;">
                Sie erhalten diese E-Mail, weil Ihre Registrierung im Leasyback-Portal abgeschlossen wurde.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
