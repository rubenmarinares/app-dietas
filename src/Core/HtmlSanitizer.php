<?php declare(strict_types=1);

namespace App\Core;

/** Saneador de HTML de contenido (HTMLPurifier con lista blanca de iframes y estilos). */
final class HtmlSanitizer
{
    private const CSSPROPS = [
        'text-align', 'width', 'height', 'max-width', 'float',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'color', 'background-color', 'font-weight', 'font-style', 'text-decoration',
        'vertical-align', 'line-height', 'list-style-type',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-collapse', 'white-space',
    ];

    /** Subir al cambiar la definición HTML: invalida la caché de HTMLPurifier. */
    private const DEFREV = 3;

    /** @var array<string,\HTMLPurifier> una instancia por lista de hosts */
    static private array $purifiers = [];

    /** @param string[] $iframehosts hosts permitidos para <iframe> (p. ej. www.youtube.com) */
    static public function clean(string $html, array $iframehosts = []): string
    {
        $hosts = array_values(array_unique(array_map('strtolower', $iframehosts)));
        sort($hosts);
        $key = implode('|', $hosts);
        self::$purifiers[$key] ??= self::build($hosts, $key);
        return self::$purifiers[$key]->purify($html);
    }

    /** @param string[] $hosts */
    static private function build(array $hosts, string $key): \HTMLPurifier
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Attr.EnableID', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('CSS.AllowedProperties', self::CSSPROPS);

        // Dentro del proyecto (src/cache/htmlpurifier): nunca en un /tmp compartido, donde otro
        // inquilino podría plantar definiciones serializadas.
        $cache = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'htmlpurifier';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        if (is_dir($cache) && is_writable($cache)) {
            $config->set('Cache.SerializerPath', $cache);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        if ($hosts) {
            $quoted = array_map(fn($h) => preg_quote($h, '%'), $hosts);
            $config->set('HTML.SafeIframe', true);
            $config->set('URI.SafeIframeRegexp', '%^(https?:)?//(' . implode('|', $quoted) . ')/%i');
        }

        $config->set('HTML.DefinitionID', 'app-' . md5($key));
        $config->set('HTML.DefinitionRev', self::DEFREV);
        $def = $config->maybeGetRawHTMLDefinition();
        if ($def) {
            if ($hosts) {
                $def->addAttribute('iframe', 'allowfullscreen', 'Bool');
            }
            // Vídeo HTML5; src/poster son URI (HTMLPurifier bloquea javascript:).
            $def->addElement('video', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', [
                'src' => 'URI',
                'poster' => 'URI',
                'controls' => 'Bool',
                'preload' => 'Enum#none,metadata,auto',
                'width' => 'Length',
                'height' => 'Length',
            ]);
            $def->addElement('source', 'Block', 'Flow', 'Common', [
                'src' => 'URI',
                'type' => 'Text',
            ]);
            // Anclas y tamaños con % o unidades.
            $def->addAttribute('a', 'name', 'Text');
            foreach (['img', 'table', 'td', 'th'] as $tag) {
                $def->addAttribute($tag, 'width', 'Length');
                $def->addAttribute($tag, 'height', 'Length');
            }
        }

        return new \HTMLPurifier($config);
    }
}
