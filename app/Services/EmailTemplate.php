<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Company;
use App\Models\CompanyBranding;

/**
 * Shared layout of every e-mail sent by the application (account e-mails, ticket notifications,
 * transport test). render() turns one structured message into a plain-text part and an HTML part
 * built for e-mail clients: table layout, inline styles, a single CTA button with its raw link
 * repeated underneath. Deliberately plain so Outlook for Windows renders it as designed.
 * Every value is escaped; callers never pass HTML. Only absolute URLs under APP_URL are expected.
 *
 * Message keys (all optional except title):
 *  preheader  string  hidden preview line shown by mail clients
 *  eyebrow    string  small label above the title (e.g. a ticket key)
 *  title      string
 *  greeting   string
 *  paragraphs string[]
 *  chips      string[]  short labels rendered as pills (e.g. notification events)
 *  card       ['title'=>string,'rows'=>[[label,value],...]]
 *  action     ['label'=>string,'url'=>string]
 *  note       string  small text after the button (expiry, security hint)
 *  footer     [[label,url],...] links in the footer
 */
final class EmailTemplate
{
    private const ACCENT='#7061df';
    private const ACCENT_DARK='#5e4fc8';
    private const INK='#1f2330';
    private const MUTED='#6b7080';
    private const LINE='#e7e8ee';
    private const BG='#f4f3fb';
    private const SOFT='#f0edfc';

    /** @return array{text:string,html:string} */
    public static function render(array $m): array
    {
        return ['text'=>self::text($m),'html'=>self::html($m)];
    }

    private static function text(array $m): string
    {
        $out=[];
        if (!empty($m['greeting'])) $out[]=$m['greeting'];
        if (!empty($m['eyebrow'])) $out[]=$m['eyebrow'].' — '.$m['title'];
        elseif (empty($m['greeting'])) $out[]=$m['title'];
        foreach($m['paragraphs']??[] as $p) $out[]=$p;
        if (!empty($m['chips'])) $out[]=implode(' · ',$m['chips']);
        if (!empty($m['card'])) {
            $lines=[];
            if (!empty($m['card']['title'])) $lines[]=$m['card']['title'];
            foreach($m['card']['rows']??[] as [$label,$value]) $lines[]=$label.' : '.$value;
            $out[]=implode("\n",$lines);
        }
        if (!empty($m['action'])) $out[]=$m['action']['label']." :\n".$m['action']['url'];
        if (!empty($m['note'])) $out[]=$m['note'];
        $footer=[];
        foreach($m['footer']??[] as [$label,$url]) $footer[]=$label.' : '.$url;
        $footer[]='L’équipe '.Company::name().' — '.app_name();
        $out[]=implode("\n",$footer);
        return implode("\n\n",$out);
    }

    /**
     * HTML part, written for the most limited client (Outlook for Windows renders with Word):
     * tables only, padding on cells rather than links or paragraphs, no rounded corners, gradients
     * or CSS classes, a system font stack, and link colours forced on an inner span so Outlook's
     * own hyperlink style cannot repaint the button text.
     */
    private static function html(array $m): string
    {
        $a=self::ACCENT;$bg=self::BG;$ink=self::INK;$muted=self::MUTED;$line=self::LINE;$soft=self::SOFT;
        $font="font-family:'Segoe UI',Arial,Helvetica,sans-serif;";
        $company=e(Company::name());$app=e(app_name());
        $branding=CompanyBranding::get();
        $mark=$branding['logo_digest']
            ? '<img src="'.e(url('/company/logo/'.$branding['logo_digest'])).'" alt="'.$company.'" height="36" style="display:block;height:36px;width:auto;max-width:160px;border:0;">'
            : '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="36" height="36" align="center" valign="middle" bgcolor="#ffffff" style="width:36px;height:36px;background:#ffffff;color:'.$a.';'.$font.'font-size:18px;font-weight:bold;">'.e($branding['icon']??Company::initial()).'</td></tr></table>';
        $text=fn(string $value,string $style)=>'<tr><td style="'.$font.$style.'">'.$value.'</td></tr>';

        $rows='';
        if (!empty($m['eyebrow'])) $rows.=$text(e($m['eyebrow']),'padding:0 0 8px;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:'.$a.';');
        $rows.=$text(e($m['title']),'padding:0 0 18px;font-size:22px;line-height:28px;font-weight:bold;color:'.$ink.';');
        if (!empty($m['greeting'])) $rows.=$text(e($m['greeting']),'padding:0 0 12px;font-size:15px;line-height:22px;color:'.$ink.';');
        foreach($m['paragraphs']??[] as $p) $rows.=$text(e($p),'padding:0 0 12px;font-size:15px;line-height:22px;color:'.$ink.';');
        if (!empty($m['chips'])) $rows.=$text(implode(' &nbsp;&middot;&nbsp; ',array_map('e',$m['chips'])),'padding:0 0 14px;font-size:13px;font-weight:bold;color:'.self::ACCENT_DARK.';');
        if (!empty($m['card'])) {
            $card='';
            if (!empty($m['card']['title'])) $card.='<tr><td colspan="2" style="padding:12px 16px;'.$font.'font-size:15px;font-weight:bold;color:'.$ink.';">'.e($m['card']['title']).'</td></tr>';
            foreach($m['card']['rows']??[] as $i=>[$label,$value]) {
                $border=$i||!empty($m['card']['title'])?'border-top:1px solid '.$line.';':'';
                $card.='<tr><td valign="top" width="140" style="width:140px;padding:9px 12px 9px 16px;'.$border.$font.'font-size:13px;color:'.$muted.';">'.e($label).'</td><td valign="top" style="padding:9px 16px 9px 0;'.$border.$font.'font-size:13px;font-weight:bold;color:'.$ink.';">'.e($value).'</td></tr>';
            }
            $rows.='<tr><td style="padding:4px 0 20px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fafafd" style="background:#fafafd;border:1px solid '.$line.';border-left:4px solid '.$a.';">'.$card.'</table></td></tr>';
        }
        if (!empty($m['action'])) {
            $href=e($m['action']['url']);
            $rows.='<tr><td style="padding:6px 0 18px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="'.$a.'" style="background:'.$a.';padding:13px 26px;'.$font.'font-size:15px;font-weight:bold;"><a href="'.$href.'" target="_blank" style="color:#ffffff;text-decoration:none;'.$font.'font-size:15px;font-weight:bold;"><span style="color:#ffffff;">'.e($m['action']['label']).'</span></a></td></tr></table></td></tr>';
            $rows.=$text('Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br><a href="'.$href.'" style="color:'.$a.';word-break:break-all;"><span style="color:'.$a.';">'.$href.'</span></a>','padding:0 0 16px;font-size:12px;line-height:18px;color:'.$muted.';');
        }
        if (!empty($m['note'])) $rows.='<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="'.$soft.'" style="background:'.$soft.';padding:12px 14px;'.$font.'font-size:13px;line-height:19px;color:'.$muted.';">'.e($m['note']).'</td></tr></table></td></tr>';

        $links=[];
        foreach($m['footer']??[] as [$label,$url]) $links[]='<a href="'.e($url).'" style="color:'.$muted.';text-decoration:underline;"><span style="color:'.$muted.';">'.e($label).'</span></a>';
        $footer=($links?implode(' &nbsp;&middot;&nbsp; ',$links).'<br>':'').'L’équipe '.$company.' — '.$app;

        $pre=e($m['preheader']??'');
        $title=e($m['title']);
        return <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:{$bg};">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">{$pre}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{$bg}" style="background:{$bg};"><tr><td align="center" style="padding:28px 10px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
<tr><td bgcolor="{$a}" style="background:{$a};padding:18px 28px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle">{$mark}</td><td valign="middle" style="padding-left:12px;{$font}color:#ffffff;"><span style="font-size:16px;font-weight:bold;color:#ffffff;">{$company}</span><br><span style="font-size:12px;color:#ffffff;">{$app}</span></td></tr></table>
</td></tr>
<tr><td bgcolor="#ffffff" style="background:#ffffff;border:1px solid {$line};border-top:0;padding:28px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">{$rows}</table>
</td></tr>
<tr><td align="center" style="padding:18px 20px 0;{$font}font-size:12px;line-height:18px;color:{$muted};">{$footer}</td></tr>
</table></td></tr></table>
</body></html>
HTML;
    }
}
