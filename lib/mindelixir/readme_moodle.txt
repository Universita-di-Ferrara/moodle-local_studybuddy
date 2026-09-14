Mind Elixir
----------

Mind Elixir core is bundled for the StudyBuddy concept map renderer.

Project: https://github.com/SSShooter/mind-elixir-core
Documentation: https://docs.mind-elixir.com
Version: 5.15.1
License: MIT
Copyright: 2019 DjZhou

Download
--------

The official npm package is available at:
https://registry.npmjs.org/mind-elixir/-/mind-elixir-5.15.1.tgz

The corresponding source tag is:
https://github.com/SSShooter/mind-elixir-core/tree/v5.15.1

The bundled files are the browser distribution required by StudyBuddy:

* MindElixir.iife.js
* MindElixir.css
* LICENSE

Build instructions
------------------

To reproduce the browser distribution from the source repository:

1. Check out the v5.15.1 tag.
2. Install the dependencies with `pnpm install`.
3. Run `pnpm build`.
4. Copy `dist/MindElixir.iife.js`, `dist/MindElixir.css` and `LICENSE` into
   this directory.

No changes have been made to the third-party source files.

The distribution must be replaced only with a reviewed release and the
version in `thirdpartylibs.xml` must be updated at the same time.
