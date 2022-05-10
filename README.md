# Astrx

## Features

## Installation

## Usage

## Documentation & Dev notes

### Injector (and Config)
`getConfigurationMethods` and configs structure (todo). 

### Getters and  Setters

Setters return a boolean: true if set was successful, false otherwise.  
Getters return the requested property or null.

### Classes error handling

Rather than throwing exceptions we store them into the array `$this->exceptions`
.  
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
error handling will be controlled and errors and exceptions will falls back to a
template: to the default one or, if things go extremely bad, to the
`failsafe.php` template.  
The project prelude:

```php
$ErrorHandler = new ErrorHandler();
$config = new Config();
```

Side note, from the PHP manual: "The following error types cannot be handled
with a user defined function: E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING,
E_COMPILE_ERROR, E_COMPILE_WARNING independent of where they were raised, and
most of E_STRICT raised in the file where set_error_handler() is called."

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
