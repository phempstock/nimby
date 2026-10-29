<?php
declare(strict_types=1);

namespace Nimby\Digest;

use Nimby\Council\AppType;
use Nimby\Export\GeoJsonExporter;
use Nimby\Mail\EmailLayout;
use Nimby\Mail\Message;

/**
 * Writes the weekly email for one subscriber: new applications in The Park,
 * then those just outside it, each with a link to its pin on the map and to
 * the council's case file.
 */
final class DigestBuilder
{
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                            'August', 'September', 'October', 'November', 'December'];

    public function __construct(
        private string $siteUrl,
        private string $caseFileBaseUrl,
        private float $bufferMetres = 0,
        private ?string $organisation = null
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * @param list<array> $apps rows from the applications table, each with an 'inside' flag
     * @param string $weekTo the digest's end date, 'Y-m-d' (UK date)
     */
    public function message(array $apps, string $weekTo, string $to, ?string $name, string $unsubscribeUrl, bool $test = false): Message
    {
        $n = count($apps);
        $inside = array_values(array_filter($apps, fn($a) => $a['inside']));
        $outside = array_values(array_filter($apps, fn($a) => !$a['inside']));
        $week = self::longDate($weekTo);
        $where = $this->bufferMetres > 0 ? 'in and around The Park' : 'in The Park';

        $subject = sprintf('NIMBY weekly: %d new planning application%s %s', $n, $n === 1 ? '' : 's', $where);
        if ($test) {
            $subject = '[TEST] ' . $subject;
        }
        $intro = sprintf('%d new planning application%s %s appeared in the council’s data in the week to %s.',
            $n, $n === 1 ? '' : 's', $where === 'in The Park' ? 'in The Park' : 'in or near The Park', $week);

        // ----- HTML -----
        $html = ($name ? '<p style="margin:0 0 12px">Hello ' . EmailLayout::e($name) . ',</p>' : '')
            . '<p style="margin:0 0 20px">' . EmailLayout::e($intro) . '</p>';
        if ($test) {
            $html = '<p style="margin:0 0 16px;padding:10px 12px;background:#fbe7b5;border-radius:6px;font-size:15px">'
                . 'This is a test. It shows what subscribers would receive; nothing was sent to them.</p>' . $html;
        }
        if ($inside) {
            $html .= $this->sectionHtml($outside ? 'In The Park' : null, $inside);
        }
        if ($outside) {
            $html .= $this->sectionHtml('Just outside The Park, within ' . self::metres($this->bufferMetres) . ' m', $outside);
        }
        $html .= '<p style="margin:8px 0 20px">' . EmailLayout::button($this->siteUrl . '/', 'Open the NIMBY map') . '</p>';

        $footer = 'You get this email because you signed up at ' . EmailLayout::link($this->siteUrl . '/', 'nimby.parknews.co.uk')
            . '. It comes on Mondays when there are new applications.<br>'
            . EmailLayout::link($unsubscribeUrl, 'Unsubscribe') . ' &nbsp; ' . EmailLayout::link($this->siteUrl . '/privacy.php', 'Privacy notice')
            . '<br><br>Planning data from Nottingham City Council under the Open Government Licence. Pin positions are approximate; '
            . 'the council’s case file is the official record.'
            . ($this->organisation ? '<br>Run by ' . EmailLayout::e($this->organisation) . '.' : '');

        // ----- Plain text -----
        $text = ($test ? "THIS IS A TEST. It shows what subscribers would receive; nothing was sent to them.\n\n" : '')
            . ($name ? "Hello {$name},\n\n" : '') . $intro . "\n\n";
        if ($inside) {
            $text .= $this->sectionText($outside ? 'IN THE PARK' : null, $inside);
        }
        if ($outside) {
            $text .= $this->sectionText('JUST OUTSIDE THE PARK, WITHIN ' . self::metres($this->bufferMetres) . ' M', $outside);
        }
        $text .= "Map: {$this->siteUrl}/\n\n--\n"
            . "You get this email because you signed up at {$this->siteUrl}/. It comes on Mondays when there are new applications.\n"
            . "Unsubscribe: {$unsubscribeUrl}\nPrivacy notice: {$this->siteUrl}/privacy.php\n"
            . "Planning data from Nottingham City Council under the Open Government Licence.\n";

        $headers = [
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'List-Id' => 'NIMBY weekly <weekly.nimby.parknews.co.uk>',
        ];

        return new Message($to, $subject,
            $text,
            EmailLayout::wrap($html, $footer, $intro),
            $name, $headers);
    }

    private function sectionHtml(?string $heading, array $apps): string
    {
        $out = $heading ? '<h2 style="margin:24px 0 4px;font-size:19px;color:#1f3d34">' . EmailLayout::e($heading) . '</h2>' : '';
        foreach ($apps as $a) {
            $slug = GeoJsonExporter::slug((string) $a['app_ref']);
            $out .= '<div style="padding:14px 0;border-bottom:1px solid #d6dbd4">'
                . '<div style="font-size:18px;font-weight:700;line-height:1.3">' . EmailLayout::e(self::address($a['address'])) . '</div>'
                . '<div style="font-size:15px;color:#56635e;margin:2px 0 6px">'
                    . EmailLayout::e(AppType::label($a['app_type']) . ', ' . $a['app_ref']) . '</div>'
                . '<div style="margin:0 0 6px">' . nl2br(EmailLayout::e(self::proposal($a['proposal']))) . '</div>'
                . '<div style="font-size:15px;color:#56635e;margin:0 0 8px">' . EmailLayout::e(self::statusLine($a)) . '</div>'
                . '<div style="font-size:15px">' . EmailLayout::link($this->siteUrl . '/?app=' . rawurlencode($slug), 'See it on the map')
                    . ' &nbsp;&nbsp; ' . EmailLayout::link($this->caseFileBaseUrl . rawurlencode((string) $a['keyval']), 'Council case file') . '</div>'
                . '</div>';
        }
        return $out;
    }

    private function sectionText(?string $heading, array $apps): string
    {
        $out = $heading ? $heading . "\n\n" : '';
        foreach ($apps as $a) {
            $slug = GeoJsonExporter::slug((string) $a['app_ref']);
            $out .= self::address($a['address']) . "\n"
                . AppType::label($a['app_type']) . ', ' . $a['app_ref'] . "\n"
                . self::proposal($a['proposal']) . "\n"
                . self::statusLine($a) . "\n"
                . 'Map: ' . $this->siteUrl . '/?app=' . rawurlencode($slug) . "\n"
                . 'Case file: ' . $this->caseFileBaseUrl . rawurlencode((string) $a['keyval']) . "\n\n";
        }
        return $out;
    }

    /** "1 Clumber Road East, Nottingham, Nottingham City, NG7 1BD" -> "1 Clumber Road East, NG7 1BD" */
    public static function address(?string $address): string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $address)),
            fn($p) => $p !== '' && !preg_match('/^nottingham( city)?$/i', $p));
        return $parts ? implode(', ', $parts) : 'Address not given';
    }

    private static function proposal(?string $proposal): string
    {
        $p = trim((string) $proposal);
        if ($p === '') {
            return 'No description given.';
        }
        return mb_strlen($p) >= 295 ? $p . ' (continues in the case file)' : $p;
    }

    private static function statusLine(array $a): string
    {
        $status = $a['status'] ?: ($a['decision'] ?: 'Status not recorded');
        $received = $a['date_received'] ?: $a['date_valid'];
        return $status . ($received ? ', received ' . self::shortDate($received) : '');
    }

    public static function longDate(string $ymd): string
    {
        $t = strtotime($ymd . ' 12:00 UTC');
        return gmdate('l j ', $t) . self::MONTHS[(int) gmdate('n', $t) - 1];
    }

    private static function shortDate(string $ymd): string
    {
        $t = strtotime($ymd . ' 12:00 UTC');
        return gmdate('j ', $t) . substr(self::MONTHS[(int) gmdate('n', $t) - 1], 0, 3) . gmdate(' Y', $t);
    }

    private static function metres(float $m): string
    {
        return rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.');
    }
}
