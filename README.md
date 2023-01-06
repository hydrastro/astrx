# Astrx

## Features

## Installation

## Usage

## Documentation & Dev notes

### Code quality

Phpstan checks **only**. Level 9: `./vendor/bin/phpstan analyse src --level=9`
.  
Code formatting is left to be done by the IDE.  
Use strict types in every file.

Is separation of concerns more important than possible code duplication?

### Docker, Nginx, PHP, MySQL

This project does NOT aim to come along with deployment configurations.  
Dockerfiles and other configuration files are here ONLY for development
purposes.  
Therefore, DO NOT rely on those since they are NOT SAFE and will be deleted when
I get more confident with my development environments.  
There will be another repository though in which I will provide safe
configurations for every software needed to deploy this project.

### Docker

```shell
docker ps
docker exec -it **container id** /bin/sh

docker-compose up
docker-compose up -d
docker-compose build
```

### Database

```sql
CREATE
USER 'user'@'localhost' IDENTIFIED BY 'password';
CREATE
DATABASE 'content_manager';
GRANT ALL PRIVILEGES ON content_manager.* TO
'user'@'localhost';
FLUSH
PRIVILEGES;
```

Here are the golden rules for this project's database design:

1. Avoid having NULL attributes in tables as much as possible.
2. Avoid using weak entities / composed primary keys: add a new identifier
   attribute.
3. Tables should be normalized when possible. We do not fear joins.
4. Remember that ten fast queries are better than a slow query.
5. Tables must have a primary key and its attributes shouldn't be duplicated.
6. Verbosity is appreciated. This rule applies also to the code.
7. Properly done joins are better than multiple queries.
8. Views are appreciated when there are more than two joins.

### Architecture and Constraints

There are some constraints in this project.
The way the class are named and loaded, their configuration methods
The way templates are named, the way the pages are internationalized etc.
Here below will be listed all the "constraints" in a short list.
Each constraint is explained more in depth in other sections of this file.

### Injector (and Config)

`getConfigurationMethods` and configs structure (todo).  
Helper functions for the injector must take two parameters:

```php
function injectorHelper(object $class_instance, string $class_name) : bool
```

getConfig returns null on failure.
type checking is left to be done to the invoker.
example:

```php
$lang = $config->getConfig("Prelude", "language", "");
//if(!is_string($lang)) {
    // error handling?
//}

$some_stuff = $config->getConfig("Bar", "foo");
if(some_stuff === null) {
    // error handling?
}
```

~~If we are pretty confident that the config files are correct, we can skip
these checks.~~
Or can we??
Zero checks may be bad.. one check is fine. Even if it feels useless... yeah.
Also make sure you don't do checks twice.  
So **do at least one check**

### Getters and  Setters

Setters return a boolean: true if set was successful, false otherwise.  
Getters return the requested property or null.

### Classes error handling

~~Rather than throwing exceptions we store them into the
array `$this->exceptions`
.~~  
Exceptions are not thrown when possible.

Errors and messages are stored in another array along with an HTTP status code
and a level:

```php
$e = new Exception("Exception message");
$this->exceptions[] = $e;
$this->messages[] = array(
    MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
    MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
    MESSAGE_TEXT => $e->getMessage()
);
```

- **Why HTTP status codes in the first place?**  
  Because they have to come from somewhere, let's consider the API responses.  
  When the project will have an API, its structure will be similar to something
  like this:
    1. The HTTP request goes into the single entry point (`index.php`), and it's
       then passed to `bootstrap.php`.
    2. The request is routed by the content manager and dispatched to its
       controller.
    3. The controller processes the requests and calls the model accordingly.
    4. The model returns to the controller either the requested data or nothing.
       It also sets some internal result messages.
    5. The controller has the faculty to build (and maybe also send) the
       response.
    7.
        1. If it's a normal requests the controller just renders the template.
        2. If it's an API request the controller builds the JSON response. In
           either cases the controller has to retrieve the result messages and
           the possible exceptions from the model.  
           Since API requests are expected to be compliant with the HTTP status
           codes, the HTTP status codes have to come from somewhere. And they *
           must* come from the model (must because the logic is in the models)
           .  
           So, the best thing to do is to just couple result messages with an
           HTTP status code.
- **Which HTTP status code will be sent when there are multiple differing?**  
  Errors will have the priority.  
  The whole project won't ever face such cases by structure: if it ever happens
  it will be due third party modules.  
  The messages array `MESSAGE_HTTP_STATUS` (**maybe**) can be optional (and
  assumed `HTTP_OK` if not provided).
- **Why then having a messages array in the first instance?**  
  Because there ~~are~~ will be some cases where we have multiple messages to
  display. And in all those cases the status code is the same.
- **Why not throwing errors/exceptions?**  
  For multiple reasons:
  All thrown exceptions would have to be caught in a try-catch block because
  uncaught exceptions become fatal errors.
  The try catch blocks would have to be put around _every_ function call that
  that throw errors/exceptions, which is pretty insane. And once we catch them
  we would still probably log/store them silently, looking forward a future
  display.
  Downside of this approach: we may know errors at the end of the script
  execution (but we can always wrap the code into blocks that check for new
  errors), errors identification within the code may be hard since they lack
  of a global class name (exceptions are always `new Exception("message")`).
- **Why not making a dataclass for the messages, instead of using an array?**  
  Because it would be an additional abstraction layer, which will eventually
  result in more error handling (checking for valid error levels, checking if
  the HTTP status is correct, that the text is a string), also it couples code
  more tightly with the new hypothetical `ResultMessage` class.
- **Type checking has to be done somewhere tho..**  
  Yes, it will be done when the message locator/handler will retrieve/display
  them.
- **Why not wrapping HTTP_ constants into the result/response class and then,
  when storing an error, refer to those with `Response::HTTP_`?**  
  Because, as said above, it couples the code with the `Response` class.
- **Templating error/exception messages?**  
  It's too complex for core errors: it requires that the TemplateEngine isn't
  failing, which is something that isn't assured, also there's the exception
  call stack we can use for getting all the debugging info.
  Implying that templating in errors is only for debugging info... well I don't
  care.

### Core errors

The project assumes that it's core components (`Autoloader`, `Config`,
`ErrorHandler`) aren't faulty.  
It's essential for the project to be able to load `constants.php`, therefore
there will be *zero* tolerance for its loading failure (which may happen only in
case someone changes the project core).  
Once the prelude is complete, meaning that the main language file is loaded,
error handling will be controlled and errors and exceptions will falls back to
the default template.  
If things go __extremely__ bad, meaning that the prelude failed, `failsafe.php`
will be loaded as a template.  
If things go __insanely__ bad, a pretty error page, hardcoded into the error
handler
will still be printed.  
(Side note: these are just notes for myself, therefore I don't give a shit
wether
the terminology I'm using here is correct; I will correct myself before the
release).  
The project prelude core lines (`./src/classes/prelude.php:24`):

```php
$ErrorHandler = new ErrorHandler();
$config = new Config();
// Now we can relax. We have a custom error handler in english
```

Side note, from the PHP manual: "The following error types cannot be handled
with a user defined function: E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING,
E_COMPILE_ERROR, E_COMPILE_WARNING independent of where they were raised, and
most of E_STRICT raised in the file where set_error_handler() is called."

### Bootstrap

index.php: -> require bootstrap.php
bootstrap.php: require constants.php
require functions.php
require autoloader.php
Autoloader() -> spl_autoloader_register
Prelude(): -> prelude.php
prelude.php:   error_handler() -> ini_set, error_reporting, set_error_handler
etc.
config():
this.config = require config

*                      getConfig language ?? fail->return; getConfig:
*                               return config or null on fail. on fail sets this.messages.
*                       require lang

| error_handler -> addclass()
| // END OF CRITICAL SECTION
V .
DEFER THIS DECISION TO LATER, AFTER THE CMS LOADS AND WE GET THE USER-REQUESTED
LANGUAGE!

### Injector Helper Functions

### Void return

`return null;` is preferred over `return;`.

### Folders structure

```shell
$ tree -d
.
├── class
├── config
├── controller
├── lang
├── template
```

### Module structure

Class name: `class MyModule`  
Class file: `./class/my_module.php`  
Config file: `./config/my_module.config.php`  
Lang file: `./lang/my_module.` language code `.php`

### Template engine interface

```php
$TemplateEngine->render($template_name, $arguments);
```

Render can be reimplemented to suit your needs.  
The project supports both plain PHP rendering and a custom template engine
rendering.  
(NOTE) Be careful when enabling PHP rendering: the template evaulation eats up
the first line if it's empty. Take a look at the following example:
```php
echo eval('?>
stuff');
echo eval('?>not empty
stuff');
```

### Template engine syntax

```
{{variable}}
{{&unecaped_variable}}
{{#loop_start}}
{{^inverted_loop_start}}
{{/loop_end}}
{{!comment}}
{{=tag change=}}
{{>partial_load}}
{{*dereference}}
{{>*dynamic_partial}}
```

### Modules i18n

Modules can either use their own language definitions, or the core class ones.  
In no case they should use any other module's definitions.

## Contributing

## TODO List

- [X] Modularization
- [X] Module rewrite/check
- [X] I18n
- [X] POST-Redirect-GET
- [ ] API
- [X] Objects support in the template
- [ ] ZKP login method. ( challenge-text, challenge-input)
  ~~- [ ] Generate a password for me~~

# Hiatus resume README

- [X] Check for parameter validations redundancy e.g.: parent validates
  parameters and calls child who revalidates the same parameters
- [X] Define how to handle validation redundancy
- [ ] Check functions scope
- [~] Never use arrays, type everything (FUCK): first off all variables then the
  arrays. More class files, less type cheks (issets).
  ~~- [ ] ResultMessage class: instead of arrays. Not sure about constructor
  checks~~
  here. Check if class has properly been loaded.
- [X] Fix PHPDoc.
- [X] No composer? Ok. But at least let's have a proper dev. environment!

### TODO 2.

- [X] Check useless if statements and replace them with assertions
- [ ] Provide an ad-hoc super optimized code? Remove useless if clauses on
  internal checks. Maybe replace those if statements with an assertion and
  define
  assertion callback function
- [ ] Assertions handler
- [ ] Rewrite this file.

# Hiatus (again) resume README

# Read EVERYTHING before jumping to code

What's left to be done:
Adjusting the path for the controllers and the templates.
Page parents should be split in order to access child pages, but there's a
problem: internationalization.
So the paths would become /template/WORDING_USER/profile.html etc.

There's also left to be figured out how to share one controller between multiple
pages.
I guess we could just specify the controller name in the page table.

Is sharing controllers necessary?
Very likely.

Also: if a loaded page is HIDDEN, redirect to UNAUTHORIZED.

One last thing to check: proper folders permissions (TEMPLATE_CACHE_DIR).
this will probably be left to be done in the setup script.

$CONFIG->CLASSLANGHELPER: if lang not found TRY to load the default lang as a
fallback. Example case: a website is 90% translated, but those untranslated
pages SHOULD NOT BREAK. We fall back to the default lang and display a NOTICE
MESSAGE INSTEAD.
if(file_exists(fallback)) { $this->results[] = array( FALLBACK_LANG )

Some considerations: it will be okay to store controllers and templates is
subdirectories named with the i18n constant names (e.g. WORDING_USER).
We could also consider using the url_id field in place of the filename.
/page/WORDING_FOO/filename.php, /controller/WORDING_FOO/filenameController.php
References to file names can't be resolved dynamically but should rather be
static.
We could resolve it with the filenames though. Yeah this would make things
looking better.
So we'd have to retrieve "ancestorsFilenames".
Therefore yeah we have to choose between
/page/WORDING_FOO/WORDING_BAR.php
/page/WORDING_FOO/filename.php
/page/foo/filename.php

I'd say let's keep the filename filed and just retrieve it.
--

The setup script:
The user should read carefully all the configs and edit them.

Then we could "build" the application.
loading all the configs, var_exporting them and placing the dumped array
manually into the $Config class, maybe passing them into the constructor.
```php
clas Config {
    public function __construct(ErrorHandler $ErrorHandler, array $config);
}
```
The critical parts of building the application would be includes and requires
therefore those should be wrapped around if statements.
if(!class_exist(controller)) { require controller_file }
So in the `Config` class:
```php
$this->configuration = require(CONFIG_DIR . "config.php");
```
becomes
```php
$config_file = CONFIG_DIR . "config.php";
if(file_exists($config_file)) {
    $this->configuration = require($config_file);
}
```
Requires and includes which are within functions registered to spl_autoload_register
are okay since they're called only if a class doesn't exist.

Then we would just build all the existing themplates: we need to edit the
TemplateEngine class to allow loading pre-built templates (existing classes).
and maybe having a template signature (template class name) that doesn't rely
on reading the template file.

For extreme optimization:
writing all the classes default config into the classes themselves.
then disabling the class helper methods.
There are some methods though that are essential. Those should be put into
the class constructors.
E.g. PDO credentials and the mailserver credentials & config.

CHECK IF REMOVING $config["class_name"]["var_name"] makes the helper method
scream. IF SO, SHUT IT UP/notify silently (DEBUG).
```php
        $template_file = $this->getTemplateDir() .
                         $template . // <- here dots should be replaced with
                                     // slashes. For properly loading templates
                                     // in folders. OR MAYBE NOT?
                                     // if we allow slashes in template names
                                     // we should replace slashes with
                                     // underscores when genereating the class
                                     // name for the template.
                         $this->getTemplateExtension();
```

So the idea is:
building all the classes and loading them.
manually loading the config array into the config class.
two ways of dumping the config class array:
1. Full. Entire array is dumped.
2. Minimal. Only critical sections are dumped, note: the user MUST set other
configs directly into the classes. With this config the injector helper methods
can be turned off.
Helper methods: loadClassAndConfig, configurationMethodsHelper, addClass

Controllers are responsible for building responses.
Therefore controllers are responsible for buliding both HTML adn JSON
JSON responses are specific for the API.
So. Controllers call an init function. then they should set BOTH the template
arguments AND the general JSON response data.
So. when controllers call the models we should be sure that there's only ONE
response in the controller ?
Anyways yeah. You got the idea. Controllers should also set JSON data.
Multiple responses? Status code = max. Response = responses.

Another thing that would be cool to implement is: EMPTY template, load all the
page data through the API with javascript: so web crawlers can't index
ANYTHING (idea proudly stolen from @meh. .
/api/getPageData/ -> returns rendered {{*content}} as a string.

### (Re)Move `getConfigurationMethods`

To the configurations array.

### Merge template args of the ContentManager with the template handler args.

### IMPORTANT

Test the application in production environment: language constants should be
loaded in a different way.

There are some things that should NOT be asserted, for example: loading the
fallback language file!

```php
            assert(
                $this->config->setLang($fallback_lang),
                $language_catastrophe_message
            );
```
this is very bad.

### PDO CACHE

cache for pdo basic queries: page retrival
var_export to a config / cache file to build the cache

if cache and cache file exists
       return include cache fil

pdo select
if cache
   file put contents var export
return data

### Logger

log to a file messages that are not displayed?

### Async

ASYNC PDO CONNECTION?

# Future performance improvements

 - add config caching flag which enables memcached:
wherEVER there's a query we try to load it from the cache and store it if not present
check for typical usages
dumping data into php directly is insane and should not be done
- sessions should be all stored in memcached since they're supposed to be volatile
and since mysql is too slow and caching with mysql doesn't make sense here
if caching is not enabled we can fallback to pho
- template renders are too complex to be cached: caching would be appropriate for static pages
but ours are all dynamic

check if there's a cache psr  
caching can implemented in different ways, in almost every case it comes with pdo

async pdo makes no sense
check if there's a way to build a connection only when strictly needed


# check memory usage

and unset unused things!!

## Session Handler and sessions

absolutely adjust the cookie options!
session ddos shouldn't be a thing. if it is, the implementation is too slow!

## ini settings

some of them don't make sense.
doesn't make sense to set them every time.

they make things easier for development but should be set as default in php ini in production

# User login

Normal username/email - password login; no javascript.  
Leaving room for javascript implementations though: on the backend ( API? ) we check if
the user is loggin in with javascript. Javascript -> ZKP login method.

# Language constants

Maybe we should make a class for handling the language strings. And not store them in constants.

# Template fragmets caching

```
non cached content
{{!cache_start}}
cached content
{{!cache_end}}
non cached content
```
