# Astrx

## Features

## Installation

## Usage

## Documentation & Dev notes
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
    "level" => MESSAGE_LEVEL_ERROR,
    "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
    "message"=> $e->getMessage();
);
```
- **Which HTTP status code will be sent when there are multiple differing?**  
Errors will have the priority.  
The whole project won't ever face such  cases by structure: if it ever happens
it will be due third party modules.  
The messages array `http_status_code` can be optional.
- **Why then having a messages array in the first instance?**  
Because there ~~are~~ will be some cases where we have multiple messages to
display. And in all those cases the status code is the same.

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
$TemplateEngine->render($template_name);
```
Render can be reimplemented to suit your needs.  
The project supports both plain PHP rendering and a custom template engine
rendering.

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
```

## TODO List
- [ ] Modularization
- [ ] Module rewrite/check
- [ ] I18n
- [ ] POST-Redirect-GET
- [ ] API
