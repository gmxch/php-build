# PHP Custom Build (Zend Patch)

Custom PHP build with Zend customization.
Automatically outputs source when `eval()` is executed.
Works with any obfuscator.

## How to Build

### Clone
You can fork this repository. 
Remember to set the target architecture (ARM64 support via Docker for cross-compilation).

### Compatibility
Check each Zend version and set up your PHP environment accordingly. Especially for PHP 7.x, ensure all dependencies are compatible.

### Notes
- The build works with any obfuscator.
- For requests on different Zend versions, please open an issue in this repository.