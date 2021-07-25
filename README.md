# Extension embedded-call

This package provides the ability to call a feature with adjusted data. 
It is convenient to let out the API with closing functions.

## Usage
```php
embedded_call(function (\Illuminate\Http\Request $request) {
    dd($request); // This is a request app instance
});
// Or
embedded_call(function (\Illuminate\Http\Request $request) {
    dd($request); // This is my request instance
}, ['request' => $my_request_instance]);
```
