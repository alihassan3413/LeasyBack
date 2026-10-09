<?php

namespace App\Support;

use Illuminate\Mail\Message;

/**
 * The logo at the top of every email.
 *
 * Embedded in the message itself (cid:) while sending, so it shows however
 * APP_URL is set and whether or not the mail client may load remote images;
 * a plain URL only where there is no message to embed into (a browser
 * preview). MAIL_LOGO_URL, when set, is used as is.
 */
final class MailLogo
{
    public static function src(mixed $message = null): string
    {
        $configured = config('mail_notifications.branding.logo_url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $asset = (string) config('mail_notifications.branding.logo_asset');
        $file = public_path($asset);

        if ($message instanceof Message && is_file($file)) {
            return $message->embed($file);
        }

        return asset($asset);
    }
}
