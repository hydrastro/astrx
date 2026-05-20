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
 *   /<locale>/js/templates.json raw template cache
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
            default              => $this->emitShell(),
        };

        exit;
    }

    private function locale(): string
    {
        $lang = $this->currentUrl->get('lang', $this->translator->getLocale());
        return is_scalar($lang) && (string) $lang !== '' ? (string) $lang : 'en';
    }

    private function jsBasePath(): string
    {
        $basePath = rtrim($this->config->getConfigString('Routing', 'base_path', '/'), '/');
        if ($basePath === '') {
            $basePath = '';
        }
        return $basePath . '/' . rawurlencode($this->locale()) . '/js';
    }

    private function siteLocaleBasePath(): string
    {
        $basePath = rtrim($this->config->getConfigString('Routing', 'base_path', '/'), '/');
        if ($basePath === '') {
            $basePath = '';
        }
        return $basePath . '/' . rawurlencode($this->locale());
    }

    private function currentRoutePath(): string
    {
        $tail = $this->currentUrl->tail();
        if ($tail === []) {
            return '';
        }
        $first = $tail[0] ?? '';
        if (in_array($first, [self::ASSET_RUNTIME, self::ASSET_MANIFEST, self::ASSET_TPLS], true)) {
            return '';
        }
        return implode('/', array_map('rawurlencode', $tail));
    }

    private function emitShell(): void
    {
        $locale = $this->html($this->locale());
        $siteName = $this->html($this->siteName());
        $runtime = $this->html($this->jsBasePath() . '/' . self::ASSET_RUNTIME);
        $route = $this->html($this->currentRoutePath());

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-AstrX-JS-Browser: shell');
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="{$locale}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{$siteName} — JS</title>
</head>
<body data-astrx-js-route="{$route}">
  <noscript>To use the /js/ browser you need JavaScript enabled. The normal site remains available without JavaScript.</noscript>
  <div id="astrx-js-boot">Loading…</div>
  <script defer src="{$runtime}"></script>
</body>
</html>
HTML;
    }

    private function emitRuntimeJs(): void
    {
        $boot = [
            'locale'       => $this->locale(),
            'jsBase'       => $this->jsBasePath(),
            'siteBase'     => $this->siteLocaleBasePath(),
            'siteName'     => $this->siteName(),
            'defaultRoute' => $this->defaultRouteSlug(),
            'assets'       => [
                'manifest'  => $this->jsBasePath() . '/' . self::ASSET_MANIFEST,
                'templates' => $this->jsBasePath() . '/' . self::ASSET_TPLS,
            ],
        ];
        $bootJson = $this->json($boot);
        $etag = '"astrx-js-runtime-' . sha1($bootJson . '|v4') . '"';

        if ($this->etagMatches($etag)) {
            http_response_code(304);
            exit;
        }

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/javascript; charset=utf-8');
            header('Cache-Control: private, max-age=300, must-revalidate');
            header('ETag: ' . $etag);
            header('X-AstrX-JS-Browser: runtime');
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

    function renderNodes(nodes, ctx, partials, parent, index) {
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
          out += render(partial, ctx, partials, parent, index);
        } else if (node.type === '#') {
          const value = resolve(node.value, ctx, parent, index);
          if (Array.isArray(value)) {
            value.forEach((item, i) => out += renderNodes(node.nodes, ctx, partials, value, i));
          } else if (truthy(value)) {
            out += renderNodes(node.nodes, ctx, partials, value && typeof value === 'object' ? value : parent, index);
          }
        } else if (node.type === '^') {
          const value = resolve(node.value, ctx, parent, index);
          if (!truthy(value)) out += renderNodes(node.nodes, ctx, partials, parent, index);
        }
      });
      return out;
    }

    function render(src, ctx, partials, parent, index) {
      return renderNodes(nest(tokenize(String(src || ''))), ctx || {}, partials || {}, parent || null, index || 0);
    }

    return { render: render };
  })();

  const App = {
    manifest: null,
    templates: null,
    currentTargetUrl: null,

    async start() {
      try {
        await this.loadResources();
        this.renderDocumentShell();
        this.bind();
        await this.openFromLocation({ replace: true });
      } catch (err) {
        this.fatal(err);
      }
    },

    async loadResources() {
      const [manifest, templatesPayload] = await Promise.all([
        this.getJson(BOOT.assets.manifest),
        this.getJson(BOOT.assets.templates)
      ]);
      this.manifest = manifest;
      this.templates = templatesPayload.templates || templatesPayload;
    },

    async getJson(url) {
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('Could not load ' + url + ' (HTTP ' + res.status + ')');
      return await res.json();
    },

    renderDocumentShell() {
      const templates = Object.assign({}, this.templates, {
        '__astrx_js_content': '<div id="astrx-js-status" aria-live="polite"></div><div id="astrx-js-content"></div>'
      });
      const layout = templates['default'];
      if (!layout) throw new Error('Template cache does not contain default.html');

      const html = Mustache.render(layout, this.manifest.shellContext || {}, templates);
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      this.installDocumentHead(parsed);
      document.title = parsed.title || ((BOOT.siteName || 'AstrX') + ' — JS');
      document.body.innerHTML = parsed.body ? parsed.body.innerHTML : html;

      const boot = byId('astrx-js-boot');
      if (boot) boot.remove();
      this.ensureRuntimeMarker();
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
    },

    installDocumentHead(doc) {
      if (!doc || !doc.head) return;

      document.head.querySelectorAll('[data-astrx-js-head]').forEach(node => node.remove());

      doc.head
        .querySelectorAll('style, link[rel~="stylesheet"], link[rel~="icon"], meta[name="description"], meta[name="keywords"], meta[name="robots"]')
        .forEach(node => {
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

    routeFromLocation() {
      const jsBase = new URL(BOOT.jsBase + '/', location.origin).pathname.replace(/\/+$/,'');
      let path = location.pathname;
      if (path.indexOf(jsBase) === 0) path = path.slice(jsBase.length);
      path = trimSlashes(decodeURIComponent(path));
      if (path === 'runtime.js' || path === 'manifest.json' || path === 'templates.json') path = '';
      return path || BOOT.defaultRoute || 'main';
    },

    targetUrlForRoute(route) {
      route = trimSlashes(route || BOOT.defaultRoute || 'main');
      return pathJoin(BOOT.siteBase, route || BOOT.defaultRoute || 'main') + location.search;
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
      return pathJoin(BOOT.jsBase, route) + u.search + u.hash;
    },

    normalUrlFromHref(href) {
      if (!sameOrigin(href)) return null;
      const u = new URL(href, location.href);
      const jsBase = new URL(BOOT.jsBase + '/', location.origin).pathname.replace(/\/+$/,'');
      const siteBase = new URL(BOOT.siteBase + '/', location.origin).pathname.replace(/\/+$/,'');

      if (u.pathname === jsBase || u.pathname.indexOf(jsBase + '/') === 0) {
        let route = trimSlashes(u.pathname.slice(jsBase.length));
        if (!route) route = BOOT.defaultRoute || 'main';
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
      this.currentTargetUrl = url;
      this.status('Loading ' + url + ' …');
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: {
          'Accept': 'text/html',
          'X-AstrX-JS-Browser': '1'
        }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status + ' while loading ' + url);
      const text = await res.text();
      const finalUrl = res.url || url;

      this.syncHistoryToNormalUrl(finalUrl, true);
      this.applyHtmlDocument(text, finalUrl);
    },

    applyHtmlDocument(text, url) {
      const doc = new DOMParser().parseFromString(text, 'text/html');

      this.installDocumentHead(doc);
      if (doc.title) document.title = doc.title + ' — JS';
      if (doc.body) this.rewriteRelativeUrls(doc.body, url);
      this.installDocumentChrome(doc);
      this.installMainContent(doc, text);
      this.ensureRuntimeMarker();
      this.markActive(url);
      this.status('');
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
      this.status('Submitting …');
      const body = this.formData(form, submitter);
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
      if (!res.ok) throw new Error('HTTP ' + res.status + ' while submitting ' + url.pathname);

      const text = await res.text();
      const finalUrl = res.url || url.href;
      this.syncHistoryToNormalUrl(finalUrl, false);
      this.applyHtmlDocument(text, finalUrl);
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
      const el = byId('astrx-js-status');
      if (el) el.textContent = text || '';
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
            'shellContext' => $shell,
        ];
        $this->emitJson($payload, privateMaxAge: 30, browserLabel: 'manifest');
    }

    private function emitTemplatesJson(): void
    {
        $payload = $this->buildTemplateCache();
        $this->emitJson($payload, privateMaxAge: 300, browserLabel: 'templates');
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
            "SELECT id, url_id, i18n, file_name, template, controller, hidden, title
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
                'highlight' => false,
            ];
        }
        return $pages;
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
            'version'     => 1,
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
        $body = $this->json($payload);
        $etag = '"astrx-js-' . $browserLabel . '-' . sha1($body) . '"';
        if ($this->etagMatches($etag)) {
            http_response_code(304);
            exit;
        }
        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, max-age=' . $privateMaxAge . ', must-revalidate');
            header('ETag: ' . $etag);
            header('X-AstrX-JS-Browser: ' . $browserLabel);
        }
        echo $body;
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
