<?php
declare(strict_types=1);

namespace Nimby\Mail;

/**
 * The HTML frame shared by every NIMBY email: a railing-green masthead, the
 * content, and a quiet footer. Table layout and inline styles, because many
 * email programs ignore anything else.
 */
final class EmailLayout
{
    public const FONT = "'Alegreya Sans', 'Gill Sans', 'Segoe UI', Arial, sans-serif";

    public static function wrap(string $contentHtml, string $footerHtml, string $preheader = ''): string
    {
        $font = self::FONT;
        $pre = $preheader === '' ? '' :
            '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . self::e($preheader) . '</div>';
        return <<<HTML
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>NIMBY</title>
</head>
<body style="margin:0;padding:0;background:#f2f3ef;color:#1d2925;font-family:{$font}">
{$pre}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f3ef">
<tr><td align="center" style="padding:24px 12px">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:6px;overflow:hidden">
    <tr><td style="background:#1f3d34;color:#f2f3ef;padding:20px 28px">
      <div style="font-size:30px;line-height:1;font-weight:800;letter-spacing:2px">NIMBY</div>
      <div style="font-size:17px;margin-top:6px">Planning watch for The Park</div>
    </td></tr>
    <tr><td style="padding:24px 28px 8px;font-size:17px;line-height:1.5">
{$contentHtml}
    </td></tr>
    <tr><td style="padding:16px 28px 24px;font-size:14px;line-height:1.5;color:#56635e;border-top:1px solid #d6dbd4">
{$footerHtml}
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    public static function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** A plain link in railing green. */
    public static function link(string $url, string $label): string
    {
        return '<a href="' . self::e($url) . '" style="color:#1f3d34;font-weight:700">' . self::e($label) . '</a>';
    }

    /** A filled button-style link. */
    public static function button(string $url, string $label): string
    {
        return '<a href="' . self::e($url) . '" style="display:inline-block;background:#1f3d34;color:#f2f3ef;text-decoration:none;'
            . 'font-weight:700;padding:12px 20px;border-radius:6px">' . self::e($label) . '</a>';
    }
}
