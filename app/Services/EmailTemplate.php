<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Company;
use App\Models\CompanyBranding;

/**
 * Shared layout of every e-mail sent by the application (account e-mails, ticket notifications,
 * transport test). render() turns one structured message into a plain-text part and an HTML part
 * built for e-mail clients: table layout, inline styles, a single CTA button with its raw link
 * repeated underneath, and an optional dark-mode refinement for clients that support it.
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

    private static function html(array $m): string
    {
        $a=self::ACCENT;$bg=self::BG;$ink=self::INK;$muted=self::MUTED;$line=self::LINE;
        $font="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
        $company=e(Company::name());$app=e(app_name());
        $branding=CompanyBranding::get();
        $mark=$branding['logo_digest']
            ? '<img src="'.e(url('/company/logo/'.$branding['logo_digest'])).'" alt="'.$company.'" height="36" style="display:block;height:36px;width:auto;max-width:160px;border:0;border-radius:8px;background:#ffffff;">'
            : '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="36" height="36" align="center" valign="middle" style="width:36px;height:36px;border-radius:10px;background:#ffffff;color:'.$a.';'.$font.'font-size:17px;font-weight:700;line-height:36px;">'.e($branding['icon']??Company::initial()).'</td></tr></table>';

        $body='';
        if (!empty($m['eyebrow'])) $body.='<p class="eyebrow" style="margin:0 0 8px;'.$font.'font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:'.$a.';">'.e($m['eyebrow']).'</p>';
        $body.='<h1 class="ink" style="margin:0 0 18px;'.$font.'font-size:22px;line-height:1.3;font-weight:700;color:'.$ink.';">'.e($m['title']).'</h1>';
        if (!empty($m['greeting'])) $body.='<p class="ink" style="margin:0 0 14px;'.$font.'font-size:15px;line-height:1.6;color:'.$ink.';">'.e($m['greeting']).'</p>';
        foreach($m['paragraphs']??[] as $p) $body.='<p class="ink" style="margin:0 0 14px;'.$font.'font-size:15px;line-height:1.6;color:'.$ink.';">'.e($p).'</p>';
        if (!empty($m['chips'])) {
            $chips='';
            foreach($m['chips'] as $chip) $chips.='<span class="chip" style="display:inline-block;margin:0 6px 6px 0;padding:4px 10px;border-radius:999px;background:'.self::SOFT.';color:'.self::ACCENT_DARK.';'.$font.'font-size:12px;font-weight:600;">'.e($chip).'</span>';
            $body.='<p style="margin:4px 0 12px;">'.$chips.'</p>';
        }
        if (!empty($m['card'])) {
            $rows='';
            if (!empty($m['card']['title'])) $rows.='<tr><td colspan="2" class="ink" style="padding:0 0 10px;'.$font.'font-size:15px;font-weight:600;line-height:1.45;color:'.$ink.';">'.e($m['card']['title']).'</td></tr>';
            foreach($m['card']['rows']??[] as [$label,$value]) $rows.='<tr><td valign="top" class="muted" style="padding:6px 12px 6px 0;width:130px;'.$font.'font-size:13px;color:'.$muted.';border-top:1px solid '.$line.';">'.e($label).'</td><td valign="top" class="ink" style="padding:6px 0;'.$font.'font-size:13px;font-weight:600;color:'.$ink.';border-top:1px solid '.$line.';">'.e($value).'</td></tr>';
            $body.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="card" style="margin:6px 0 22px;background:#fafafd;border:1px solid '.$line.';border-left:4px solid '.$a.';border-radius:10px;"><tr><td style="padding:16px 18px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'.$rows.'</table></td></tr></table>';
        }
        if (!empty($m['action'])) {
            $href=e($m['action']['url']);
            $body.='<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 22px;"><tr><td align="center" bgcolor="'.$a.'" style="border-radius:10px;background:'.$a.';"><a href="'.$href.'" target="_blank" style="display:inline-block;padding:13px 26px;'.$font.'font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">'.e($m['action']['label']).' &rarr;</a></td></tr></table>';
            $body.='<p class="muted" style="margin:0 0 14px;'.$font.'font-size:12px;line-height:1.5;color:'.$muted.';">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br><a href="'.$href.'" style="color:'.$a.';word-break:break-all;">'.$href.'</a></p>';
        }
        if (!empty($m['note'])) $body.='<p class="note" style="margin:0;padding:12px 14px;border-radius:8px;background:'.self::BG.';'.$font.'font-size:13px;line-height:1.5;color:'.$muted.';">'.e($m['note']).'</p>';

        $links=[];
        foreach($m['footer']??[] as [$label,$url]) $links[]='<a href="'.e($url).'" style="color:'.$muted.';text-decoration:underline;">'.e($label).'</a>';
        $footer=($links?implode(' &nbsp;·&nbsp; ',$links).'<br>':'').'L’équipe '.$company.' — '.$app;

        $pre=e($m['preheader']??'');
        $title=e($m['title']);
        return <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>{$title}</title>
<style>
@media (max-width:620px){.shell{width:100%!important}.pad{padding:24px 20px!important}}
@media (prefers-color-scheme:dark){
 body,.bg{background:#14161d!important}.panel{background:#1c1f29!important;border-color:#2b2f3c!important}
 .ink{color:#e8e9f0!important}.muted,.foot{color:#9a9eb0!important}
 .card{background:#22252f!important;border-color:#2b2f3c!important}.card td{border-color:#2b2f3c!important}
 .note{background:#22252f!important;color:#b5b8c8!important}.chip{background:#302a48!important;color:#c9bfff!important}.eyebrow{color:#a292fa!important}
}
</style></head>
<body style="margin:0;padding:0;background:{$bg};">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$pre}&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg" style="background:{$bg};"><tr><td align="center" style="padding:32px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="shell" style="width:600px;max-width:600px;">
<tr><td style="background:{$a};background-image:linear-gradient(135deg,{$a} 0%,#8f7cf0 100%);border-radius:16px 16px 0 0;padding:20px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td valign="middle">{$mark}</td><td valign="middle" style="padding-left:12px;{$font}color:#ffffff;"><div style="font-size:16px;font-weight:700;line-height:1.2;">{$company}</div><div style="font-size:12px;opacity:.85;line-height:1.4;">{$app}</div></td></tr></table>
</td></tr>
<tr><td class="panel pad" style="background:#ffffff;border:1px solid {$line};border-top:0;border-radius:0 0 16px 16px;padding:32px;">{$body}</td></tr>
<tr><td class="foot" align="center" style="padding:20px 24px 0;{$font}font-size:12px;line-height:1.6;color:{$muted};">{$footer}</td></tr>
</table></td></tr></table>
</body></html>
HTML;
    }
}
