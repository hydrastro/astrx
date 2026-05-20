# AstrX compiled build

AstrX can be built into a single PHP bundle for profiling and production experiments.

```bash
php tools/compile.php
```

This generates:

```text
build/astrx.compiled.php
public/compiled.php
```

`build/astrx.compiled.php` contains:

- every `src/**/*.php` source file as an embedded payload;
- a generated autoloader mapping `AstrX\...` symbols to embedded source;
- the non-config, non-cache text resources needed to reconstruct templates/lang/setup files;
- helpers for inspecting and extracting the embedded resource payload.

`public/compiled.php` is a front controller equivalent to `public/index.php`, except it boots from the compiled bundle instead of `src/bootstrap.php` and the PSR-4 filesystem autoloader.

## Why it is a bundle autoloader, not raw concatenation

Naive concatenation is fragile because PHP may need interfaces, traits, enums, attributes, and parent classes before a specific class body is evaluated. The compiled bundle keeps all source in one file, but evaluates each embedded file on demand through an autoloader.

That gives the useful production property:

```text
one PHP source file read by the front controller
```

without introducing declaration-order fatals.

## Running it

Build the bundle:

```bash
php tools/compile.php
```

Then point your web server to:

```text
public/compiled.php
```

instead of:

```text
public/index.php
```

For a quick manual test, temporarily change your Nginx `try_files`/FastCGI target from `index.php` to `compiled.php`, or request it directly if your server allows it.

## Resources

The compiler embeds read-only text resources from:

```text
resources/lang/
resources/template/
setup/
src/setup/
```

It intentionally does **not** embed:

```text
resources/config/
resources/template/cache/
resources/fonts/
uploaded files / mutable runtime state
```

Reasons:

- config contains environment-specific values and secrets;
- template cache is generated output;
- fonts and binary assets should stay external assets;
- uploaded/runtime state must remain mutable.

The normal runtime still reads templates, language files, config files, and theme files from disk. The embedded resources are available for inspection and extraction:

```php
$manifest = \AstrX\Compiled\Bundle::resourceManifest();
$css = \AstrX\Compiled\Bundle::resource('resources/template/style.css');
$count = \AstrX\Compiled\Bundle::extractResources(__DIR__ . '/..');
```

This is deliberate. It lets the PHP code be compiled first, while keeping resource virtualisation as a separate optimization step.

## Custom output paths

```bash
php tools/compile.php \
  --out=build/astrx.compiled.php \
  --front=public/compiled.php
```

## Profiling comparison

Compare normal and compiled front controllers:

```text
/en/main?XDEBUG_TRIGGER=1             normal public/index.php
/en/main?XDEBUG_TRIGGER=1             compiled public/compiled.php, after web server switch
```

Useful functions to compare in Cachegrind:

```text
spl_autoload_call
AstrX\Compiled\Bundle::autoload
AstrX\Compiled\Bundle::loadFile
AstrX\ContentManager::init
AstrX\Template\TemplateEngine::loadTemplate
AstrX\Navbar\NavbarHandler::*
PDOStatement::execute
```

If the bottleneck is class loading/stat calls, compiled mode should help. If the bottleneck is database work, table rendering, navbar generation, or template rendering, compiled mode will show similar total request times and the next optimization should target those functions instead.

## Safety model

Compiled mode is opt-in. Development mode stays unchanged:

```text
public/index.php → src/bootstrap.php → filesystem autoloader
```

Compiled mode is:

```text
public/compiled.php → build/astrx.compiled.php → AstrX\Compiled\Bundle::boot()
```

So you can keep both front controllers side by side and switch at the web server level.
