<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status Update</title>
</head>
<body style="margin:0;padding:0;background:#eef5f3;font-family:Arial,Helvetica,sans-serif;color:#17384a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef5f3;">
        <tr>
            <td align="center" style="padding:30px 12px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;">
                    <tr>
                        <td align="center" style="background:#0b4f49;padding:46px 28px 40px 28px;">
                            <img src="https://d1sge3z4c43rq6.cloudfront.net/logo_leasyback.png" width="360" alt="LeasyBack" style="display:block;max-width:100%;height:auto;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:38px 44px 24px 44px;font-size:16px;line-height:1.65;">
                            <h1 style="margin:0 0 22px 0;font-size:28px;line-height:1.25;color:#17384a;font-weight:700;">
                                Neues Update zu Ihrem Fahrzeug
                            </h1>
                            <p>Hallo {{ $firstName }},</p>
                            <p>es gibt ein neues Update zu Ihrem Leasingfahrzeug mit dem Kennzeichen <strong>{{ $licensePlate }}</strong>.</p>
                            @if($actionUrl)
                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0;">
                                <tr>
                                    <td align="center" bgcolor="#0bb995" style="border-radius:13px;">
                                        <a href="{{ $actionUrl }}" target="_blank" style="display:inline-block;padding:18px 36px;font-size:18px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:13px;">
                                            Zum Portal
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            @endif
                            <p>Freundliche Grüße<br>Ihr Leasyback-Team</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 44px 34px 44px;background:#f8fbfa;border-top:1px solid #dbece8;font-size:13px;line-height:1.6;color:#557080;">
                            <strong style="color:#17384a;">LeasyBack GmbH</strong><br>
                            Rolshover Str. 45, 51105 Köln, NRW, Deutschland<br>
                            Telefon: <a href="tel:+4922188750755" style="color:#0bb995;">0221 88750755</a><br>
                            E-Mail: <a href="mailto:Office@Leasyback.com" style="color:#0bb995;">Office@Leasyback.com</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
