# Tests

`playground/` holds two must-use plugins for a throwaway WordPress (for example WordPress Playground):

* `acf-stub.php` – a minimal ACF stand-in (one flexible content group on pages, one options page). Do not load it next to real ACF.
* `csa-test.php` – mocks the Claude API through `pre_http_request` and drives the plugin through its REST routes.

Run: mount both into `wp-content/mu-plugins`, activate the plugin, then open `/?csa_test=run`. It deletes all posts first, so never run it on a real site.
