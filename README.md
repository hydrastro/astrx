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
$this->messages = array(
    "level" => MESSAGE_LEVEL_ERROR,
    "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
    "message"=> $e->getMessage();
);
```
- Which HTTP status code will be sent when there are multiple differing?  

Errors will have the priority.  
The whole project won't ever face such  cases by structure: if it ever happens
it will be due third party modules.  
The messages array `http_status_code` can be optional.

## TODO List
- [ ] Modularization
- [ ] Module rewrite/check
- [ ] I18n
- [ ] POST-Redirect-GET
- [ ] API
