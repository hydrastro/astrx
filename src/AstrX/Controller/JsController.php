<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlGenerator;
use AstrX\Theme\ThemeService;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function AstrX\Support\cacheDir;
use function AstrX\Support\templateDir;

/**
 * Experimental client-side browser/runtime.
 *
 * URL namespace:
 *   /<locale>/js/               shell document
 *   /<locale>/js/<page...>      shell document for a JS-side route
 *   /<locale>/js/runtime.js     generated runtime
 *   /<locale>/js/manifest.json  page/site manifest
 *   /<locale>/js/templates.js   compiled template bundle for the runtime
 *   /<locale>/js/templates.json raw template cache/debug fallback
 *   /<locale>/js/api.json       API endpoint index for the JS runtime/debugging
 *
 * This intentionally does NOT replace the normal PHP-rendered site. The
 * ordinary /<locale>/<page> pages remain the canonical, JS-less path. The
 * /js/ namespace is a miniLOL-inspired browser: one boot page, a small
 * resource loader, a client template renderer, and a router that browses the
 * PHP site from inside the client runtime.
 */
final class JsController extends AbstractController
{
    private const ASSET_RUNTIME  = 'runtime.js';
    private const ASSET_MANIFEST = 'manifest.json';
    private const ASSET_TPLS     = 'templates.json';
    private const ASSET_TPLS_JS  = 'templates.js';
    private const ASSET_API      = 'api.json';

    private const CACHE_RUNTIME_MAX_AGE   = 604800;   // 7 days; ETag still gates upgrades.
    private const CACHE_MANIFEST_MAX_AGE  = 86400;    // 1 day; page DB changes are ETag-gated.
    private const CACHE_TEMPLATES_MAX_AGE = 2592000;  // 30 days; template fingerprint is content-derived.
    private const CACHE_API_INDEX_MAX_AGE = 3600;     // 1 hour; admin/API exposure can change.

    public function __construct(
        DiagnosticsCollector      $collector,
        private readonly Request  $request,
        private readonly CurrentUrl $currentUrl,
        private readonly Config   $config,
        private readonly Translator $translator,
        private readonly UrlGenerator $urlGenerator,
        private readonly ThemeService $themeService,
        private readonly PDO $pdo,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $tail = $this->currentUrl->tail();
        $first = $tail[0] ?? '';

        match ($first) {
            self::ASSET_RUNTIME  => $this->emitRuntimeJs(),
            self::ASSET_MANIFEST => $this->emitManifestJson(),
            self::ASSET_TPLS     => $this->emitTemplatesJson(),
            self::ASSET_TPLS_JS  => $this->emitTemplatesJs(),
            self::ASSET_API      => $this->emitApiIndexJson(),
            default              => $this->emitShell(),
        };

        exit;
    }

    private function locale(): string
    {
        $lang = $this->currentUrl->get('lang', $this->translator->getLocale());
        return is_scalar($lang) && (string) $lang !== '' ? (string) $lang : 'en';
    }

    private function routePrefix(): string
    {
        if (!defined('ASTRX_COMPILED_ROUTE_PREFIX')) {
            return '';
        }
        $prefix = '/' . trim((string) constant('ASTRX_COMPILED_ROUTE_PREFIX'), '/');
        return $prefix === '/' ? '' : $prefix;
    }

    private function jsBasePath(): string
    {
        $basePath = rtrim($this->config->getConfigString('Routing', 'base_path', '/'), '/');
        if ($basePath === '') {
            $basePath = '';
        }
        return $this->routePrefix() . $basePath . '/' . rawurlencode($this->locale()) . '/js';
    }

    private function siteLocaleBasePath(): string
    {
        $basePath = rtrim($this->config->getConfigString('Routing', 'base_path', '/'), '/');
        if ($basePath === '') {
            $basePath = '';
        }
        return $this->routePrefix() . $basePath . '/' . rawurlencode($this->locale());
    }

    private function currentRoutePath(): string
    {
        $tail = $this->currentUrl->tail();
        if ($tail === []) {
            return '';
        }
        $first = $tail[0] ?? '';
        if (in_array($first, [self::ASSET_RUNTIME, self::ASSET_MANIFEST, self::ASSET_TPLS, self::ASSET_TPLS_JS, self::ASSET_API], true)) {
            return '';
        }
        return implode('/', array_map('rawurlencode', $tail));
    }

    private function emitShell(): void
    {
        $started = microtime(true);
        $locale = $this->html($this->locale());
        $siteName = $this->html($this->siteName());
        $runtime = $this->html($this->jsBasePath() . '/' . self::ASSET_RUNTIME);
        $templatesJs = $this->html($this->jsBasePath() . '/' . self::ASSET_TPLS_JS);
        $route = $this->html($this->currentRoutePath());

        $manifestPayload = [
            'ok'           => true,
            'version'      => 3,
            'locale'       => $this->locale(),
            'siteName'     => $this->siteName(),
            'siteBase'     => $this->siteLocaleBasePath(),
            'jsBase'       => $this->jsBasePath(),
            'defaultRoute' => $this->defaultRouteSlug(),
            'pages'        => $this->manifestPages(),
            'api'          => [
                'index' => $this->jsBasePath() . '/' . self::ASSET_API,
                'base'  => $this->siteLocaleBasePath() . '/api',
            ],
        ];
        $manifestPayload['shellContext'] = $this->shellContext($manifestPayload['pages']);
        $manifestJson = $this->json($manifestPayload);

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-AstrX-JS-Browser: shell');
            $this->emitServerTiming('astrx_js_shell', $started);
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="{$locale}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <link rel="preload" href="{$templatesJs}" as="script">
  <link rel="preload" href="{$runtime}" as="script">
  <title>{$siteName} — JS</title>
  <script>
  window.AstrXJSInlineManifest = {$manifestJson};
  window.AstrXJSEarlyPreload = { startedAt: Date.now(), manifest: Promise.resolve(window.AstrXJSInlineManifest) };
  </script>
</head>
<body data-astrx-js-route="{$route}">
  <noscript>To use the /js/ browser you need JavaScript enabled. The normal site remains available without JavaScript.</noscript>
  <div id="astrx-js-boot">Loading templates…</div>
  <script defer src="{$templatesJs}"></script>
  <script defer src="{$runtime}"></script>
</body>
</html>
HTML;
    }

    private function emitRuntimeJs(): void
    {
        $started = microtime(true);
        $boot = [
            'locale'       => $this->locale(),
            'jsBase'       => $this->jsBasePath(),
            'siteBase'     => $this->siteLocaleBasePath(),
            'siteName'     => $this->siteName(),
            'defaultRoute' => $this->defaultRouteSlug(),
            'assets'       => [
                'manifest'    => $this->jsBasePath() . '/' . self::ASSET_MANIFEST,
                'templates'   => $this->jsBasePath() . '/' . self::ASSET_TPLS,
                'templatesJs' => $this->jsBasePath() . '/' . self::ASSET_TPLS_JS,
                'api'         => $this->jsBasePath() . '/' . self::ASSET_API,
            ],
        ];
        $bootJson = $this->json($boot);
        $etag = '"astrx-js-runtime-' . sha1($bootJson . '|v11') . '"';

        if ($this->etagMatches($etag)) {
            http_response_code(304);
            if (!headers_sent()) {
                $this->emitServerTiming('astrx_js_runtime', $started);
            }
            exit;
        }

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/javascript; charset=utf-8');
            header('Cache-Control: private, max-age=' . self::CACHE_RUNTIME_MAX_AGE . ', stale-while-revalidate=86400');
            header('ETag: ' . $etag);
            header('X-AstrX-JS-Browser: runtime');
            $this->emitServerTiming('astrx_js_runtime', $started);
        }

        echo 'window.AstrXJSBoot = ' . $bootJson . ";\n";
        echo <<<'JS'
(function () {
  'use strict';

  const BOOT = window.AstrXJSBoot || {};

  function byId(id) { return document.getElementById(id); }
  function enc(s) { return encodeURIComponent(String(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }
  function trimSlashes(s) { return String(s || '').replace(/^\/+|\/+$/g, ''); }
  function sameOrigin(url) {
    try { return new URL(url, location.href).origin === location.origin; }
    catch (_) { return false; }
  }
  function pathJoin() {
    return Array.prototype.slice.call(arguments)
      .filter(Boolean)
      .map((part, i) => i === 0 ? String(part).replace(/\/+$/g, '') : trimSlashes(part))
      .join('/');
  }

  const Mustache = (function () {
    function tokenize(src) {
      const tokens = [];
      let i = 0;
      while (i < src.length) {
        const open = src.indexOf('{{', i);
        if (open < 0) {
          if (i < src.length) tokens.push({ type: 'text', value: src.slice(i) });
          break;
        }
        if (open > i) tokens.push({ type: 'text', value: src.slice(i, open) });
        const close = src.indexOf('}}', open + 2);
        if (close < 0) {
          tokens.push({ type: 'text', value: src.slice(open) });
          break;
        }
        let body = src.slice(open + 2, close).trim();
        let type = 'var';
        if (/^[#\/\^>&!=]/.test(body)) {
          type = body.charAt(0);
          body = body.slice(1).trim();
          if (type === '=') {
            const eq = body.lastIndexOf('=');
            if (eq >= 0) body = body.slice(0, eq).trim();
          }
        }
        tokens.push({ type: type, value: body });
        i = close + 2;
      }
      return tokens;
    }

    function nest(tokens) {
      const root = [];
      const stack = [{ name: null, nodes: root }];
      tokens.forEach(token => {
        const top = stack[stack.length - 1];
        if (token.type === '#' || token.type === '^') {
          const node = { type: token.type, value: token.value, nodes: [] };
          top.nodes.push(node);
          stack.push({ name: token.value, nodes: node.nodes });
        } else if (token.type === '/') {
          if (stack.length > 1) stack.pop();
        } else if (token.type !== '!' && token.type !== '=') {
          top.nodes.push(token);
        }
      });
      return root;
    }

    function resolve(name, ctx, parent, index) {
      if (name === '.') {
        if (Array.isArray(parent)) return parent[index];
        return parent;
      }
      const deref = (name.match(/^\*+/) || [''])[0].length;
      const clean = name.slice(deref);
      const parts = clean.split('.').filter(Boolean);

      function from(obj) {
        let cur = obj;
        for (let i = 0; i < parts.length; i++) {
          if (cur == null || typeof cur !== 'object' || !(parts[i] in cur)) return undefined;
          cur = cur[parts[i]];
        }
        return cur;
      }

      let value;
      if (parent && typeof parent === 'object' && !Array.isArray(parent)) value = from(parent);
      if (value === undefined && Array.isArray(parent) && parent[index] && typeof parent[index] === 'object') value = from(parent[index]);
      if (value === undefined) value = from(ctx);

      for (let i = 0; i < deref; i++) {
        if (typeof value === 'string' && Object.prototype.hasOwnProperty.call(ctx, value)) {
          value = ctx[value];
        } else {
          return undefined;
        }
      }
      return value;
    }

    function truthy(value) {
      if (Array.isArray(value)) return value.length > 0;
      return !!value;
    }

    function renderNodes(nodes, ctx, partials, parent, index, compiledPartials) {
      let out = '';
      nodes.forEach(node => {
        if (node.type === 'text') {
          out += node.value;
        } else if (node.type === 'var') {
          out += esc(resolve(node.value, ctx, parent, index));
        } else if (node.type === '&') {
          const v = resolve(node.value, ctx, parent, index);
          out += v == null ? '' : String(v);
        } else if (node.type === '>') {
          const partialName = resolve(node.value, ctx, parent, index) || node.value;
          const partial = partials[String(partialName)] || '';
          out += renderCompiled((compiledPartials && compiledPartials[String(partialName)]) || compile(partial), ctx, partials, parent, index, compiledPartials);
        } else if (node.type === '#') {
          const value = resolve(node.value, ctx, parent, index);
          if (Array.isArray(value)) {
            value.forEach((item, i) => out += renderNodes(node.nodes, ctx, partials, value, i, compiledPartials));
          } else if (truthy(value)) {
            out += renderNodes(node.nodes, ctx, partials, value && typeof value === 'object' ? value : parent, index, compiledPartials);
          }
        } else if (node.type === '^') {
          const value = resolve(node.value, ctx, parent, index);
          if (!truthy(value)) out += renderNodes(node.nodes, ctx, partials, parent, index, compiledPartials);
        }
      });
      return out;
    }

    function compile(src) {
      return nest(tokenize(String(src || '')));
    }

    function compileAll(partials) {
      const out = {};
      Object.keys(partials || {}).forEach(name => {
        out[name] = compile(partials[name]);
      });
      return out;
    }

    function renderCompiled(ast, ctx, partials, parent, index, compiledPartials) {
      return renderNodes(ast || [], ctx || {}, partials || {}, parent || null, index || 0, compiledPartials || null);
    }

    function render(src, ctx, partials, parent, index, compiledPartials) {
      return renderCompiled(compile(src), ctx, partials, parent, index, compiledPartials);
    }

    function renderName(name, ctx, partials, compiledPartials) {
      partials = partials || {};
      compiledPartials = compiledPartials || compileAll(partials);
      const ast = compiledPartials[name] || compile(partials[name] || '');
      return renderCompiled(ast, ctx, partials, null, 0, compiledPartials);
    }

    return { render: render, compile: compile, compileAll: compileAll, renderCompiled: renderCompiled, renderName: renderName };
  })();

  const App = {
    manifest: null,
    templates: null,
    compiledTemplates: null,
    apiIndex: null,
    resourceStats: {},
    profile: {},
    currentTargetUrl: null,

    async start() {
      const bootStart = performance.now();
      try {
        await this.loadResources();
        this.profile.bootResourcesMs = Math.round(performance.now() - bootStart);
        this.renderDocumentShell();
        this.bind();
        await this.openFromLocation({ replace: true });
      } catch (err) {
        this.fatal(err);
      }
    },

    async loadResources() {
      this.status('Loading manifest and compiled templates …');
      const early = window.AstrXJSEarlyPreload || {};
      const [manifest, templatesPayload] = await Promise.all([
        this.getJsonResource('manifest', BOOT.assets.manifest, early.manifest, true),
        this.getTemplateBundle()
      ]);
      this.manifest = manifest;
      this.templates = templatesPayload.templates || templatesPayload;

      const compileStarted = performance.now();
      this.compiledTemplates = Mustache.compileAll(this.templates || {});
      this.profile.templateCompileMs = Math.round(performance.now() - compileStarted);

      this.apiIndex = null;
      if (this.debugEnabled()) {
        this.apiIndex = await this.getJsonResource('api', BOOT.assets.api, null, false);
      }
      this.resourceStats.templateCount = this.templates ? Object.keys(this.templates).length : 0;
      this.resourceStats.compiledTemplateCount = this.compiledTemplates ? Object.keys(this.compiledTemplates).length : 0;
      this.resourceStats.preloadedAt = early.startedAt || null;
    },

    async getTemplateBundle() {
      const started = performance.now();
      const bundle = window.AstrXJSTemplateBundle || null;
      if (bundle && bundle.templates && typeof bundle.templates === 'object') {
        this.resourceStats.templates = {
          ok: true,
          source: 'templates-js',
          fingerprint: bundle.fingerprint || null,
          generatedAt: bundle.generatedAt || null,
          ms: Math.round(performance.now() - started)
        };
        return bundle;
      }

      // Fallback for older/cached shells or if templates.js failed to load.
      try {
        const payload = await this.fetchJson(BOOT.assets.templates);
        this.resourceStats.templates = {
          ok: true,
          source: 'json-fallback',
          fingerprint: payload && payload.fingerprint ? payload.fingerprint : null,
          ms: Math.round(performance.now() - started)
        };
        return payload;
      } catch (err) {
        this.resourceStats.templates = {
          ok: false,
          source: 'failed',
          message: err && err.message ? err.message : String(err),
          ms: Math.round(performance.now() - started)
        };
        throw err;
      }
    },

    async getJsonResource(name, url, earlyPromise, required) {
      const started = performance.now();
      try {
        const payload = earlyPromise ? await earlyPromise : await this.fetchJson(url);
        this.resourceStats[name] = {
          ok: true,
          source: earlyPromise ? 'early-preload' : 'runtime-fetch',
          ms: Math.round(performance.now() - started)
        };
        return payload;
      } catch (earlyErr) {
        if (earlyPromise) {
          try {
            const payload = await this.fetchJson(url);
            this.resourceStats[name] = {
              ok: true,
              source: 'runtime-refetch',
              ms: Math.round(performance.now() - started)
            };
            return payload;
          } catch (fallbackErr) {
            this.resourceStats[name] = {
              ok: false,
              source: 'failed',
              message: fallbackErr && fallbackErr.message ? fallbackErr.message : String(fallbackErr),
              ms: Math.round(performance.now() - started)
            };
            if (required) throw fallbackErr;
            return null;
          }
        }
        this.resourceStats[name] = {
          ok: false,
          source: 'failed',
          message: earlyErr && earlyErr.message ? earlyErr.message : String(earlyErr),
          ms: Math.round(performance.now() - started)
        };
        if (required) throw earlyErr;
        return null;
      }
    },

    async fetchJson(url) {
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('Could not load ' + url + ' (HTTP ' + res.status + ')');
      return await res.json();
    },

    renderDocumentShell() {
      const started = performance.now();
      const templates = Object.assign({}, this.templates, {
        '__astrx_js_content': '<div id="astrx-js-content"></div>'
      });
      const layout = templates['default'];
      if (!layout) throw new Error('Template cache does not contain default.html');

      const renderStarted = performance.now();
      const html = Mustache.renderName('default', this.manifest.shellContext || {}, templates, this.compiledTemplates);
      this.profile.shellTemplateRenderMs = Math.round(performance.now() - renderStarted);

      const parseStarted = performance.now();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      this.profile.shellParseMs = Math.round(performance.now() - parseStarted);

      this.installDocumentHead(parsed);
      document.title = parsed.title || ((BOOT.siteName || 'AstrX') + ' — JS');
      document.body.innerHTML = parsed.body ? parsed.body.innerHTML : html;

      const boot = byId('astrx-js-boot');
      if (boot) boot.remove();
      this.ensureRuntimeMarker();
      this.profile.shellInstallMs = Math.round(performance.now() - started);
    },

    ensureRuntimeMarker() {
      let marker = byId('astrx-js-runtime-marker');
      if (!marker) {
        marker = document.createElement('div');
        marker.id = 'astrx-js-runtime-marker';
        marker.hidden = true;
        marker.textContent = 'AstrX JS runtime active';
        document.body.appendChild(marker);
      }

      if (!byId('astrx-js-runtime-style')) {
        const style = document.createElement('style');
        style.id = 'astrx-js-runtime-style';
        style.textContent = [
          '#astrx-js-status{position:fixed;left:1rem;bottom:1rem;z-index:9998;max-width:min(36rem,calc(100vw - 2rem));padding:.45rem .7rem;border-radius:.45rem;background:rgba(0,0,0,.82);color:#fff;font:12px/1.35 monospace;box-shadow:0 .35rem 1rem rgba(0,0,0,.25)}',
          '#astrx-js-status:empty{display:none}',
          '#astrx-js-debug-toggle{position:fixed;right:1rem;bottom:1rem;z-index:9999;padding:.45rem .65rem;border-radius:999px;border:1px solid currentColor;background:rgba(0,0,0,.82);color:#fff;font:700 12px/1 monospace;cursor:pointer}',
          '#astrx-js-debug{position:fixed;right:1rem;bottom:3.5rem;z-index:9999;width:min(34rem,calc(100vw - 2rem));max-height:min(32rem,calc(100vh - 5rem));overflow:auto;padding:1rem;border:1px solid currentColor;border-radius:.65rem;background:rgba(0,0,0,.9);color:#fff;box-shadow:0 .75rem 2rem rgba(0,0,0,.35);font:12px/1.4 monospace}',
          '#astrx-js-debug h2{margin:0 0 .75rem;font:700 13px/1.2 monospace;color:inherit}',
          '#astrx-js-debug pre{white-space:pre-wrap;word-break:break-word;margin:0}',
          '#astrx-js-debug-close{float:right;border:1px solid currentColor;background:transparent;color:inherit;border-radius:.35rem;cursor:pointer;font:inherit}'
        ].join('');
        document.head.appendChild(style);
      }

      if (!byId('astrx-js-status')) {
        const status = document.createElement('div');
        status.id = 'astrx-js-status';
        status.setAttribute('aria-live', 'polite');
        document.body.appendChild(status);
      }

      let toggle = byId('astrx-js-debug-toggle');
      if (!toggle) {
        toggle = document.createElement('button');
        toggle.id = 'astrx-js-debug-toggle';
        toggle.type = 'button';
        toggle.hidden = true;
        toggle.textContent = 'JS';
        document.body.appendChild(toggle);
      }

      let debug = byId('astrx-js-debug');
      if (!debug) {
        debug = document.createElement('aside');
        debug.id = 'astrx-js-debug';
        debug.hidden = true;
        debug.setAttribute('aria-live', 'polite');
        debug.innerHTML = '<button type="button" id="astrx-js-debug-close">hide</button><h2>AstrX JS runtime</h2><pre></pre>';
        document.body.appendChild(debug);
      }

      if (!toggle.dataset.bound) {
        toggle.dataset.bound = '1';
        toggle.addEventListener('click', () => {
          const panel = byId('astrx-js-debug');
          if (panel) panel.hidden = !panel.hidden;
        });
      }

      const close = byId('astrx-js-debug-close');
      if (close && !close.dataset.bound) {
        close.dataset.bound = '1';
        close.addEventListener('click', () => {
          localStorage.removeItem('astrx.debug');
          const panel = byId('astrx-js-debug');
          const btn = byId('astrx-js-debug-toggle');
          if (panel) panel.hidden = true;
          if (btn) btn.hidden = true;
        });
      }
    },

    installDocumentHead(doc) {
      if (!doc || !doc.head) return;

      const managed = doc.head.querySelectorAll(
        'style, link[rel~="stylesheet"], link[rel~="icon"], meta[name="description"], meta[name="keywords"], meta[name="robots"]'
      );

      // Content-only JS-browser fragments intentionally do not carry a full
      // <head>. Preserve the shell/theme head in that case instead of deleting
      // the current stylesheet and leaving the runtime unstyled.
      if (managed.length === 0) return;

      document.head.querySelectorAll('[data-astrx-js-head]').forEach(node => node.remove());

      managed.forEach(node => {
        const clone = node.cloneNode(true);
        clone.setAttribute('data-astrx-js-head', '');

        if (clone.tagName === 'LINK' && clone.hasAttribute('href')) {
          const raw = clone.getAttribute('href') || '';
          if (raw && !/^(data:|https?:|\/)/i.test(raw)) {
            clone.setAttribute('href', '/' + trimSlashes(raw));
          }
        }

        document.head.appendChild(clone);
      });
    },

    bind() {
      window.addEventListener('popstate', () => this.openFromLocation({ replace: true }));
      document.addEventListener('click', event => this.onClick(event));
      document.addEventListener('submit', event => this.onSubmit(event));
    },

    normalizeRoute(route) {
      route = trimSlashes(route || '');

      // The JS runtime reserves these names inside /<locale>/js/.
      // In particular, /js without a locale is routed by PHP as the JS page,
      // but the browser location is still /js. Without this guard the client
      // would treat that URL as the inner route named "js", fetch /<locale>/js,
      // then canonicalise to /<locale>/js/js and lose the normal page CSS.
      if (
        route === 'js' ||
        route === 'runtime.js' ||
        route === 'manifest.json' ||
        route === 'templates.json' ||
        route === 'templates.js' ||
        route === 'api.json'
      ) {
        route = '';
      }

      return route || BOOT.defaultRoute || 'main';
    },

    routeFromLocation() {
      const jsBase = new URL(BOOT.jsBase + '/', location.origin).pathname.replace(/\/+$/,'');
      let path = location.pathname;

      if (path === jsBase || path.indexOf(jsBase + '/') === 0) {
        path = path.slice(jsBase.length);
      } else {
        // If the shell was reached through an unlocalised alias like /js, trust
        // the route that PHP put on the boot document instead of re-parsing the
        // browser path as an inner JS route.
        const bootRoute = document.body ? document.body.getAttribute('data-astrx-js-route') : null;
        if (bootRoute !== null) {
          path = bootRoute;
        }
      }

      path = trimSlashes(decodeURIComponent(path));
      return this.normalizeRoute(path);
    },

    targetUrlForRoute(route) {
      route = this.normalizeRoute(route);
      return pathJoin(BOOT.siteBase, route) + location.search;
    },

    jsUrlForTarget(url) {
      const u = new URL(url, location.href);
      const siteBase = new URL(BOOT.siteBase + '/', location.origin).pathname.replace(/\/+$/,'');
      let route = '';
      if (u.pathname === siteBase || u.pathname === siteBase + '/') {
        route = BOOT.defaultRoute || 'main';
      } else if (u.pathname.indexOf(siteBase + '/') === 0) {
        route = trimSlashes(u.pathname.slice(siteBase.length));
      } else {
        return null;
      }
      route = this.normalizeRoute(route);
      return pathJoin(BOOT.jsBase, route) + u.search + u.hash;
    },

    normalUrlFromHref(href) {
      if (!sameOrigin(href)) return null;
      const u = new URL(href, location.href);
      const jsBase = new URL(BOOT.jsBase + '/', location.origin).pathname.replace(/\/+$/,'');
      const siteBase = new URL(BOOT.siteBase + '/', location.origin).pathname.replace(/\/+$/,'');

      if (u.pathname === jsBase || u.pathname.indexOf(jsBase + '/') === 0) {
        let route = trimSlashes(u.pathname.slice(jsBase.length));
        route = this.normalizeRoute(route);
        return pathJoin(BOOT.siteBase, route) + u.search + u.hash;
      }
      if (u.pathname === siteBase || u.pathname.indexOf(siteBase + '/') === 0) {
        return u.pathname + u.search + u.hash;
      }
      return null;
    },

    async openFromLocation(options) {
      const route = this.routeFromLocation();
      const target = this.targetUrlForRoute(route);
      if (options && options.replace) {
        const canonical = pathJoin(BOOT.jsBase, route) + location.search + location.hash;
        if (location.pathname + location.search + location.hash !== canonical) {
          history.replaceState({}, '', canonical);
        }
      }
      await this.loadTarget(target);
    },

    async navigateToNormalUrl(url) {
      const jsUrl = this.jsUrlForTarget(url);
      if (!jsUrl) {
        location.href = url;
        return;
      }
      history.pushState({}, '', jsUrl);
      await this.loadTarget(url);
    },

    async loadTarget(url) {
      const navStarted = performance.now();
      this.currentTargetUrl = url;
      this.status('Loading ' + url + ' …');
      const fetchStarted = performance.now();
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: {
          'Accept': 'text/html',
          'X-AstrX-JS-Browser': '1'
        }
      });
      this.profile.lastFetchResponseMs = Math.round(performance.now() - fetchStarted);
      this.profile.lastFetchStatus = res.status;
      this.profile.lastFetchServerTiming = res.headers.get('Server-Timing') || '';
      this.profile.lastFetchRenderMode = res.headers.get('X-AstrX-JS-Browser') || '';
      if (!res.ok) throw new Error('HTTP ' + res.status + ' while loading ' + url);

      const textStarted = performance.now();
      const text = await res.text();
      this.profile.lastFetchReadMs = Math.round(performance.now() - textStarted);
      this.profile.lastFetchBytes = text.length;
      const finalUrl = res.url || url;

      this.syncHistoryToNormalUrl(finalUrl, true);
      this.applyHtmlDocument(text, finalUrl);
      this.profile.lastNavigationTotalMs = Math.round(performance.now() - navStarted);
    },

    applyHtmlDocument(text, url) {
      const parseStarted = performance.now();
      const doc = new DOMParser().parseFromString(text, 'text/html');
      this.profile.lastDomParseMs = Math.round(performance.now() - parseStarted);
      const fragment = doc.getElementById('astrx-js-fragment');

      const installStarted = performance.now();
      this.installDocumentHead(doc);
      const title = doc.title || (fragment ? fragment.getAttribute('data-title') : '');
      if (title) document.title = title + ' — JS';
      if (doc.body) this.rewriteRelativeUrls(doc.body, url);
      this.installDocumentChrome(doc);
      this.installMainContent(doc, text);
      this.ensureRuntimeMarker();
      this.markActive(url);
      this.status('');
      this.profile.lastDomInstallMs = Math.round(performance.now() - installStarted);
      this.updateDebug(url);
      window.scrollTo(0, 0);
    },

    installDocumentChrome(doc) {
      // Keep the JS runtime shell, but make its chrome match the normal PHP page.
      // This preserves the public/user/admin menu sections and flash/message bar
      // exactly as the JS-less version rendered them for the current session.
      this.replaceOrRemoveById(doc, 'header', false);
      this.replaceOrRemoveById(doc, 'top_nav', false);
      this.replaceOrRemoveById(doc, 'user_top_nav', true);
      this.replaceOrRemoveById(doc, 'admin_top_nav', true);
      this.replaceOrRemoveById(doc, 'message_bar', true);
      this.replaceOrRemoveById(doc, 'footer', false);
    },

    replaceOrRemoveById(doc, id, removeWhenMissing) {
      const fresh = doc.getElementById(id);
      const current = byId(id);
      if (fresh && current) {
        current.replaceWith(fresh.cloneNode(true));
        return;
      }
      if (fresh && !current) {
        this.insertChromeNode(id, fresh.cloneNode(true));
        return;
      }
      if (!fresh && current && removeWhenMissing) current.remove();
    },

    insertChromeNode(id, node) {
      const wrap = byId('wrap') || document.body;
      const main = byId('main');
      const footer = byId('footer');
      const header = byId('header');
      const topNav = byId('top_nav');
      const userNav = byId('user_top_nav');

      if (id === 'header') {
        wrap.insertBefore(node, wrap.firstChild);
      } else if (id === 'top_nav') {
        wrap.insertBefore(node, main || footer || null);
      } else if (id === 'user_top_nav') {
        wrap.insertBefore(node, byId('admin_top_nav') || main || footer || null);
      } else if (id === 'admin_top_nav') {
        wrap.insertBefore(node, main || footer || null);
      } else if (id === 'message_bar') {
        wrap.insertBefore(node, main || footer || null);
      } else if (id === 'footer') {
        wrap.appendChild(node);
      } else if (header || topNav || userNav || main || footer) {
        wrap.insertBefore(node, main || footer || null);
      } else {
        wrap.appendChild(node);
      }
    },

    installMainContent(doc, fallbackHtml) {
      const freshMain = doc.getElementById('main');
      const currentMain = byId('main');
      if (freshMain && currentMain) {
        currentMain.innerHTML = freshMain.innerHTML;
        return;
      }
      if (freshMain && !currentMain) {
        const wrap = byId('wrap') || document.body;
        const footer = byId('footer');
        wrap.insertBefore(freshMain.cloneNode(true), footer || null);
        return;
      }

      const source = doc.querySelector('#wrap') || doc.body;
      const target = byId('astrx-js-content') || currentMain || document.body;
      target.innerHTML = source ? source.innerHTML : fallbackHtml;
    },

    syncHistoryToNormalUrl(url, replace) {
      const jsUrl = this.jsUrlForTarget(url);
      if (!jsUrl) return;
      const current = location.pathname + location.search + location.hash;
      if (current === jsUrl) return;
      if (replace) history.replaceState({}, '', jsUrl);
      else history.pushState({}, '', jsUrl);
    },

    rewriteRelativeUrls(root, baseUrl) {
      const base = new URL(baseUrl, location.origin);
      root.querySelectorAll('a[href]').forEach(a => {
        const raw = a.getAttribute('href') || '';
        if (raw.startsWith('#') || raw.startsWith('mailto:') || raw.startsWith('tel:')) return;
        const u = new URL(raw, base);
        if (u.origin === location.origin) a.setAttribute('href', u.pathname + u.search + u.hash);
      });
      root.querySelectorAll('form').forEach(form => {
        const raw = form.getAttribute('action') || base.pathname + base.search;
        const u = new URL(raw, base);
        if (u.origin === location.origin) form.setAttribute('action', u.pathname + u.search + u.hash);
      });
      root.querySelectorAll('img[src],iframe[src],script[src]').forEach(el => {
        const attr = el.hasAttribute('src') ? 'src' : null;
        if (!attr) return;
        const raw = el.getAttribute(attr) || '';
        if (/^(data:|https?:|mailto:|tel:)/i.test(raw)) return;
        const u = new URL(raw, base);
        if (u.origin === location.origin) el.setAttribute(attr, u.pathname + u.search + u.hash);
      });
    },

    onClick(event) {
      if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
      const a = event.target.closest && event.target.closest('a[href]');
      if (!a || (a.target && a.target !== '_self')) return;
      const href = a.getAttribute('href') || '';
      if (href.startsWith('#')) return;
      const normal = this.normalUrlFromHref(a.href);
      if (!normal) return;
      event.preventDefault();
      this.navigateToNormalUrl(normal).catch(err => this.fatal(err));
    },

    onSubmit(event) {
      const form = event.target.closest && event.target.closest('form');
      if (!form || (form.target && form.target !== '_self')) return;

      const method = (form.getAttribute('method') || 'GET').toUpperCase();
      const action = form.getAttribute('action') || this.currentTargetUrl || this.targetUrlForRoute(this.routeFromLocation());
      const u = new URL(action, location.href);
      if (!sameOrigin(u.href)) return;

      event.preventDefault();

      if (method === 'GET') {
        const params = new URLSearchParams(this.formData(form, event.submitter || null));
        u.search = params.toString();
        const normal = this.normalUrlFromHref(u.href) || (u.pathname + u.search + u.hash);
        this.navigateToNormalUrl(normal).catch(err => this.fatal(err));
        return;
      }

      this.submitMutationForm(form, u, method, event.submitter || null)
        .catch(err => this.fatal(err));
    },

    formData(form, submitter) {
      try {
        return submitter ? new FormData(form, submitter) : new FormData(form);
      } catch (_) {
        const data = new FormData(form);
        if (submitter && submitter.name && !submitter.disabled) {
          data.append(submitter.name, submitter.value || '');
        }
        return data;
      }
    },

    async submitMutationForm(form, url, method, submitter) {
      const submitStarted = performance.now();
      this.status('Submitting …');
      const body = this.formData(form, submitter);
      const fetchStarted = performance.now();
      const res = await fetch(url.href, {
        method: method,
        credentials: 'same-origin',
        redirect: 'follow',
        headers: {
          'Accept': 'text/html',
          'X-AstrX-JS-Browser': '1'
        },
        body: body
      });
      this.profile.lastFetchResponseMs = Math.round(performance.now() - fetchStarted);
      this.profile.lastFetchStatus = res.status;
      this.profile.lastFetchServerTiming = res.headers.get('Server-Timing') || '';
      this.profile.lastFetchRenderMode = res.headers.get('X-AstrX-JS-Browser') || '';
      if (!res.ok) throw new Error('HTTP ' + res.status + ' while submitting ' + url.pathname);

      const textStarted = performance.now();
      const text = await res.text();
      this.profile.lastFetchReadMs = Math.round(performance.now() - textStarted);
      this.profile.lastFetchBytes = text.length;
      const finalUrl = res.url || url.href;
      this.syncHistoryToNormalUrl(finalUrl, false);
      this.applyHtmlDocument(text, finalUrl);
      this.profile.lastNavigationTotalMs = Math.round(performance.now() - submitStarted);
    },

    markActive(url) {
      const jsUrl = this.jsUrlForTarget(url) || '';
      document.querySelectorAll('#nav a, #user_nav a, #admin_nav a').forEach(a => {
        const normal = this.normalUrlFromHref(a.href);
        const active = normal && this.jsUrlForTarget(normal) === jsUrl;
        a.classList.toggle('active', !!active);
      });
    },

    status(text) {
      this.ensureRuntimeMarker();
      const el = byId('astrx-js-status');
      if (el) el.textContent = text || '';
    },

    performanceResources() {
      try {
        return performance.getEntriesByType('resource')
          .filter(entry => entry.name.indexOf(location.origin + BOOT.jsBase) === 0 || entry.name.indexOf(location.origin + BOOT.siteBase) === 0)
          .slice(-20)
          .map(entry => ({
            name: entry.name.replace(location.origin, ''),
            type: entry.initiatorType,
            duration: Math.round(entry.duration),
            transferSize: entry.transferSize || 0,
            encodedBodySize: entry.encodedBodySize || 0,
            decodedBodySize: entry.decodedBodySize || 0
          }));
      } catch (_) {
        return [];
      }
    },

    debugEnabled() {
      const params = new URLSearchParams(location.search);
      return params.get('debug') === '1' || localStorage.getItem('astrx.debug') === '1';
    },

    updateDebug(url) {
      this.ensureRuntimeMarker();
      const el = byId('astrx-js-debug');
      const btn = byId('astrx-js-debug-toggle');
      if (!el) return;
      const enabled = this.debugEnabled();
      if (btn) btn.hidden = !enabled;
      el.hidden = !enabled;
      if (!enabled) return;
      const pre = el.querySelector('pre');
      if (!pre) return;

      const route = this.routeFromLocation();
      const page = this.manifest && this.manifest.pages
        ? this.manifest.pages.find(p => p.slug === route) || null
        : null;

      pre.textContent = JSON.stringify({
        mode: byId('astrx-js-fragment') ? 'fragment-browse' : 'html-browse',
        route: route,
        target: url || this.currentTargetUrl,
        finalLocation: location.pathname + location.search + location.hash,
        jsBase: BOOT.jsBase,
        siteBase: BOOT.siteBase,
        apiIndex: BOOT.assets && BOOT.assets.api,
        resourceStats: this.resourceStats,
        profile: this.profile,
        resources: this.performanceResources(),
        apiIndexLoaded: !!this.apiIndex,
        page: page ? {
          slug: page.slug,
          file_name: page.file_name,
          api_enabled: !!page.api_enabled,
          api_url: page.api_url || null,
          api_data_url: page.api_data_url || null
        } : null,
        manifestPages: this.manifest && this.manifest.pages ? this.manifest.pages.length : 0,
        apiEnabledPages: this.manifest && this.manifest.pages ? this.manifest.pages.filter(p => p.api_enabled).length : 0,
        templateCount: this.templates ? Object.keys(this.templates).length : 0,
        lastUpdated: new Date().toISOString()
      }, null, 2);
    },

    fatal(err) {
      const message = err && err.message ? err.message : String(err);
      const target = byId('astrx-js-content') || byId('astrx-js-boot') || document.body;
      target.innerHTML = '<div class="diag-error"><strong>JS browser error:</strong> ' + esc(message) + '</div>';
      this.status('Error');
    }
  };

  window.AstrXJS = { Mustache: Mustache, App: App, boot: BOOT };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.start());
  } else {
    App.start();
  }
})();
JS;
    }

    private function emitManifestJson(): void
    {
        $pages = $this->manifestPages();
        $shell = $this->shellContext($pages);
        $payload = [
            'ok'           => true,
            'version'      => 2,
            'locale'       => $this->locale(),
            'siteName'     => $this->siteName(),
            'siteBase'     => $this->siteLocaleBasePath(),
            'jsBase'       => $this->jsBasePath(),
            'defaultRoute' => $this->defaultRouteSlug(),
            'pages'        => $pages,
            'api'          => [
                'index' => $this->jsBasePath() . '/' . self::ASSET_API,
                'base'  => $this->siteLocaleBasePath() . '/api',
            ],
            'shellContext' => $shell,
        ];
        $this->emitJson($payload, privateMaxAge: self::CACHE_MANIFEST_MAX_AGE, browserLabel: 'manifest');
    }

    private function emitTemplatesJson(): void
    {
        $payload = $this->buildTemplateCache();
        $this->emitJson($payload, privateMaxAge: self::CACHE_TEMPLATES_MAX_AGE, browserLabel: 'templates');
    }

    private function emitTemplatesJs(): void
    {
        $started = microtime(true);
        $payload = $this->buildTemplateCache();
        $payload['module'] = 'astrx.templates';
        $payload['format'] = 'js-template-bundle';
        $payload['compiledBy'] = 'AstrX\\Controller\\JsController';

        $body = "window.AstrXJSTemplateBundle=" . $this->json($payload) . ";\n"
            . "window.AstrXJSTemplates=window.AstrXJSTemplateBundle.templates||{};\n";
        $etag = '"astrx-js-templates-js-' . sha1($body) . '"';
        if ($this->etagMatches($etag)) {
            http_response_code(304);
            if (!headers_sent()) {
                $this->emitServerTiming('astrx_js_templates_js', $started);
            }
            exit;
        }

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/javascript; charset=utf-8');
            header('Cache-Control: private, max-age=' . self::CACHE_TEMPLATES_MAX_AGE . ', stale-while-revalidate=86400');
            header('ETag: ' . $etag);
            header('X-AstrX-JS-Browser: templates-js');
            $this->emitServerTiming('astrx_js_templates_js', $started);
        }
        echo $body;
    }

    private function emitApiIndexJson(): void
    {
        $payload = [
            'ok'          => true,
            'version'     => 1,
            'locale'      => $this->locale(),
            'apiBase'     => $this->siteLocaleBasePath() . '/api',
            'queryMode'   => $this->siteLocaleBasePath() . '?api=1',
            'description' => 'AstrX API endpoints are regular pages with page.api_enabled = 1. Values in data are filtered through ContextScope; rendered HTML is included unless ?html=0 is sent.',
            'endpoints'   => $this->apiManifestPages(),
        ];

        $this->emitJson($payload, privateMaxAge: self::CACHE_API_INDEX_MAX_AGE, browserLabel: 'api');
    }

    /** @param list<array<string,mixed>> $pages */
    private function shellContext(array $pages): array
    {
        $cssRaw = $this->themeService->activeStylesheetContent();
        $css = is_string($cssRaw) ? $this->minifyCss($cssRaw) : '';
        $now = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $time = is_float($now) ? round(microtime(true) - $now, 4) : 0;

        return [
            'lang'         => $this->locale(),
            'year'         => date('Y'),
            'title'        => $this->siteName() . ' — JS',
            'description'  => 'Client-side browser for ' . $this->siteName(),
            'keywords'     => '',
            'index'        => false,
            'follow'       => false,
            'include'      => '__astrx_js_content',
            'captcha'      => 'partials/captcha',
            'website_name' => $this->siteName(),
            'title_url'    => $this->jsBasePath() . '/' . $this->defaultRouteSlug(),
            'icon'         => $this->config->getConfigString('ContentManager', 'icon', '/favicon.ico'),
            'ip'           => (string) $this->request->ip(),
            'css'          => $css,
            'generated_in' => $this->translator->t('generated_in', fallback: 'Generated in:'),
            'go_top'       => $this->translator->t('go_top', fallback: 'Go top'),
            'navbar'       => $pages,
            'has_messages' => false,
            'messages'     => [],
            'got_results'  => false,
            'results'      => [],
            'user_logged_in' => false,
            'user_nav'       => [],
            'user_page_url'  => $this->jsBasePath() . '/' . $this->translator->t('WORDING_USER', fallback: 'user'),
            'user_nav_guest_label' => $this->translator->t('user.nav.guest', fallback: 'Login'),
            'user_nav_guest_highlight' => false,
            'is_admin'      => false,
            'admin_nav'     => [],
            'page_comments' => false,
            'comments_html' => '',
            'time'          => $time,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function manifestPages(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, url_id, i18n, file_name, template, controller, hidden, api_enabled, title
               FROM resolved_page
              WHERE hidden = 0
                AND template = 1
              ORDER BY id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        /** @var list<array<string,mixed>> $rows */
        $pages = [];
        foreach ($rows as $row) {
            $urlId = is_scalar($row['url_id'] ?? null) ? (string) $row['url_id'] : '';
            $fileName = is_scalar($row['file_name'] ?? null) ? (string) $row['file_name'] : '';
            if ($urlId === '' || $fileName === 'error') {
                continue;
            }
            $slug = (bool) ($row['i18n'] ?? false)
                ? $this->translator->t($urlId, fallback: $fileName)
                : $urlId;
            if ($slug === '' || $slug === 'js') {
                continue;
            }
            $titleFallback = is_scalar($row['title'] ?? null) ? (string) $row['title'] : $slug;
            $name = (bool) ($row['i18n'] ?? false)
                ? $this->translator->t($urlId . '.title', fallback: $titleFallback)
                : $titleFallback;
            if ($name === '') {
                $name = ucfirst(str_replace(['_', '-'], ' ', $slug));
            }
            $normalUrl = $this->urlGenerator->toPage($slug);
            $pages[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'url_id'    => $urlId,
                'file_name' => $fileName,
                'slug'      => $slug,
                'name'      => $name,
                'title'     => $name,
                'url'       => $this->jsBasePath() . '/' . rawurlencode($slug),
                'normal_url'=> $normalUrl,
                'api_enabled' => (bool) ($row['api_enabled'] ?? false),
                'api_url'   => $this->siteLocaleBasePath() . '/api/' . rawurlencode($slug),
                'api_data_url' => $this->siteLocaleBasePath() . '/api/' . rawurlencode($slug) . '?html=0',
                'highlight' => false,
            ];
        }
        return $pages;
    }

    /** @return list<array<string,mixed>> */
    private function apiManifestPages(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, url_id, i18n, file_name, template, controller, hidden, api_enabled, title
               FROM resolved_page
              WHERE hidden = 0
                AND api_enabled = 1
              ORDER BY id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        /** @var list<array<string,mixed>> $rows */

        $endpoints = [];
        foreach ($rows as $row) {
            $urlId = is_scalar($row['url_id'] ?? null) ? (string) $row['url_id'] : '';
            $fileName = is_scalar($row['file_name'] ?? null) ? (string) $row['file_name'] : '';
            if ($urlId === '' || $fileName === 'error' || $fileName === 'js') {
                continue;
            }

            $slug = (bool) ($row['i18n'] ?? false)
                ? $this->translator->t($urlId, fallback: $fileName)
                : $urlId;
            if ($slug === '' || $slug === 'js') {
                continue;
            }

            $titleFallback = is_scalar($row['title'] ?? null) ? (string) $row['title'] : $slug;
            $name = (bool) ($row['i18n'] ?? false)
                ? $this->translator->t($urlId . '.title', fallback: $titleFallback)
                : $titleFallback;
            if ($name === '') {
                $name = ucfirst(str_replace(['_', '-'], ' ', $slug));
            }

            $url = $this->siteLocaleBasePath() . '/api/' . rawurlencode($slug);
            $endpoints[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'url_id'    => $urlId,
                'file_name' => $fileName,
                'slug'      => $slug,
                'name'      => $name,
                'methods'   => ['GET'],
                'url'       => $url,
                'data_url'  => $url . '?html=0',
                'html_url'  => $url,
                'normal_url'=> $this->urlGenerator->toPage($slug),
                'note'      => 'Data keys depend on ContextScope tags in the controller. Add ?html=0 to omit rendered HTML.',
            ];
        }

        return $endpoints;
    }

    /** @return array<string,mixed> */
    private function buildTemplateCache(): array
    {
        $root = rtrim(templateDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $cacheRoot = rtrim(cacheDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'js';
        $cacheFile = $cacheRoot . DIRECTORY_SEPARATOR . 'templates.json';

        $sources = $this->templateSources($root);
        $fingerprint = sha1($this->json(array_map(
            static fn(array $s): array => [$s['name'], $s['mtime'], $s['size']],
            $sources
        )));

        if (is_file($cacheFile)) {
            $cachedRaw = file_get_contents($cacheFile);
            if (is_string($cachedRaw) && $cachedRaw !== '') {
                $cached = json_decode($cachedRaw, true);
                if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) {
                    /** @var array<string,mixed> $cached */
                    return $cached;
                }
            }
        }

        $templates = [];
        foreach ($sources as $source) {
            $body = file_get_contents((string) $source['path']);
            if (is_string($body)) {
                $templates[(string) $source['name']] = $body;
            }
        }

        $payload = [
            'ok'          => true,
            'version'     => 2,
            'fingerprint' => $fingerprint,
            'generatedAt' => gmdate('c'),
            'templates'   => $templates,
        ];

        if (!is_dir($cacheRoot)) {
            @mkdir($cacheRoot, 0755, true);
        }
        @file_put_contents($cacheFile, $this->json($payload), LOCK_EX);

        return $payload;
    }

    /**
     * @return list<array{name:string,path:string,mtime:int,size:int}>
     */
    private function templateSources(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!str_ends_with($path, '.html')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($root)));
            if (str_starts_with($rel, 'cache/')) {
                continue;
            }
            // Email templates are intentionally not needed in the browser cache.
            if (str_starts_with($rel, 'email/')) {
                continue;
            }
            $name = substr($rel, 0, -5);
            $out[] = [
                'name'  => $name,
                'path'  => $path,
                'mtime' => (int) $file->getMTime(),
                'size'  => (int) $file->getSize(),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    private function defaultRouteSlug(): string
    {
        $default = $this->config->getConfigString('Routing', 'default_page', 'WORDING_MAIN');
        return $this->translator->t($default, fallback: 'main');
    }

    private function siteName(): string
    {
        $name = $this->config->getConfigString('ContentManager', 'website_name', 'AstrX');
        if ($name === '') {
            $name = $this->config->getConfigString('EmailService', 'site_name', 'AstrX');
        }
        return $name;
    }

    /** @param array<string,mixed> $payload */
    private function emitJson(array $payload, int $privateMaxAge, string $browserLabel): void
    {
        $started = microtime(true);
        $body = $this->json($payload);
        $etag = '"astrx-js-' . $browserLabel . '-' . sha1($body) . '"';
        if ($this->etagMatches($etag)) {
            http_response_code(304);
            if (!headers_sent()) {
                $this->emitServerTiming('astrx_js_' . $browserLabel, $started);
            }
            exit;
        }
        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, max-age=' . $privateMaxAge . ', stale-while-revalidate=86400');
            header('ETag: ' . $etag);
            header('X-AstrX-JS-Browser: ' . $browserLabel);
            $this->emitServerTiming('astrx_js_' . $browserLabel, $started);
        }
        echo $body;
    }

    private function emitServerTiming(string $name, float $started): void
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?: 'astrx';
        $dur = max(0.0, (microtime(true) - $started) * 1000.0);
        header('Server-Timing: ' . $safe . ';dur=' . number_format($dur, 2, '.', ''), false);
        header('X-AstrX-Elapsed-Ms: ' . number_format($dur, 2, '.', ''));
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '{}';
    }

    private function etagMatches(string $etag): bool
    {
        $raw = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', $raw)) as $candidate) {
            if ($candidate === $etag || $candidate === 'W/' . $etag) {
                return true;
            }
        }
        return false;
    }

    private function html(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function minifyCss(string $cssRaw): string
    {
        $withoutComments = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $cssRaw) ?? $cssRaw;
        $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $withoutComments);
        $css = preg_replace('/\s{2,}/', ' ', $css) ?? $css;
        $css = str_replace([': ', '; ', ', ', ' {', '{ ', '} '], [':', ';', ',', '{', '{', '}'], $css);
        return trim($css);
    }
}
