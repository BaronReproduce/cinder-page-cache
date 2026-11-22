# Security notes

* no `eval`, no dynamic includes, no writes outside `wp-content`
* every admin action is nonce- and capability-checked
* the optional benchmark posts only after an explicit click, and the request body is shown first
* uninstall removes the options and tables the plugin created
