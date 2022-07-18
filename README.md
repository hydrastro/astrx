# Astrx

## Features

## Installation

## Usage

## Documentation & Dev notes

### Code quality

Phpstan checks only. Level 9: `./vendor/bin/phpstan analyse src --level=9`.  
No psalm. No phpcs.  
Code formatting is done by the IDE.  
Use strict types in every file.

Separation of concerns is more important than possible code duplication?

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

```angular2html
├───class
├───config
├───controller
├───data
├───lang
├───page
└───template
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

- [ ] Modularization
- [ ] Module rewrite/check
- [ ] I18n
- [ ] POST-Redirect-GET
- [ ] API
- [ ] Objects support in the template
- [ ] ZKP login method. ( challenge-text, challenge-input)
- [ ] Generate a password for me

# Hiatus resume README

- [ ] Check for parameter validations redundancy e.g.: parent validates
  parameters and calls child who revalidates the same parameters
- [ ] Define how to handle validation redundancy
- [ ] Check functions scope
- [ ] Never use arrays, type everything (FUCK): first off all variables then the
  arrays. More class files, less type cheks (issets).
- [ ] ResultMessage class: instead of arrays. Not sure about constructor checks
  here. Check if class has properly been loaded.
- [ ] Fix PHPDoc.
- [ ] No composer? Ok. But at least let's have a proper dev. environment!
